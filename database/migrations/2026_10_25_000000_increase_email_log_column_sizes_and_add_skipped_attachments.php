<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class IncreaseEmailLogColumnSizesAndAddSkippedAttachments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // TEXT columns can't have a normal index on MySQL. The package searches "to" and "subject"
        // with LIKE '%...%', which can't use these indexes anyway.
        Schema::table('email_log', function (Blueprint $table) {
            $table->dropIndex(['to']);
            $table->dropIndex(['subject']);
        });

        // Large enough that an email never fails to be logged because a value doesn't fit: the body
        // and the headers are cut off at EMAIL_LOG_MAX_BODY_SIZE, the other values at 64 KB. On a
        // large MySQL table, this can lock the table for a while.
        Schema::table('email_log', function (Blueprint $table) {
            $table->text('from')->nullable()->change();
            $table->text('to')->nullable()->change();
            $table->text('cc')->nullable()->change();
            $table->text('bcc')->nullable()->change();
            $table->text('subject')->change();
            $table->longText('body')->change();
            $table->longText('headers')->nullable()->change();

            // attachments that were too large to store: name, size and the limit at that time
            $table->json('skipped_attachments')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Fails on MySQL and PostgreSQL if a value no longer fits the smaller columns.
        Schema::table('email_log', function (Blueprint $table) {
            $table->dropColumn('skipped_attachments');

            $table->string('from')->nullable()->change();
            $table->string('to')->nullable()->change();
            $table->string('cc')->nullable()->change();
            $table->string('bcc')->nullable()->change();
            $table->string('subject')->change();
            $table->text('body')->change();
            $table->text('headers')->nullable()->change();
        });

        Schema::table('email_log', function (Blueprint $table) {
            $table->index('to');
            $table->index('subject');
        });
    }
}
