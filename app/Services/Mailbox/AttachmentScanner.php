<?php

namespace App\Services\Mailbox;

use App\Models\MailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class AttachmentScanner
{
    public function scan(MailAttachment $attachment, string $path): void
    {
        if ($attachment->scan_status === 'clean') {
            return;
        }
        $absolute = Storage::disk('local')->path($path);
        $binary = config('coldforge.attachment_scanner.binary');
        if (! $binary) {
            if ((app()->isLocal() || app()->runningUnitTests()) && config('coldforge.attachment_scanner.bypass_local')) {
                $attachment->update(['scan_status' => 'bypassed', 'scanned_at' => now(), 'scan_result' => 'Local-only scan bypass was explicitly enabled.']);

                return;
            }
            $attachment->update(['scan_status' => 'unavailable', 'scanned_at' => now(), 'scan_result' => 'ClamAV is not configured.']);
            throw new \RuntimeException('Attachment download is quarantined until ClamAV scanning is configured.');
        }

        $process = new Process([$binary, '--no-summary', $absolute]);
        $process->setTimeout(120);
        $process->run();
        $result = mb_substr(trim($process->getOutput().' '.$process->getErrorOutput()), 0, 2000);
        if ($process->getExitCode() === 0) {
            $attachment->update(['scan_status' => 'clean', 'scanned_at' => now(), 'scan_result' => $result]);

            return;
        }
        if ($process->getExitCode() === 1) {
            Storage::disk('local')->delete($path);
            $attachment->update(['scan_status' => 'infected', 'scanned_at' => now(), 'scan_result' => $result, 'is_downloaded' => false, 'storage_path' => null]);
            throw new \RuntimeException('The attachment was blocked because malware was detected.');
        }

        $attachment->update(['scan_status' => 'error', 'scanned_at' => now(), 'scan_result' => $result]);
        throw new \RuntimeException('The attachment could not be scanned safely.');
    }
}
