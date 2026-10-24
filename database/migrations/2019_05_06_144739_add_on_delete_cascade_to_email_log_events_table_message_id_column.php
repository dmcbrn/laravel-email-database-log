<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddOnDeleteCascadeToEmailLogEventsTableMessageIdColumn extends Migration
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

        Schema::table('email_log_events', function (Blueprint $table) {
            $table->dropIndex(['messageId']);
            $table->foreign('messageId')
                ->references('messageId')->on('email_log')
                ->onDelete('cascade');
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
            $table->dropForeign(['messageId']);
            $table->index('messageId');
        });
    }

    /**
     * Only MySQL and MariaDB allow this foreign key: PostgreSQL and SQL Server require the referenced
     * column (email_log.messageId) to be unique, and SQLite never adds a foreign key to an existing
     * table (here the migration only dropped the messageId index). On those, keep the index instead.
     *
     * @return bool
     */
    private function runsOnThisDatabase()
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
