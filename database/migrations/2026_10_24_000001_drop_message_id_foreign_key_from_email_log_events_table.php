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
        // unknown emails must be storable (EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS=false).
        // MySQL keeps the index it created for the foreign key, so lookups stay indexed.
        Schema::table('email_log_events', function (Blueprint $table) {
            $table->dropForeign(['messageId']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (!$this->runsOnThisDatabase()) {
            return;
        }

        Schema::table('email_log_events', function (Blueprint $table) {
            $table->foreign('messageId')
                ->references('messageId')->on('email_log')
                ->onDelete('cascade');
        });
    }

    /**
     * The foreign key only ever existed on MySQL and MariaDB (see the 2019 migration that added it).
     *
     * @return bool
     */
    private function runsOnThisDatabase()
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}