<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AiConversationMessage;
use App\Models\AiRequest;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use App\Models\MailSyncLog;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneColdForgeData extends Command
{
    protected $signature = 'mail:prune-data {--dry-run}';

    protected $description = 'Apply configured retention windows to mail bodies, logs, AI history, and cached attachments.';

    public function handle(): int
    {
        $windows = collect(config('coldforge.retention'))->mapWithKeys(fn ($default, $key) => [$key => (int) Setting::get('retention.'.$key, $default)]);
        $counts = [];
        $counts['message bodies'] = $this->countOrRun($windows['message_bodies_days'], fn ($before, $run) => $run
            ? MailMessage::where('message_at', '<', $before)->where(fn ($q) => $q->whereNotNull('plain_body')->orWhereNotNull('html_body'))->update(['plain_body' => null, 'html_body' => null])
            : MailMessage::where('message_at', '<', $before)->where(fn ($q) => $q->whereNotNull('plain_body')->orWhereNotNull('html_body'))->count());
        $counts['activity logs'] = $this->countOrRun($windows['activity_days'], fn ($before, $run) => $run ? ActivityLog::where('created_at', '<', $before)->delete() : ActivityLog::where('created_at', '<', $before)->count());
        $counts['sync logs'] = $this->countOrRun($windows['sync_logs_days'], fn ($before, $run) => $run ? MailSyncLog::where('created_at', '<', $before)->delete() : MailSyncLog::where('created_at', '<', $before)->count());
        $counts['AI requests'] = $this->countOrRun($windows['ai_history_days'], fn ($before, $run) => $run ? AiRequest::where('created_at', '<', $before)->delete() : AiRequest::where('created_at', '<', $before)->count());
        $counts['AI messages'] = $this->countOrRun($windows['ai_history_days'], fn ($before, $run) => $run ? AiConversationMessage::where('created_at', '<', $before)->delete() : AiConversationMessage::where('created_at', '<', $before)->count());
        $counts['attachments'] = $this->pruneAttachments($windows['downloaded_attachments_days']);

        foreach ($counts as $label => $count) {
            $this->line(($this->option('dry-run') ? 'Would prune ' : 'Pruned ').$count.' '.$label.'.');
        }

        return self::SUCCESS;
    }

    private function countOrRun(int $days, callable $callback): int
    {
        if ($days <= 0) {
            return 0;
        }

        return (int) $callback(now()->subDays($days), ! $this->option('dry-run'));
    }

    private function pruneAttachments(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $query = MailAttachment::where('updated_at', '<', now()->subDays($days))->where('is_downloaded', true)->whereNotNull('storage_path');
        if ($this->option('dry-run')) {
            return $query->count();
        }
        $count = 0;
        $query->chunkById(100, function ($attachments) use (&$count) {
            foreach ($attachments as $attachment) {
                Storage::disk('local')->delete($attachment->storage_path);
                $attachment->update(['storage_path' => null, 'is_downloaded' => false, 'scan_status' => 'pending', 'scanned_at' => null, 'scan_result' => null]);
                $count++;
            }
        });

        return $count;
    }
}
