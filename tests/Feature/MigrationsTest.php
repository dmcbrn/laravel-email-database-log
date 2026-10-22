<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationsTest extends TestCase
{
    public function test_the_migrations_create_the_email_log_table()
    {
        $this->assertTrue(Schema::hasTable('email_log'));
        $this->assertTrue(Schema::hasColumns('email_log', [
            'id', 'date', 'from', 'to', 'cc', 'bcc', 'subject', 'body',
            'headers', 'attachments', 'messageId', 'mail_driver',
        ]));
    }

    public function test_the_migrations_create_the_email_log_events_table()
    {
        $this->assertTrue(Schema::hasTable('email_log_events'));
        $this->assertTrue(Schema::hasColumns('email_log_events', [
            'id', 'created_at', 'updated_at', 'messageId', 'event', 'data',
        ]));
    }
}