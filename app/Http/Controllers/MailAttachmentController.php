<?php

namespace App\Http\Controllers;

use App\Models\MailAttachment;
use App\Services\Mailbox\AttachmentDownloader;
use App\Services\Mailbox\AttachmentScanner;
use Illuminate\Support\Facades\Storage;

class MailAttachmentController extends Controller
{
    public function download(MailAttachment $attachment, AttachmentDownloader $downloader, AttachmentScanner $scanner)
    {
        try {
            $path = $downloader->ensureLocal($attachment);
            $scanner->scan($attachment, $path);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return Storage::disk('local')->download($path, basename($attachment->filename), [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function preview(MailAttachment $attachment, AttachmentDownloader $downloader, AttachmentScanner $scanner)
    {
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];
        abort_unless(in_array(strtolower((string) $attachment->mime_type), $allowed, true), 415, 'Preview is not available for this file type.');
        try {
            $path = $downloader->ensureLocal($attachment);
            $scanner->scan($attachment, $path);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return Storage::disk('local')->response($path, basename($attachment->filename), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes(basename($attachment->filename)).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
