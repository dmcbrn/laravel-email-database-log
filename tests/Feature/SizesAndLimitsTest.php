<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Emails of any size are sent; the log keeps them within its size limits.
 */
class SizesAndLimitsTest extends TestCase
{
    public function test_an_error_while_logging_does_not_stop_the_email()
    {
        Exceptions::fake();
        // A disk that does not exist makes storing the attachment fail.
        config(['email_log.disk' => 'missing-disk']);

        $this->sendEmail('Still sent', 'jane@example.com', ['file.txt' => 'contents']);

        // The email went out, nothing was logged, and the error was reported (e.g. to Sentry).
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, EmailLog::count());
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_a_large_html_body_is_logged_in_full()
    {
        // 200 KB: more than a MySQL TEXT column holds (64 KB), less than the default limit (1 MB).
        $body = '<p>' . str_repeat('a', 200 * 1024) . '</p>';
        Mail::html($body, function ($message) {
            $message->from('noreply@example.com')->to('jane@example.com')->subject('Large body');
        });

        $this->assertSame($body, EmailLog::first()->body);
    }

    public function test_many_recipients_and_a_long_subject_are_logged_in_full()
    {
        // About 1 KB of addresses in each field, and a 300 character subject: more than 255.
        $recipients = array_map(fn ($i) => "recipient{$i}@example.com", range(1, 40));
        $subject = str_repeat('x', 300);
        Mail::raw('Hello', function ($message) use ($recipients, $subject) {
            $message->from('noreply@example.com')
                ->to($recipients)->cc($recipients)->bcc($recipients)
                ->subject($subject);
        });

        $log = EmailLog::first();
        $this->assertSame(implode(', ', $recipients), $log->to);
        $this->assertSame(implode(', ', $recipients), $log->cc);
        $this->assertSame(implode(', ', $recipients), $log->bcc);
        $this->assertSame($subject, $log->subject);
    }

    public function test_a_body_over_the_limit_is_truncated()
    {
        config(['email_log.max_body_size' => 1]); // KB
        $this->sendRaw(str_repeat('a', 3000));

        $body = EmailLog::first()->body;
        $this->assertSame(1024, strlen($body));
        $this->assertStringStartsWith(str_repeat('a', 1000), $body);
        $this->assertStringEndsWith('…[truncated]', $body);
    }

    public function test_headers_over_the_limit_are_truncated()
    {
        config(['email_log.max_body_size' => 1]); // KB, the limit for the body and the headers
        $recipients = array_map(fn ($i) => "recipient{$i}@example.com", range(1, 100));
        Mail::raw('Hello', function ($message) use ($recipients) {
            $message->from('noreply@example.com')->to($recipients)->subject('Many recipients');
        });

        $headers = EmailLog::first()->headers;
        $this->assertSame(1024, strlen($headers));
        $this->assertStringEndsWith('…[truncated]', $headers);
    }

    public function test_truncation_does_not_cut_a_character_in_half()
    {
        config(['email_log.max_body_size' => 1]); // KB
        $this->sendRaw(str_repeat('€', 1000)); // 3 bytes each

        $body = EmailLog::first()->body;
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
        $this->assertLessThanOrEqual(1024, strlen($body));
        $this->assertStringEndsWith('…[truncated]', $body);
    }

    public function test_addresses_and_the_subject_are_truncated_to_64_kb()
    {
        // About 80 KB of addresses, and an 80 KB subject: more than a MySQL TEXT column holds.
        $recipients = array_map(fn ($i) => "recipient{$i}@example.com", range(1, 3000));
        Mail::raw('Hello', function ($message) use ($recipients) {
            $message->from('noreply@example.com')->to($recipients)->subject(str_repeat('x', 80 * 1024));
        });

        $log = EmailLog::first();
        $this->assertSame(65535, strlen($log->to));
        $this->assertStringEndsWith('…[truncated]', $log->to);
        $this->assertSame(65535, strlen($log->subject));
        $this->assertStringEndsWith('…[truncated]', $log->subject);
    }

    public function test_an_attachment_over_the_limit_is_not_stored()
    {
        config(['email_log.max_attachment_size' => 1]); // KB
        $log = $this->sendEmail('Attachments', 'jane@example.com', [
            'big.txt' => str_repeat('a', 3 * 1024),
            'small.txt' => 'small contents',
        ]);

        $this->assertSame($log->messageId . '/small.txt', $log->attachments);
        Storage::disk('email_log_attachments')->assertMissing($log->messageId . '/big.txt');
        // Sizes in bytes; the limit as it was when the email was sent.
        $this->assertSame([['name' => 'big.txt', 'size' => 3072, 'limit' => 1024]], $log->skipped_attachments);
    }

    public function test_the_email_page_explains_why_an_attachment_was_not_stored()
    {
        config(['email_log.max_attachment_size' => 1]); // KB
        $log = $this->sendEmail('Attachments', 'jane@example.com', ['big.txt' => str_repeat('a', 3 * 1024)]);

        $this->get(route('email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertSee('big.txt was not stored: when this email was sent, the maximum size for logged attachments was 1 KB, and this file was 3 KB.');
    }

    public function test_the_api_lists_the_attachments_that_were_not_stored()
    {
        config(['email_log.max_attachment_size' => 1]); // KB
        $log = $this->sendEmail('Attachments', 'jane@example.com', ['big.txt' => str_repeat('a', 3 * 1024)]);

        $this->getJson(route('api.email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertJsonPath('email.skipped_attachments.0.name', 'big.txt')
            ->assertJsonPath('email.skipped_attachments.0.size', 3072)
            ->assertJsonPath('email.skipped_attachments.0.limit', 1024)
            ->assertJsonPath('email.skipped_attachments.0.message', 'big.txt was not stored: when this email was sent, the maximum size for logged attachments was 1 KB, and this file was 3 KB.');
    }

    public function test_the_attachment_limit_can_be_turned_off()
    {
        config(['email_log.max_attachment_size' => 0]);
        $log = $this->sendEmail('Attachments', 'jane@example.com', ['big.txt' => str_repeat('a', 3 * 1024)]);

        Storage::disk('email_log_attachments')->assertExists($log->messageId . '/big.txt');
        $this->assertNull($log->skipped_attachments);
    }

    public function test_the_default_limits()
    {
        $this->assertSame(1024, config('email_log.max_body_size'));
        $this->assertSame(10240, config('email_log.max_attachment_size'));
    }

    public function test_the_migration_adds_the_skipped_attachments_column()
    {
        $this->assertTrue(Schema::hasColumn('email_log', 'skipped_attachments'));
    }

    public function test_emails_are_still_logged_when_the_skipped_attachments_migration_has_not_run()
    {
        $this->skipOnMySql();

        // As in an app that installed the new version without running "php artisan migrate".
        Schema::table('email_log', function ($table) {
            $table->dropColumn('skipped_attachments');
        });
        config(['email_log.max_attachment_size' => 1]); // KB

        $log = $this->sendEmail('Attachments', 'jane@example.com', ['big.txt' => str_repeat('a', 3 * 1024)]);

        $this->assertNotNull($log);
        Storage::disk('email_log_attachments')->assertMissing($log->messageId . '/big.txt');
    }

    private function sendRaw($body)
    {
        Mail::raw($body, function ($message) {
            $message->from('noreply@example.com')->to('jane@example.com')->subject('Test');
        });
    }
}
