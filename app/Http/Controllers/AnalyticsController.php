<?php

namespace App\Http\Controllers;

use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\MailMessage;
use App\Models\SequenceEnrollment;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __invoke()
    {
        $from = today()->subDays(29);
        $sent = MailMessage::where('status', 'sent')->where('sent_at', '>=', $from)->count();
        $failed = MailMessage::whereIn('status', ['failed', 'uncertain'])->where('updated_at', '>=', $from)->count();
        $replies = Contact::where('last_replied_at', '>=', $from)->count();
        $unsubscribes = CampaignSend::where('unsubscribed_at', '>=', $from)->count();

        return Inertia::render('Analytics/Index', [
            'metrics' => compact('sent', 'failed', 'replies', 'unsubscribes'),
            'daily' => $this->daily($from),
            'campaigns' => Campaign::latest()->limit(20)->get(['id', 'name', 'status', 'total_recipients', 'sent_count', 'opened_count', 'clicked_count', 'unsubscribed_count', 'bounced_count', 'created_at']),
            'pipeline' => Contact::selectRaw('pipeline_status, COUNT(*) as total')->groupBy('pipeline_status')->orderByDesc('total')->pluck('total', 'pipeline_status'),
            'sequences' => SequenceEnrollment::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'ai' => [
                'requests' => AiRequest::where('created_at', '>=', $from)->count(),
                'input_tokens' => (int) AiRequest::where('created_at', '>=', $from)->sum('input_tokens'),
                'output_tokens' => (int) AiRequest::where('created_at', '>=', $from)->sum('output_tokens'),
                'failures' => AiRequest::where('created_at', '>=', $from)->where('status', 'failed')->count(),
            ],
        ]);
    }

    private function daily(Carbon $from): array
    {
        $sent = MailMessage::selectRaw('DATE(sent_at) as day, COUNT(*) as total')->where('status', 'sent')->where('sent_at', '>=', $from)->groupBy('day')->pluck('total', 'day');
        $replies = Contact::selectRaw('DATE(last_replied_at) as day, COUNT(*) as total')->where('last_replied_at', '>=', $from)->groupBy('day')->pluck('total', 'day');

        return collect(range(0, 29))->map(function ($offset) use ($from, $sent, $replies) {
            $date = $from->copy()->addDays($offset);

            return ['date' => $date->format('M j'), 'sent' => (int) ($sent[$date->toDateString()] ?? 0), 'replies' => (int) ($replies[$date->toDateString()] ?? 0)];
        })->all();
    }
}
