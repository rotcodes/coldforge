<?php

namespace App\Services\Inbox;

use Carbon\CarbonInterface;

interface MailboxReader
{
    /**
     * Folder status: ['uidvalidity' => int, 'uidnext' => int]. When uidvalidity changes,
     * previously stored UIDs are meaningless; uidnext - 1 is the newest UID right now.
     */
    public function status(): array;

    /**
     * Messages with a UID above $afterUid, or received since $since when there is no cursor yet.
     * Each item: uid, message_id, in_reply_to, references[], from_email, from_name, to[], cc[],
     * subject, text, html, attachments[], date. Ordered by UID ascending.
     *
     * @return iterable<array>
     */
    public function fetch(?int $afterUid, CarbonInterface $since): iterable;
}
