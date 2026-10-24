<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_log';

    /**
     * Disable timestamps for the model.
     *
     * @var boolean
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'body',
        'headers',
        'attachments',
        'messageId',
        'mail_driver',
        'inline_attachments',
        'body_content_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'datetime',
        'inline_attachments' => 'array',
    ];

    /**
     * Delete an email's events together with the email, on every database. This covers deletes
     * through the model ($email->delete(), EmailLog::destroy()); bulk query deletes such as
     * EmailLog::where(...)->delete() do not fire model events.
     *
     * @return void
     */
    protected static function booted()
    {
        static::deleting(function (EmailLog $email) {
            $email->events()->delete();
        });
    }

    /**
     * The Events belonging to this Email.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function events()
    {
        return $this->hasMany('Dmcbrn\LaravelEmailDatabaseLog\EmailLogEvent','messageId','messageId');
    }
}
