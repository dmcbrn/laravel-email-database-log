<?php

use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToEmailLogEventsTableMessageIdColumn extends Migration
{
    /**
     * Run the migrations.
     *
     * Older versions added a foreign key here, from email_log_events.messageId to
     * email_log.messageId (on MySQL and MariaDB). It is no longer created: MySQL 8.4 refuses a
     * foreign key to a column without a unique index, and the package doesn't need it, because the
     * EmailLog model deletes an email's events, and events for unknown emails must be storable
     * (EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS=false). The index on email_log_events.messageId,
     * from the migration that creates the table, stays. Databases that still have the key lose it
     * in 2026_10_24_000001_drop_message_id_foreign_key_from_email_log_events_table.
     *
     * The migration stays, empty, because existing databases have it recorded as run.
     *
     * @return void
     */
    public function up()
    {
        //
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}