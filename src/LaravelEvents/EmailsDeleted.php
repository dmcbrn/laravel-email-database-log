<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents;

/**
 * Fired after "Delete old emails" (on the page or through the API) deleted a batch of emails,
 * once per batch of up to 500 emails.
 */
class EmailsDeleted
{
    /**
     * The IDs of the deleted emails.
     *
     * @var int[]
     */
    public $ids;

    /**
     * The message IDs of the deleted emails (emails logged before 2018 have none).
     *
     * @var string[]
     */
    public $messageIds;

    /**
     * Create a new event instance.
     *
     * @param  int[]  $ids
     * @param  string[]  $messageIds
     * @return void
     */
    public function __construct(array $ids, array $messageIds)
    {
        $this->ids = $ids;
        $this->messageIds = $messageIds;
    }
}
