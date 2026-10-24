<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLogEvent;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;

/**
 * Mailgun calls this webhook ("delivered", "opened", ...) and the package attaches the event
 * to the logged email by its Message-ID. This is why the Message-ID we log has to be the one
 * that is really sent with the email.
 */
class MailgunWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // If the webhook crashes, show the real exception instead of just "got 500".
        // (The webhook's own 400 responses are normal responses, not exceptions.)
        $this->withoutExceptionHandling();
    }

    /**
     * Build a request body signed the way Mailgun signs it.
     */
    protected function webhookPayload($messageId, $event = 'delivered', $secret = 'test-secret', $timestamp = null)
    {
        $timestamp = (string) ($timestamp ?: time());
        $token = 'random-token';

        return [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp . $token, $secret),
            ],
            'event-data' => [
                'event' => $event,
                // Mailgun reports the Message-ID as "<id>@<domain>"; the package keeps the part before "@".
                'message' => ['headers' => ['message-id' => $messageId . '@example.com']],
            ],
        ];
    }

    public function test_a_signed_event_is_saved_for_the_logged_email()
    {
        $log = $this->sendEmail();

        $response = $this->postJson(route('email-log.webhooks'), $this->webhookPayload($log->messageId, 'delivered'));

        $response->assertOk();
        $this->assertSame('Success', $response->getContent());
        $this->assertSame(1, $log->events()->count());
        $this->assertSame('delivered', $log->events()->first()->event);
    }

    public function test_a_correctly_signed_event_that_arrives_late_is_accepted()
    {
        $log = $this->sendEmail();

        // Mailgun can deliver webhooks late and retries failed ones for up to 8 hours.
        $payload = $this->webhookPayload($log->messageId, 'delivered', 'test-secret', time() - 3600);

        $this->postJson(route('email-log.webhooks'), $payload)->assertOk();
        $this->assertSame(1, $log->events()->count());
    }

    public function test_an_event_older_than_the_configured_maximum_age_is_rejected()
    {
        config(['email_log.mailgun.max_age' => 60]);
        $log = $this->sendEmail();

        $payload = $this->webhookPayload($log->messageId, 'delivered', 'test-secret', time() - 120);

        $this->postJson(route('email-log.webhooks'), $payload)->assertStatus(400);
        $this->assertSame(0, EmailLogEvent::count());
    }

    public function test_the_age_check_can_be_turned_off()
    {
        config(['email_log.mailgun.max_age' => 0]);
        $log = $this->sendEmail();

        $payload = $this->webhookPayload($log->messageId, 'delivered', 'test-secret', time() - 2 * 86400);

        $this->postJson(route('email-log.webhooks'), $payload)->assertOk();
        $this->assertSame(1, $log->events()->count());
    }

    public function test_the_default_maximum_age_also_applies_with_an_older_published_config()
    {
        // A config file published before this setting existed has no "max_age" key.
        config(['email_log.mailgun' => ['secret' => 'test-secret', 'filter_unknown_emails' => true]]);
        $log = $this->sendEmail();

        $payload = $this->webhookPayload($log->messageId, 'delivered', 'test-secret', time() - 3600);

        $this->postJson(route('email-log.webhooks'), $payload)->assertOk();
    }

    public function test_the_saved_event_is_visible_through_the_api()
    {
        $log = $this->sendEmail();
        $this->postJson(route('email-log.webhooks'), $this->webhookPayload($log->messageId, 'opened'))->assertOk();

        $this->getJson(route('api.email-log.show', ['id' => $log->id]))
            ->assertOk()
            ->assertJsonPath('email.events.0.event', 'opened');
    }

    public function test_an_event_with_a_wrong_signature_is_rejected()
    {
        $log = $this->sendEmail();

        $response = $this->postJson(
            route('email-log.webhooks'),
            $this->webhookPayload($log->messageId, 'delivered', 'some-other-secret')
        );

        $response->assertStatus(400);
        $this->assertSame('Error: verification failed', $response->getContent());
        $this->assertSame(0, EmailLogEvent::count());
    }

    public function test_events_are_rejected_when_no_secret_is_configured()
    {
        config(['email_log.mailgun.secret' => null]);
        $log = $this->sendEmail();

        // Anyone can compute a "signature" with an empty key; it must not be accepted.
        $response = $this->postJson(route('email-log.webhooks'), $this->webhookPayload($log->messageId, 'delivered', ''));

        $response->assertStatus(400);
        $this->assertSame(0, EmailLogEvent::count());
    }

    public function test_a_request_with_a_malformed_signature_is_rejected()
    {
        // Bots and scanners post anything; the webhook must answer 400, not crash with a 500.
        $this->postJson(route('email-log.webhooks'), [])->assertStatus(400);
        $this->postJson(route('email-log.webhooks'), ['signature' => 'not-an-array'])->assertStatus(400);
        $this->postJson(route('email-log.webhooks'), [
            'signature' => ['timestamp' => (string) time(), 'token' => ['an', 'array'], 'signature' => 'x'],
        ])->assertStatus(400);
    }

    public function test_a_signed_request_with_malformed_event_data_is_rejected()
    {
        $payload = $this->webhookPayload(str_repeat('a', 32));

        $payload['event-data'] = 'not-an-array';
        $this->postJson(route('email-log.webhooks'), $payload)->assertStatus(400);

        $payload['event-data'] = ['event' => 'delivered']; // no message-id
        $this->postJson(route('email-log.webhooks'), $payload)->assertStatus(400);

        $this->assertSame(0, EmailLogEvent::count());
    }

    public function test_an_event_for_an_unknown_email_is_ignored()
    {
        $response = $this->postJson(
            route('email-log.webhooks'),
            $this->webhookPayload(str_repeat('a', 32), 'delivered')
        );

        $response->assertOk();
        $this->assertSame('Error: no E-mail found', $response->getContent());
        $this->assertSame(0, EmailLogEvent::count());
    }

    public function test_an_event_for_an_unknown_email_is_saved_when_filtering_is_off()
    {
        // EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS=false: keep events for emails that are not in the log.
        config(['email_log.mailgun.filter_unknown_emails' => false]);
        $unknownId = str_repeat('a', 32);

        $response = $this->postJson(route('email-log.webhooks'), $this->webhookPayload($unknownId, 'delivered'));

        $response->assertOk();
        $this->assertSame('Success', $response->getContent());
        $this->assertSame(1, EmailLogEvent::where('messageId', $unknownId)->count());
    }

    public function test_the_webhook_signing_key_setting_takes_precedence_over_mailgun_secret()
    {
        // Laravel's own Mailgun mail transport reads its API key from MAILGUN_SECRET,
        // but Mailgun signs webhooks with a different key: the HTTP webhook signing key.
        putenv('MAILGUN_SECRET=api-key');
        putenv('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY=signing-key');

        try {
            $config = require __DIR__ . '/../../config/email_log.php';
            $this->assertSame('signing-key', $config['mailgun']['secret']);

            // Without the new setting, MAILGUN_SECRET is used, as in earlier versions.
            putenv('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY');
            $config = require __DIR__ . '/../../config/email_log.php';
            $this->assertSame('api-key', $config['mailgun']['secret']);
        } finally {
            putenv('MAILGUN_SECRET');
            putenv('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY');
        }
    }
}