<?php

namespace App\Services\Deliverability;

use App\Models\ActivityLog;
use App\Models\MailboxSetting;
use App\Models\MailMessage;

class SendingHealthMonitor
{
    public function evaluate(): bool
    {
        $recent = MailMessage::whereIn('source_type', ['campaign', 'sequence'])->whereIn('status', ['sent', 'failed', 'uncertain'])->latest('id')->limit(50)->get();
        if ($recent->count() < 10) {
            return false;
        }
        $failures = $recent->whereIn('status', ['failed', 'uncertain'])->count();
        if (($failures / $recent->count()) < 0.2) {
            return false;
        }

        $mailbox = MailboxSetting::current();
        if (! $mailbox->automation_paused) {
            $mailbox->update(['automation_paused' => true, 'last_connection_error' => "Automation paused after {$failures} failures or uncertain results in the latest {$recent->count()} automated messages."]);
            ActivityLog::create(['action' => 'automation:auto_paused', 'subject_type' => 'mailbox', 'subject_id' => $mailbox->id, 'metadata' => ['failures' => $failures, 'sample' => $recent->count()], 'created_at' => now()]);
        }

        return true;
    }
}
