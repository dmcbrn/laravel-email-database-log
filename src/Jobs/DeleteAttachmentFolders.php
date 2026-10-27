<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Deletes the attachment folders of deleted emails. Runs immediately by default (the "sync"
 * connection); EMAIL_LOG_QUEUE_CONNECTION and EMAIL_LOG_QUEUE move it to a queue.
 */
class DeleteAttachmentFolders implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * The folders to delete: each email's attachments are stored in a folder named after its message ID.
     *
     * @var string[]
     */
    public $messageIds;

    /**
     * @param  string[]  $messageIds
     */
    public function __construct(array $messageIds)
    {
        $this->messageIds = array_values($messageIds);
    }

    public function handle()
    {
        $disk = Storage::disk(config('email_log.disk'));

        foreach ($this->messageIds as $messageId) {
            //only a plain folder name is deleted: "x/.." or ".." would point at the disk's root folder,
            //and deleting that would delete everything on the disk
            if (!self::isPlainFolderName($messageId)) {
                Log::warning('Email log: skipped deleting the attachment folder "' . $messageId . '", because it is not a plain folder name.');
                continue;
            }

            //a folder that can't be deleted is reported (log file, or an error tracker such as Sentry),
            //and doesn't stop the others
            try {
                if ($disk->deleteDirectory($messageId) === false) {
                    throw new RuntimeException('Email log: could not delete the attachment folder "' . $messageId . '".');
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Whether a message ID can safely be used as a folder name: not empty, not "." or "..", and
     * without "/" or "\".
     *
     * @param  mixed  $name
     * @return bool
     */
    public static function isPlainFolderName($name)
    {
        $name = (string) $name;

        return $name !== '' && $name !== '.' && $name !== '..' && strpbrk($name, '/\\') === false;
    }
}
