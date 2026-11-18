<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DropMessageIdForeignKeyFromEmailLogEventsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!$this->runsOnThisDatabase()) {
            return;
        }

        // Events are deleted together with their email by the EmailLog model, and events for
        // unknown emails must be storable (EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS=false). Only
        // databases created by older versions have the key; fresh installs never get it (see the
        // 2019 migration). MySQL keeps the index it created for the key, so lookups stay indexed.
        $foreignKeys = $this->messageIdForeignKeys();
        if (empty($foreignKeys)) {
            return;
        }

        Schema::table('email_log_events', function (Blueprint $table) use ($foreignKeys) {
            foreach ($foreignKeys as $name) {
                $table->dropForeign($name);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to do: the key is no longer created at all (see the 2019 migration), and MySQL 8.4
     * would refuse to add it back.
     *
     * @return void
     */
    public function down()
    {
        //
    }

    /**
     * The names of the foreign keys on email_log_events.messageId, read from the database itself,
     * so this works the same on every Laravel version (whatever the key is called).
     *
     * @return string[]
     */
    private function messageIdForeignKeys()
    {
        $connection = Schema::getConnection();
        $rows = $connection->select(
            'select distinct CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE'
            . ' where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?'
            . ' and REFERENCED_TABLE_NAME is not null',
            [$connection->getTablePrefix() . 'email_log_events', 'messageId']
        );

        return array_map(function ($row) {
            return $row->name;
        }, $rows);
    }

    /**
     * The foreign key only ever existed on MySQL and MariaDB.
     *
     * @return bool
     */
    private function runsOnThisDatabase()
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}