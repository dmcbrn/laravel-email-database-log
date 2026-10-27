<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\EmailLogEvent;
use Dmcbrn\LaravelEmailDatabaseLog\Jobs\DeleteAttachmentFolders;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailsDeleted;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

/**
 * How many emails a page shows, and deleting old emails: in batches, with the attachment folders
 * deleted by a job.
 */
class OldEmailsAndPagingTest extends TestCase
{
    public function test_per_page_must_be_a_whole_number_from_1_to_100()
    {
        foreach (['-1', '0', '101', '500', '2.5', 'abc'] as $perPage) {
            $this->getJson(route('api.email-log') . '?per_page=' . $perPage)
                ->assertStatus(422)
                ->assertJsonValidationErrors('per_page');
        }

        foreach (['1', '100'] as $perPage) {
            $this->getJson(route('api.email-log') . '?per_page=' . $perPage)->assertOk();
        }
    }

    public function test_per_page_limits_the_number_of_emails()
    {
        $this->sendEmail('First');
        $this->sendEmail('Second');
        $this->sendEmail('Third');

        $this->getJson(route('api.email-log') . '?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_list_page_shows_a_per_page_error()
    {
        // The page has no field for it, so the error is shown below the filters.
        $this->from(route('email-log'))
            ->followingRedirects()
            ->get(route('email-log') . '?per_page=500')
            ->assertOk()
            ->assertSee('per page');
    }

    public function test_many_old_emails_are_deleted_in_batches_without_loading_them_in_full()
    {
        $this->createOldEmails(1200, true);
        $this->sendEmail('New email');

        DB::enableQueryLog();
        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])
            ->assertOk()
            ->assertJsonPath('message', 'Deleted 1200 emails logged on and before Fri, 01 Jan 2021');

        $this->assertSame(1, EmailLog::count());
        $this->assertSame(0, EmailLogEvent::where('messageId', 'like', 'old%')->count());

        $queries = collect(DB::getQueryLog())->pluck('query');
        // Three batches (500, 500 and 200 emails), each deleted with one query.
        $this->assertSame(3, $queries->filter(function ($query) {
            return preg_match('/^delete from [`"]?email_log[`"]? /i', $query) === 1;
        })->count());
        // No query reads the emails in full, with their bodies.
        $this->assertSame(0, $queries->filter(function ($query) {
            return preg_match('/^select \* from [`"]?email_log[`"]?( |$)/i', $query) === 1;
        })->count());
    }

    public function test_deleting_old_emails_fires_one_emails_deleted_event_per_batch()
    {
        Event::fake([EmailsDeleted::class]);
        $this->createOldEmails(1200);

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        Event::assertDispatchedTimes(EmailsDeleted::class, 3);
        $ids = [];
        $messageIds = [];
        Event::assertDispatched(EmailsDeleted::class, function ($event) use (&$ids, &$messageIds) {
            $ids = array_merge($ids, $event->ids);
            $messageIds = array_merge($messageIds, $event->messageIds);

            return true;
        });
        $this->assertCount(1200, array_unique($ids));
        $this->assertContains('old1', $messageIds);
        $this->assertContains('old1200', $messageIds);
    }

    public function test_deleting_old_emails_does_not_fire_model_events()
    {
        $deleting = 0;
        EmailLog::deleting(function () use (&$deleting) {
            $deleting++;
        });
        $this->createOldEmails(3);

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        // Deleted with one query per batch; listen to EmailsDeleted instead.
        $this->assertSame(0, EmailLog::count());
        $this->assertSame(0, $deleting);
    }

    public function test_attachment_folders_are_deleted_immediately_by_default()
    {
        Queue::fake();
        $log = $this->sendOldEmailWithAttachment();

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        Queue::assertPushed(DeleteAttachmentFolders::class, function ($job) use ($log) {
            return $job->connection === 'sync' && $job->messageIds === [$log->messageId];
        });
    }

    public function test_attachment_folders_can_be_deleted_on_a_queue()
    {
        Queue::fake();
        config(['email_log.queue_connection' => 'redis', 'email_log.queue' => 'email-log']);
        $this->sendOldEmailWithAttachment();

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        Queue::assertPushed(DeleteAttachmentFolders::class, function ($job) {
            return $job->connection === 'redis' && $job->queue === 'email-log';
        });
    }

    public function test_emails_without_attachments_need_no_folder_deletion()
    {
        Queue::fake();
        $this->createOldEmails(3);

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_a_folder_that_cannot_be_deleted_is_reported_and_the_others_are_still_deleted()
    {
        // Records what is reported, instead of reporting it.
        $handler = $this->spy(ExceptionHandler::class);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('deleteDirectory')->with('first')->once()->andReturn(true);
        $disk->shouldReceive('deleteDirectory')->with('refused')->once()->andReturn(false);
        $disk->shouldReceive('deleteDirectory')->with('failing')->once()->andThrow(new RuntimeException('Storage unreachable'));
        $disk->shouldReceive('deleteDirectory')->with('last')->once()->andReturn(true);
        Storage::set('email_log_attachments', $disk);

        (new DeleteAttachmentFolders(['first', 'refused', 'failing', 'last']))->handle();

        $handler->shouldHaveReceived('report')->twice()->with(Mockery::type(RuntimeException::class));
    }

    public function test_attachment_folders_with_unsafe_names_are_skipped_and_logged()
    {
        Log::spy();
        Storage::disk('email_log_attachments')->put('unrelated.txt', 'another file on the same disk');
        foreach (['x/..', '..', 'a\\b'] as $messageId) {
            EmailLog::create([
                'date' => '2020-01-01 10:00:00',
                'subject' => 'Odd message ID',
                'body' => 'Old body',
                'messageId' => $messageId,
                'attachments' => $messageId . '/file.txt',
            ]);
        }

        $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01'])->assertOk();

        // The emails are deleted, their folders are not: each of these names would point outside
        // the email's own folder ("x/.." and ".." even at the disk's root).
        $this->assertSame(0, EmailLog::count());
        Storage::disk('email_log_attachments')->assertExists('unrelated.txt');
        Log::shouldHaveReceived('warning')->times(3);
    }

    private function sendOldEmailWithAttachment()
    {
        $log = $this->sendEmail('Old email', 'jane@example.com', ['old.txt' => 'old file']);
        EmailLog::where('id', $log->id)->update(['date' => '2020-01-01 10:00:00']);

        return $log;
    }

    private function createOldEmails($count, $withEvents = false)
    {
        // Inserted directly, a hundred rows at a time: sending 1200 emails would make the test slow.
        foreach (array_chunk(range(1, $count), 100) as $numbers) {
            EmailLog::insert(array_map(function ($i) {
                return ['date' => '2020-01-01 10:00:00', 'subject' => "Old email {$i}", 'body' => 'Old body', 'messageId' => "old{$i}"];
            }, $numbers));

            if ($withEvents) {
                EmailLogEvent::insert(array_map(function ($i) {
                    return ['messageId' => "old{$i}", 'event' => 'delivered', 'data' => '{}', 'created_at' => '2020-01-01 10:00:00', 'updated_at' => '2020-01-01 10:00:00'];
                }, $numbers));
            }
        }
    }
}
