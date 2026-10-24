<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests;

use Dmcbrn\LaravelEmailDatabaseLog\EmailLog;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEmailDatabaseLogServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    // Re-creates the database (running the package's migrations) before every test.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A throw-away disk for attachments, emptied for every test.
        Storage::fake('email_log_attachments');
    }

    /**
     * Tell Testbench which service providers to load into the fake Laravel app.
     */
    protected function getPackageProviders($app)
    {
        return [LaravelEmailDatabaseLogServiceProvider::class];
    }

    /**
     * Configure the fake Laravel app.
     */
    protected function getEnvironmentSetUp($app)
    {
        $config = $app['config'];

        // Web pages encrypt cookies, which needs an app key. Any 32 random bytes will do in tests.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // The database: in-memory SQLite by default (fast, nothing to install or clean up).
        // GitHub Actions also runs the tests on MySQL and PostgreSQL by setting
        // EMAIL_LOG_TEST_DB_CONNECTION. Own variable names, so the tests never wipe a
        // database that is configured for something else.
        $config->set('database.default', 'testing');
        $driver = env('EMAIL_LOG_TEST_DB_CONNECTION', 'sqlite');
        $config->set('database.connections.testing', $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']
            : [
                'driver' => $driver,
                'host' => env('EMAIL_LOG_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('EMAIL_LOG_TEST_DB_PORT'),
                'database' => env('EMAIL_LOG_TEST_DB_DATABASE', 'email_log_tests'),
                'username' => env('EMAIL_LOG_TEST_DB_USERNAME', 'root'),
                'password' => env('EMAIL_LOG_TEST_DB_PASSWORD', ''),
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '',
            ]);

        // The "array" mailer never sends anything; it only keeps mails in memory.
        // The package logs mails before they reach the mailer, so it works the same.
        $config->set('mail.default', 'array');
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Example App']);

        $config->set('session.driver', 'array');
        $config->set('cache.default', 'array');

        $config->set('email_log.disk', 'email_log_attachments');
        $config->set('email_log.mailgun.secret', 'test-secret');
    }

    /**
     * Send a mail through Laravel and return the row the package logged for it.
     *
     * @param  string  $subject
     * @param  string  $to
     * @param  array   $attachments  [file name => file contents]
     * @return EmailLog
     */
    protected function sendEmail($subject = 'Test subject', $to = 'jane@example.com', array $attachments = [])
    {
        Mail::raw('Hello there', function ($message) use ($subject, $to, $attachments) {
            $message->from('noreply@example.com', 'Example App')
                ->to($to)
                ->subject($subject);

            foreach ($attachments as $name => $contents) {
                $message->attachData($contents, $name, ['mime' => 'text/plain']);
            }
        });

        return EmailLog::orderBy('id', 'desc')->first();
    }

    /**
     * MySQL saves changes to a table's structure immediately, so a test that changes one (and
     * everything it saves afterwards) can't be undone and would affect the tests after it.
     */
    protected function skipOnMySql()
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL saves table structure changes immediately; they cannot be undone after the test.');
        }
    }
}