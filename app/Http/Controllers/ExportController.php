<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function contacts(Request $request): StreamedResponse
    {
        $query = Contact::query()
            ->when($request->q, fn ($q, $value) => $q->where(fn ($q) => $q->where('email', 'like', "%{$value}%")->orWhere('company', 'like', "%{$value}%")))
            ->when($request->status, fn ($q, $value) => $q->where('status', $value))
            ->when($request->permission_status, fn ($q, $value) => $q->where('permission_status', $value))
            ->when($request->pipeline_status, fn ($q, $value) => $q->where('pipeline_status', $value));

        return $this->csv('contacts-'.now()->format('Ymd-His').'.csv', [
            'Email', 'First name', 'Last name', 'Company', 'Pipeline', 'Permission', 'Consent source', 'Consent evidence', 'Status', 'Tags', 'Last contacted', 'Last replied', 'Notes',
        ], function ($output) use ($query) {
            $query->orderBy('id')->chunkById(500, function ($contacts) use ($output) {
                foreach ($contacts as $contact) {
                    fputcsv($output, array_map([$this, 'safe'], [
                        $contact->email, $contact->first_name, $contact->last_name, $contact->business ?: $contact->company,
                        $contact->pipeline_status, $contact->permission_status, $contact->consent_source, $contact->consent_evidence,
                        $contact->status, implode(';', $contact->tags ?? []), $contact->last_contacted_at, $contact->last_replied_at, $contact->notes,
                    ]));
                }
            });
        });
    }

    public function campaign(Campaign $campaign): StreamedResponse
    {
        $query = $campaign->sends()->with(['contact', 'sequenceEnrollment']);

        return $this->csv('campaign-'.$campaign->id.'-'.now()->format('Ymd-His').'.csv', [
            'Email', 'Permission', 'Consent source', 'Delivery status', 'Sent at', 'Opened at', 'First clicked at', 'Clicks', 'Unsubscribed at', 'Error', 'Sequence status', 'Next follow-up',
        ], function ($output) use ($query) {
            $query->orderBy('id')->chunkById(500, function ($sends) use ($output) {
                foreach ($sends as $send) {
                    fputcsv($output, array_map([$this, 'safe'], [
                        $send->contact->email, $send->contact->permission_status, $send->contact->consent_source, $send->status,
                        $send->sent_at, $send->opened_at, $send->first_clicked_at, $send->clicked_count, $send->unsubscribed_at,
                        $send->error, $send->sequenceEnrollment?->status, $send->sequenceEnrollment?->next_send_at,
                    ]));
                }
            });
        });
    }

    public function suppressions(): StreamedResponse
    {
        return $this->csv('suppressions-'.now()->format('Ymd-His').'.csv', ['Email', 'Reason', 'Source', 'Evidence', 'Suppressed at'], function ($output) {
            Suppression::orderBy('id')->chunkById(500, function ($rows) use ($output) {
                foreach ($rows as $row) {
                    fputcsv($output, array_map([$this, 'safe'], [$row->email, $row->reason, $row->source, $row->evidence, $row->suppressed_at]));
                }
            });
        });
    }

    private function csv(string $filename, array $headers, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writer) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            $writer($output);
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}
