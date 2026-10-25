<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLogEvent;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * The web pages and the JSON API that show the logged emails.
 */
class EmailLogPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // If a page crashes, show the real exception instead of just "got 500".
        $this->withoutExceptionHandling();
    }

    public function test_the_list_page_shows_logged_emails()
    {
        $this->sendEmail('Welcome aboard', 'jane@example.com');

        $this->get(route('email-log'))
            ->assertOk()
            ->assertSee('Welcome aboard')
            ->assertSee('jane@example.com');
    }

    public function test_the_list_page_can_be_filtered_by_recipient()
    {
        $this->sendEmail('Subject for Jane', 'jane@example.com');
        $this->sendEmail('Subject for Bob', 'bob@example.com');

        $this->get(route('email-log', ['filterEmail' => 'jane@']))
            ->assertOk()
            ->assertSee('Subject for Jane')
            ->assertDontSee('Subject for Bob');
    }

    public function test_the_list_page_can_be_filtered_by_subject()
    {
        $this->sendEmail('Invoice 42', 'jane@example.com');
        $this->sendEmail('Password reset', 'jane@example.com');

        $this->get(route('email-log', ['filterSubject' => 'Invoice']))
            ->assertOk()
            ->assertSee('Invoice 42')
            ->assertDontSee('Password reset');
    }

    public function test_the_list_page_is_paginated()
    {
        $this->sendEmail('Oldest email');
        $this->sendEmail('Middle email');
        $this->sendEmail('Newest email');

        // Two per page: page 1 has the two newest, and page links are rendered.
        $this->get(route('email-log', ['per_page' => 2]))
            ->assertOk()
            ->assertSee('Newest email')
            ->assertSee('Middle email')
            ->assertDontSee('Oldest email');

        $this->get(route('email-log', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertSee('Oldest email')
            ->assertDontSee('Newest email');
    }

    public function test_filters_are_kept_when_paging()
    {
        $this->sendEmail('Invoice 1');
        $this->sendEmail('Invoice 2');
        $this->sendEmail('Invoice 3');
        $this->sendEmail('Newsletter');

        // The page links on the list page keep the filter and the page size...
        $this->get(route('email-log', ['filterSubject' => 'Invoice', 'per_page' => 2]))
            ->assertSee('filterSubject=Invoice')
            ->assertSee('per_page=2');

        // ...and so does the API, so its page 2 holds the last invoice, not the newsletter.
        $nextPage = $this->getJson(route('api.email-log', ['filterSubject' => 'Invoice', 'per_page' => 2]))
            ->json('next_page_url');

        $this->getJson($nextPage)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Invoice 1');
    }

    public function test_the_show_page_displays_one_email_with_its_attachment()
    {
        $log = $this->sendEmail('Show me', 'jane@example.com', ['hello.txt' => 'file contents']);

        $this->get(route('email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertSee('Show me')
            ->assertSee('Hello there')
            ->assertSee('hello.txt');
    }

    public function test_the_email_body_is_shown_in_a_sandboxed_frame()
    {
        Mail::html('<p>Hi</p><script>alert("logged")</script>', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Script in the body');
        });
        $log = EmailLog::first();

        $response = $this->get(route('email-log.show', ['id' => $log->id]))->assertOk();

        // The body's HTML must not end up in the page itself, where its scripts would run...
        $response->assertDontSee('<script>alert', false);

        // ...but, escaped, inside an iframe that is sandboxed without "allow-scripts" and "allow-same-origin".
        $response->assertSee(
            '<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="&lt;p&gt;Hi&lt;/p&gt;&lt;script&gt;alert(&quot;logged&quot;)&lt;/script&gt;"',
            false
        );
    }

    public function test_the_email_body_is_shown_with_its_inline_images()
    {
        // A real 1x1 pixel PNG, embedded by a mail view (tests/views/inline-image.blade.php).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        View::addLocation(__DIR__ . '/../views');

        Mail::send('inline-image', ['png' => $png], function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Inline image');
        });
        $log = EmailLog::first();

        $response = $this->get(route('email-log.show', ['id' => $log->id]))->assertOk();

        // The cid: reference is replaced by the image itself (escaped, like everything in the frame's srcdoc).
        $response->assertSee('&lt;img src=&quot;data:image/png;base64,' . base64_encode($png) . '&quot;&gt;', false);
        $response->assertDontSee('cid:', false);
    }

    public function test_a_plain_text_body_is_shown_as_text_with_its_line_breaks()
    {
        Mail::raw("Line one\nLine two <name>", function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Plain text');
        });
        $log = EmailLog::first();

        $response = $this->get(route('email-log.show', ['id' => $log->id]))->assertOk();

        // Escaped twice: once as text in the frame's document, once more for the srcdoc attribute.
        $response->assertSee(
            'srcdoc="&lt;pre style=&quot;white-space: pre-wrap; word-wrap: break-word; margin: 0;&quot;&gt;Line one' . "\n"
            . 'Line two &amp;lt;name&amp;gt;&lt;/pre&gt;"',
            false
        );
    }

    public function test_an_attachment_can_be_downloaded()
    {
        $log = $this->sendEmail('Download me', 'jane@example.com', ['hello.txt' => 'file contents']);

        $response = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]));

        $response->assertOk();
        $this->assertSame('file contents', $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_unknown_emails_and_attachments_give_404()
    {
        // A 404 is a response Laravel makes from an exception, so turn exception handling back on.
        $this->withExceptionHandling();

        $withFile = $this->sendEmail('With file', 'jane@example.com', ['hello.txt' => 'file contents']);
        $withoutFile = $this->sendEmail('Without file');

        $this->get(route('email-log.show', ['id' => 999]))->assertNotFound();
        $this->getJson(route('api.email-log.show', ['id' => 999]))->assertNotFound();
        $this->get(route('email-log.fetch-attachment', ['id' => 999, 'attachment' => 0]))->assertNotFound();
        $this->get(route('email-log.fetch-attachment', ['id' => $withFile->id, 'attachment' => 5]))->assertNotFound();
        $this->get(route('email-log.fetch-attachment', ['id' => $withoutFile->id, 'attachment' => 0]))->assertNotFound();
        $this->get('/email-log/not-a-number')->assertNotFound();
    }

    public function test_an_attachment_whose_file_was_deleted_gives_404()
    {
        $this->withExceptionHandling();

        $log = $this->sendEmail('With file', 'jane@example.com', ['hello.txt' => 'file contents']);
        Storage::disk('email_log_attachments')->delete($log->messageId . '/hello.txt');

        $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]))->assertNotFound();
    }

    public function test_an_attachment_with_a_comma_in_its_name_can_be_downloaded()
    {
        $log = $this->sendEmail('Comma in a name', 'jane@example.com', [
            'Invoice, March.pdf' => 'invoice contents',
            'notes.txt' => 'notes contents',
        ]);

        // Attachment paths are stored as one text, separated by ", ", so a name can't contain ", ".
        $this->assertSame($log->messageId . '/Invoice,March.pdf, ' . $log->messageId . '/notes.txt', $log->fresh()->attachments);

        $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]))
            ->assertOk()
            ->assertSee('invoice contents', false);
        $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 1]))
            ->assertOk()
            ->assertSee('notes contents', false);
    }

    public function test_attachments_that_could_run_scripts_are_downloaded_not_opened()
    {
        // HTML and SVG files can contain scripts, which would run on this site if opened in the browser.
        Mail::raw('Hello there', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Risky files')
                ->attachData('<html><body><script>alert(1)</script></body></html>', 'page.html', ['mime' => 'text/html'])
                ->attachData('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'drawing.svg', ['mime' => 'image/svg+xml']);
        });
        $log = EmailLog::first();

        foreach (['page.html', 'drawing.svg'] as $index => $name) {
            $response = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => $index]));

            $response->assertOk();
            $this->assertSame('attachment; filename=' . $name, $response->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_images_are_shown_in_the_browser()
    {
        // A real 1x1 pixel PNG: the file type is detected from the content, not from the name.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        $log = $this->sendEmail('Image', 'jane@example.com', ['logo.png' => $png]);

        $response = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=logo.png', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_attachment_names_with_special_characters_can_be_downloaded()
    {
        $log = $this->sendEmail('Names', 'jane@example.com', ['račun.pdf' => 'one', '100% done.txt' => 'two']);

        $first = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]));
        $this->assertSame("attachment; filename=racun.pdf; filename*=utf-8''ra%C4%8Dun.pdf", $first->headers->get('Content-Disposition'));

        $second = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 1]));
        $this->assertSame("attachment; filename=\"100 done.txt\"; filename*=utf-8''100%25%20done.txt", $second->headers->get('Content-Disposition'));
    }

    public function test_attachment_names_with_plus_signs_or_percent_codes_can_be_downloaded()
    {
        // Stored paths are plain names, never URL-encoded, so "+" and "%20" must not be decoded.
        $log = $this->sendEmail('Names', 'jane@example.com', ['C++ guide.pdf' => 'plus', '50%20off.txt' => 'percent']);

        $first = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]));
        $first->assertOk();
        $this->assertSame('plus', $first->getContent());

        $second = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 1]));
        $second->assertOk();
        $this->assertSame('percent', $second->getContent());
    }

    public function test_the_api_lists_logged_emails()
    {
        $this->sendEmail('First', 'jane@example.com');
        $this->sendEmail('Second', 'bob@example.com');

        $this->getJson(route('api.email-log'))
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_the_api_shows_one_email()
    {
        $log = $this->sendEmail('Api subject', 'jane@example.com');

        $this->getJson(route('api.email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertJsonPath('email.subject', 'Api subject')
            ->assertJsonPath('email.messageId', $log->messageId);
    }

    public function test_the_api_returns_dates_in_iso_8601_format()
    {
        $log = $this->sendEmail('Dated email');
        EmailLog::where('id', $log->id)->update(['date' => '2021-01-02 03:04:05']);

        // Both API endpoints return the date converted to a date, in ISO 8601 (UTC).
        $this->getJson(route('api.email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertJsonPath('email.date', '2021-01-02T03:04:05.000000Z');

        $this->getJson(route('api.email-log'))
            ->assertOk()
            ->assertJsonPath('data.0.date', '2021-01-02T03:04:05.000000Z');
    }

    public function test_old_emails_can_be_deleted()
    {
        $old = $this->sendEmail('Old email', 'jane@example.com', ['old.txt' => 'old file']);
        $new = $this->sendEmail('New email', 'jane@example.com');

        // Move the first email back in time; the second one keeps today's date.
        EmailLog::where('id', $old->id)->update(['date' => '2020-01-01 10:00:00']);

        $response = $this->post(route('email-log.delete-old'), ['date' => '2021-01-01']);

        $response->assertRedirect(route('email-log'));
        $this->assertStringStartsWith('Deleted 1 emails', session('status'));

        $this->assertNull(EmailLog::find($old->id));
        $this->assertNotNull(EmailLog::find($new->id));
        Storage::disk('email_log_attachments')->assertMissing($old->messageId . '/old.txt');
    }

    public function test_old_emails_can_be_deleted_through_the_api()
    {
        $old = $this->sendEmail('Old email');
        $new = $this->sendEmail('New email');
        EmailLog::where('id', $old->id)->update(['date' => '2020-01-01 10:00:00']);

        $response = $this->postJson(route('api.email-log.delete-old'), ['date' => '2021-01-01']);

        $response->assertOk();
        $this->assertStringStartsWith('Deleted 1 emails', $response->json('message'));
        $this->assertNull(EmailLog::find($old->id));
        $this->assertNotNull(EmailLog::find($new->id));
    }

    public function test_old_emails_without_a_message_id_can_be_deleted()
    {
        $disk = Storage::disk('email_log_attachments');
        $disk->put('unrelated.txt', 'another file on the same disk');

        // Emails logged before the messageId column existed (2018) have no message ID. An empty
        // one would make their attachment folder the disk's root folder.
        EmailLog::create(['date' => '2017-01-01 10:00:00', 'subject' => 'No message ID', 'body' => 'Old body', 'messageId' => null]);
        EmailLog::create(['date' => '2017-01-01 10:00:00', 'subject' => 'Empty message ID', 'body' => 'Old body', 'messageId' => '']);
        $old = $this->sendEmail('Old email', 'jane@example.com', ['old.txt' => 'old file']);
        EmailLog::where('id', $old->id)->update(['date' => '2020-01-01 10:00:00']);

        $this->post(route('email-log.delete-old'), ['date' => '2021-01-01']);

        $this->assertStringStartsWith('Deleted 3 emails', session('status'));
        $this->assertSame(0, EmailLog::count());
        $disk->assertMissing($old->messageId . '/old.txt');
        $disk->assertExists('unrelated.txt');
    }

    public function test_deleting_old_emails_includes_the_selected_date()
    {
        $dayBefore = $this->sendEmail('Day before');
        $selectedDay = $this->sendEmail('Selected day, last second');
        $nextDay = $this->sendEmail('Next day, midnight');

        EmailLog::where('id', $dayBefore->id)->update(['date' => '2021-01-01 12:00:00']);
        EmailLog::where('id', $selectedDay->id)->update(['date' => '2021-01-02 23:59:59']);
        EmailLog::where('id', $nextDay->id)->update(['date' => '2021-01-03 00:00:00']);

        DB::enableQueryLog();
        $this->post(route('email-log.delete-old'), ['date' => '2021-01-02']);

        // Emails logged on the selected date are deleted too; the next day is kept.
        $this->assertSame('Deleted 2 emails logged on and before Sat, 02 Jan 2021', session('status'));
        $this->assertNull(EmailLog::find($dayBefore->id));
        $this->assertNull(EmailLog::find($selectedDay->id));
        $this->assertNotNull(EmailLog::find($nextDay->id));

        // SQLite and MySQL only agree if the date is sent in the format dates are stored in.
        $bindings = collect(DB::getQueryLog())->pluck('bindings')->flatten()->all();
        $this->assertContains('2021-01-03 00:00:00', $bindings);
    }

    public function test_deleting_old_emails_also_deletes_their_events()
    {
        $old = $this->sendEmail('Old email');
        $new = $this->sendEmail('New email');
        EmailLog::where('id', $old->id)->update(['date' => '2020-01-01 10:00:00']);

        EmailLogEvent::create(['messageId' => $old->messageId, 'event' => 'delivered', 'data' => '{}']);
        EmailLogEvent::create(['messageId' => $new->messageId, 'event' => 'delivered', 'data' => '{}']);

        $this->post(route('email-log.delete-old'), ['date' => '2021-01-01']);

        $this->assertSame(0, EmailLogEvent::where('messageId', $old->messageId)->count());
        $this->assertSame(1, EmailLogEvent::where('messageId', $new->messageId)->count());
    }

    public function test_the_list_page_can_be_searched_with_empty_filters()
    {
        $this->sendEmail('Welcome aboard');

        // What the browser sends when "Search" is clicked with both boxes empty.
        $this->get(route('email-log') . '?filterEmail=&filterSubject=')
            ->assertOk()
            ->assertSee('Welcome aboard');
    }

    public function test_the_api_accepts_empty_filters()
    {
        $this->sendEmail('Welcome aboard');

        $this->getJson(route('api.email-log') . '?filterEmail=&filterSubject=&per_page=')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
