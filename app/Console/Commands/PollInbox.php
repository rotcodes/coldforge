<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Inbox\InboxIngestor;
use App\Services\Inbox\MailboxReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls new mail from the reply mailbox over IMAP (every minute via routes/console.php).
 * Reading the whole mailbox catches replies to aliases too (e.g. luke@ delivering into sameer@).
 * Cursor = last seen UID per UIDVALIDITY, kept in settings; the first run looks back
 * `inbox_imap.first_run_days` days. Message-ID dedup makes re-reads harmless.
 */
class PollInbox extends Command
{
    protected $signature = 'inbox:poll';

    protected $description = 'Fetch new replies from the IMAP mailbox into the inbox';

    public function handle(MailboxReader $reader, InboxIngestor $ingestor): int
    {
        $config = config('coldforge.inbox_imap');
        if (! $config['username'] || ! $config['password']) {
            return self::SUCCESS;
        }

        try {
            ['uidvalidity' => $validity, 'uidnext' => $uidNext] = $reader->status();
            $cursor = Setting::get('imap_uidvalidity') == $validity ? Setting::get('imap_last_uid') : null;
            $since = now()->subDays($config['first_run_days']);

            $stored = 0;
            $lastUid = $cursor === null ? null : (int) $cursor;
            foreach ($reader->fetch($lastUid, $since) as $m) {
                try {
                    $stored += $ingestor->ingest($m, $config['username'], "imap:{$validity}:{$m['uid']}") ? 1 : 0;
                } catch (Throwable $e) {
                    // Skip a bad message rather than blocking every later one behind it.
                    Log::error("inbox:poll could not store UID {$m['uid']}: ".$e->getMessage(), ['message_id' => $m['message_id'] ?? null]);
                }
                $lastUid = $m['uid'];
                Setting::put('imap_last_uid', $lastUid);
            }

            // First run (or UIDVALIDITY reset): start the cursor at the mailbox's current end,
            // so older mail outside the look-back window is never pulled in later.
            if ($cursor === null) {
                $lastUid = max((int) $lastUid, $uidNext - 1);
            }
            Setting::put('imap_uidvalidity', $validity);
            Setting::put('imap_last_uid', $lastUid);
            Setting::put('imap_last_polled_at', now()->toIso8601String());
            Setting::put('imap_last_error', null);
            $this->info("Stored {$stored} new message(s).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            Setting::put('imap_last_error', mb_substr($e->getMessage(), 0, 500));
            Log::error('inbox:poll failed: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
