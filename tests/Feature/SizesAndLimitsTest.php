<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Mockery;

/**
 * Emails of any size are sent; values that don't fit the log's columns are cut off.
 */
class SizesAndLimitsTest extends TestCase
{
    public function test_an_error_while_logging_does_not_stop_the_email()
    {
        // Records what is reported, instead of reporting it.
        $handler = $this->spy(ExceptionHandler::class);
        $sent = 0;
        Event::listen(MessageSent::class, function () use (&$sent) {
            $sent++;
        });

        // A disk that does not exist makes storing the attachment fail.
        config(['email_log.disk' => 'missing-disk']);

        $this->sendEmail('Still sent', 'jane@example.com', ['file.txt' => 'contents']);

        // The email went out, nothing was logged, and the error was reported (e.g. to Sentry).
        $this->assertSame(1, $sent);
        $this->assertSame(0, EmailLog::count());
        $handler->shouldHaveReceived('report')->once()->with(Mockery::type(InvalidArgumentException::class));
    }

    public function test_a_body_over_64_kb_is_truncated()
    {
        // 80 KB: more than a MySQL TEXT column holds.
        $this->sendRaw(str_repeat('a', 80 * 1024));

        $body = EmailLog::first()->body;
        $this->assertSame(65535, strlen($body));
        $this->assertStringStartsWith(str_repeat('a', 1000), $body);
        $this->assertStringEndsWith('…[truncated]', $body);
    }

    public function test_truncating_the_body_does_not_cut_a_character_in_half()
    {
        $this->sendRaw(str_repeat('€', 30000)); // 3 bytes each, 90 KB

        $body = EmailLog::first()->body;
        $this->assertTrue(mb_check_encoding($body, 'UTF-8'));
        $this->assertLessThanOrEqual(65535, strlen($body));
        $this->assertStringEndsWith('…[truncated]', $body);
    }

    public function test_addresses_and_the_subject_over_255_characters_are_truncated()
    {
        // About 1 KB of addresses in each field, and a 300 character subject.
        $recipients = array_map(function ($i) {
            return "recipient{$i}@example.com";
        }, range(1, 40));
        Mail::raw('Hello', function ($message) use ($recipients) {
            $message->from('noreply@example.com')
                ->to($recipients)->cc($recipients)->bcc($recipients)
                ->subject(str_repeat('x', 300));
        });

        $log = EmailLog::first();
        foreach (['to', 'cc', 'bcc', 'subject'] as $column) {
            $this->assertSame(255, mb_strlen($log->$column), $column);
            $this->assertStringEndsWith('…[truncated]', $log->$column, $column);
        }
        $this->assertStringStartsWith('recipient1@example.com, recipient2@example.com', $log->to);
    }

    public function test_the_subject_is_truncated_by_characters_not_bytes()
    {
        // 300 two-byte characters: VARCHAR(255) holds 255 characters, however many bytes they are.
        Mail::raw('Hello', function ($message) {
            $message->from('noreply@example.com')->to('jane@example.com')->subject(str_repeat('č', 300));
        });

        $subject = EmailLog::first()->subject;
        $this->assertSame(255, mb_strlen($subject));
        $this->assertStringStartsWith(str_repeat('č', 243), $subject);
        $this->assertStringEndsWith('…[truncated]', $subject);
    }

    public function test_headers_over_64_kb_are_truncated()
    {
        // About 80 KB of recipients make the headers longer than a TEXT column holds.
        $recipients = array_map(function ($i) {
            return "recipient{$i}@example.com";
        }, range(1, 3000));
        Mail::raw('Hello', function ($message) use ($recipients) {
            $message->from('noreply@example.com')->to($recipients)->subject('Many recipients');
        });

        $headers = EmailLog::first()->headers;
        $this->assertSame(65535, strlen($headers));
        $this->assertStringEndsWith('…[truncated]', $headers);
    }

    public function test_values_that_fit_are_logged_unchanged()
    {
        $subject = str_repeat('x', 255);
        $body = str_repeat('a', 65535);
        Mail::raw($body, function ($message) use ($subject) {
            $message->from('noreply@example.com')->to('jane@example.com')->subject($subject);
        });

        $log = EmailLog::first();
        $this->assertSame($subject, $log->subject);
        $this->assertSame($body, $log->body);
    }

    private function sendRaw($body)
    {
        Mail::raw($body, function ($message) {
            $message->from('noreply@example.com')->to('jane@example.com')->subject('Test');
        });
    }
}
