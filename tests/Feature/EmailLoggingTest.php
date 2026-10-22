<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailLogged;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * "Characterization" tests: they pin down what the package logs for an outgoing email today.
 * They have to keep passing, unchanged, on every Laravel version we upgrade to.
 */
class EmailLoggingTest extends TestCase
{
    public function test_it_logs_a_plain_text_email()
    {
        Mail::raw('Hello there', function ($message) {
            $message->from('noreply@example.com', 'Example App')
                ->to('jane@example.com', 'Jane Doe')
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->subject('Test subject');
        });

        $this->assertSame(1, EmailLog::count());

        $log = EmailLog::first();
        $this->assertSame('Example App <noreply@example.com>', $log->from);
        $this->assertSame('Jane Doe <jane@example.com>', $log->to);
        $this->assertSame('cc@example.com', $log->cc);
        $this->assertSame('bcc@example.com', $log->bcc);
        $this->assertSame('Test subject', $log->subject);
        $this->assertSame('Hello there', $log->body);
        $this->assertNull($log->attachments);
    }

    public function test_it_logs_the_transport_of_the_default_mailer()
    {
        // Laravel 7+ config: "mail.default" names a mailer, and that mailer's "transport" is the driver.
        config([
            'mail.default' => 'notifications',
            'mail.mailers.notifications' => ['transport' => 'array'],
        ]);

        $this->assertSame('array', $this->sendEmail()->mail_driver);
    }

    public function test_it_logs_the_driver_of_an_old_style_mail_config()
    {
        // Laravel <= 6 config files have a "mail.driver" key, which Laravel 8 still supports.
        config(['mail.driver' => 'array']);

        $this->assertSame('array', $this->sendEmail()->mail_driver);
    }

    public function test_it_logs_an_html_email()
    {
        Mail::html('<p>Hi <b>there</b></p>', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('HTML subject');
        });

        $log = EmailLog::first();
        $this->assertSame('HTML subject', $log->subject);
        $this->assertSame('<p>Hi <b>there</b></p>', $log->body);
    }

    public function test_it_logs_the_html_part_of_an_email_that_also_has_a_text_part()
    {
        // Same as a Mailable with both a ->view() and a ->text() template.
        Mail::send(
            ['html' => new HtmlString('<p>HTML version</p>'), 'text' => new HtmlString('Text version')],
            [],
            function ($message) {
                $message->from('noreply@example.com')
                    ->to('jane@example.com')
                    ->subject('Both parts');
            }
        );

        $log = EmailLog::first();
        $this->assertSame('<p>HTML version</p>', $log->body);
        $this->assertNull($log->cc);
        $this->assertNull($log->bcc);
    }

    public function test_it_logs_several_recipients_in_one_field()
    {
        Mail::raw('Hello there', function ($message) {
            $message->from('noreply@example.com')
                ->to(['jane@example.com' => 'Jane Doe', 'bob@example.com' => null])
                ->subject('Two recipients');
        });

        $this->assertSame('Jane Doe <jane@example.com>, bob@example.com', EmailLog::first()->to);
    }

    public function test_it_stores_a_message_id_that_also_appears_in_the_headers()
    {
        $log = $this->sendEmail();

        // 32 hex characters: this is what the `messageId` column (32 chars) is sized for.
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $log->messageId));

        $this->assertStringContainsString('Subject: Test subject', $log->headers);
        $this->assertStringContainsString($log->messageId . '@', $log->headers);
    }

    public function test_every_email_gets_its_own_row_and_message_id()
    {
        $first = $this->sendEmail('First');
        $second = $this->sendEmail('Second');

        $this->assertSame(2, EmailLog::count());
        $this->assertNotSame($first->messageId, $second->messageId);
    }

    public function test_it_stores_an_attachment_on_the_attachment_disk()
    {
        $log = $this->sendEmail('With file', 'jane@example.com', ['hello.txt' => 'file contents']);

        $this->assertSame($log->messageId . '/hello.txt', $log->attachments);

        $disk = Storage::disk('email_log_attachments');
        $disk->assertExists($log->messageId . '/hello.txt');
        $this->assertSame('file contents', $disk->get($log->messageId . '/hello.txt'));
    }

    public function test_it_stores_several_attachments()
    {
        $log = $this->sendEmail('With files', 'jane@example.com', [
            'one.txt' => 'first file',
            'two.txt' => 'second file',
        ]);

        $this->assertSame(
            $log->messageId . '/one.txt, ' . $log->messageId . '/two.txt',
            $log->attachments
        );

        $disk = Storage::disk('email_log_attachments');
        $this->assertSame('first file', $disk->get($log->messageId . '/one.txt'));
        $this->assertSame('second file', $disk->get($log->messageId . '/two.txt'));
    }

    public function test_it_stores_inline_images_with_the_attachments()
    {
        Mail::html('<p>Our logo</p>', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Inline image')
                ->attachData('file contents', 'hello.txt', ['mime' => 'text/plain']);

            // Same as {{ $message->embedData(...) }} in a mail view.
            $message->embedData('fake image bytes', 'logo.png', 'image/png');
        });

        $log = EmailLog::first();
        $this->assertSame($log->messageId . '/hello.txt, ' . $log->messageId . '/logo.png', $log->attachments);
        $this->assertSame('fake image bytes', Storage::disk('email_log_attachments')->get($log->messageId . '/logo.png'));
    }

    public function test_attachments_with_the_same_name_are_all_kept()
    {
        Mail::raw('Hello there', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Same names')
                ->attachData('first invoice', 'invoice.pdf', ['mime' => 'application/pdf'])
                ->attachData('second invoice', 'invoice.pdf', ['mime' => 'application/pdf'])
                ->attachData('third invoice', 'INVOICE.pdf', ['mime' => 'application/pdf']);
        });

        $log = EmailLog::first();
        $id = $log->messageId;
        $this->assertSame("$id/invoice.pdf, $id/2-invoice.pdf, $id/3-INVOICE.pdf", $log->attachments);

        $disk = Storage::disk('email_log_attachments');
        $this->assertSame('first invoice', $disk->get("$id/invoice.pdf"));
        $this->assertSame('second invoice', $disk->get("$id/2-invoice.pdf"));
        $this->assertSame('third invoice', $disk->get("$id/3-INVOICE.pdf"));
    }

    public function test_path_like_attachment_names_are_stored_inside_the_email_folder()
    {
        Mail::raw('Hello there', function ($message) {
            $message->from('noreply@example.com')
                ->to('jane@example.com')
                ->subject('Odd names')
                ->attachData('one', '../one.txt')
                ->attachData('two', '../../two.txt')
                ->attachData('three', 'folder\\three.txt')
                ->attachData('four', '..');
        });

        $log = EmailLog::first();
        $id = $log->messageId;
        $this->assertSame("$id/one.txt, $id/two.txt, $id/three.txt, $id/attachment-4", $log->attachments);

        // Nothing was written outside the email's own folder.
        $files = Storage::disk('email_log_attachments')->allFiles();
        sort($files);
        $this->assertSame(["$id/attachment-4", "$id/one.txt", "$id/three.txt", "$id/two.txt"], $files);
    }

    public function test_it_fires_an_email_logged_event()
    {
        $logged = null;
        Event::listen(EmailLogged::class, function ($event) use (&$logged) {
            $logged = $event;
        });

        $log = $this->sendEmail('Event subject');

        $this->assertNotNull($logged, 'The EmailLogged event was not fired.');
        $this->assertSame($log->id, $logged->emailLog->id);
        $this->assertSame('Event subject', $logged->emailLog->subject);
    }
}