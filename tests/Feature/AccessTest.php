<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\Middleware\DenyAccess;
use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;

/**
 * Who can reach the email log: only requests that pass the access middleware the app configured.
 */
class AccessTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // In this class the configured middleware turns everyone away.
        $app['config']->set('email_log.access_middleware', DenyAccess::class);
        $app['config']->set('email_log.access_middleware_api', DenyAccess::class);
    }

    public function test_the_pages_are_not_found_without_an_access_middleware()
    {
        $log = $this->sendEmail('Secret email', 'jane@example.com', ['secret.txt' => 'secret contents']);
        config(['email_log.access_middleware' => null]);

        $this->get(route('email-log'))->assertNotFound();
        $this->get(route('email-log.show', ['id' => $log->id]))->assertNotFound();
        $this->get(route('email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]))->assertNotFound();
        $this->post(route('email-log.delete-old'), ['date' => '2100-01-01'])->assertNotFound();

        $this->assertSame(1, EmailLog::count());
    }

    public function test_the_api_is_not_found_without_an_api_access_middleware()
    {
        $log = $this->sendEmail('Secret email', 'jane@example.com', ['secret.txt' => 'secret contents']);
        config(['email_log.access_middleware_api' => null]);

        $this->getJson(route('api.email-log'))->assertNotFound();
        $this->getJson(route('api.email-log.show', ['id' => $log->id]))->assertNotFound();
        $this->getJson(route('api.email-log.fetch-attachment', ['id' => $log->id, 'attachment' => 0]))->assertNotFound();
        $this->postJson(route('api.email-log.delete-old'), ['date' => '2100-01-01'])->assertNotFound();

        $this->assertSame(1, EmailLog::count());
    }

    public function test_an_empty_setting_counts_as_not_set()
    {
        // What EMAIL_LOG_ACCESS_MIDDLEWARE= (with nothing after "=") in .env gives.
        config(['email_log.access_middleware' => '', 'email_log.access_middleware_api' => ' ']);

        $this->get(route('email-log'))->assertNotFound();
        $this->getJson(route('api.email-log'))->assertNotFound();
    }

    public function test_the_configured_access_middleware_is_applied()
    {
        $log = $this->sendEmail('Secret email');

        $this->get(route('email-log'))->assertForbidden();
        $this->get(route('email-log.show', ['id' => $log->id]))->assertForbidden();
        $this->getJson(route('api.email-log'))->assertForbidden();
        $this->getJson(route('api.email-log.show', ['id' => $log->id]))->assertForbidden();
    }

    public function test_the_mailgun_webhook_does_not_need_an_access_middleware()
    {
        config(['email_log.access_middleware' => null, 'email_log.access_middleware_api' => null]);

        // 400 "verification failed" means the request reached the webhook; it is protected by
        // Mailgun's signature instead.
        $this->postJson(route('email-log.webhooks'), [])->assertStatus(400);
    }
}
