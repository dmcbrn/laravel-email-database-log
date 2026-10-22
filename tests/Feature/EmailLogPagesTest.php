<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Illuminate\Support\Facades\Storage;

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

    public function test_the_show_page_displays_one_email_with_its_attachment()
    {
        $log = $this->sendEmail('Show me', 'jane@example.com', ['hello.txt' => 'file contents']);

        $this->get(route('email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertSee('Show me')
            ->assertSee('Hello there')
            ->assertSee('hello.txt');
    }

    public function test_an_attachment_can_be_downloaded()
    {
        $log = $this->sendEmail('Download me', 'jane@example.com', ['hello.txt' => 'file contents']);

        $response = $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]));

        $response->assertOk();
        $this->assertSame('file contents', $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
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
}