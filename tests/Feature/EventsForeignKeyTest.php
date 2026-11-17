<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Tests\Feature;

use Dmcbrn\LaravelEmailDatabaseLog\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_log_events.messageId has an index, but no foreign key to email_log.messageId: MySQL 8.4
 * refuses a foreign key to a column without a unique index, which email_log.messageId is.
 */
class EventsForeignKeyTest extends TestCase
{
    /**
     * MySQL saves table structure changes immediately, so these tests don't run inside the usual
     * transaction. They create no rows, and leave the table structure as they found it.
     */
    public function beginDatabaseTransaction()
    {
    }

    public function test_the_events_message_id_has_an_index_and_no_foreign_key()
    {
        $this->onlyOnMySql();

        $this->assertSame([], $this->foreignKeys());
        $this->assertTrue($this->hasIndex());
    }

    public function test_the_migration_drops_a_foreign_key_left_by_older_versions()
    {
        $this->onlyOnMySql();

        try {
            // As in a database created by an older version, or imported from one into MySQL 8.4,
            // which only accepts the key with restrict_fk_on_non_standard_key turned off.
            if (!empty(DB::select("show variables like 'restrict_fk_on_non_standard_key'"))) {
                DB::statement('set session restrict_fk_on_non_standard_key = off');
            }
            Schema::table('email_log_events', function ($table) {
                $table->foreign('messageId')->references('messageId')->on('email_log')->onDelete('cascade');
            });
            $this->assertCount(1, $this->foreignKeys());

            (new \DropMessageIdForeignKeyFromEmailLogEventsTable())->up();

            $this->assertSame([], $this->foreignKeys());
            $this->assertTrue($this->hasIndex());
        } finally {
            // Never leave the key behind for the other tests, even if an assertion failed.
            foreach ($this->foreignKeys() as $name) {
                DB::statement('alter table email_log_events drop foreign key `' . $name . '`');
            }
        }
    }

    private function onlyOnMySql()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The foreign key only ever existed on MySQL and MariaDB.');
        }
    }

    private function foreignKeys()
    {
        return array_map(function ($row) {
            return $row->name;
        }, DB::select(
            'select distinct CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE'
            . " where TABLE_SCHEMA = database() and TABLE_NAME = 'email_log_events' and COLUMN_NAME = 'messageId'"
            . ' and REFERENCED_TABLE_NAME is not null'
        ));
    }

    private function hasIndex()
    {
        return !empty(DB::select(
            'select INDEX_NAME from information_schema.STATISTICS'
            . " where TABLE_SCHEMA = database() and TABLE_NAME = 'email_log_events' and COLUMN_NAME = 'messageId'"
        ));
    }
}