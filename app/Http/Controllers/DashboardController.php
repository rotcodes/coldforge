<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Template;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $sent = $this->daily('sent_at');
        $bounced = $this->daily('sent_at', fn ($q) => $q->where('status', 'bounced'));
        $opens = $this->daily('opened_at');
        $clicks = $this->daily('first_clicked_at');

        $last7 = fn (array $series) => array_slice($series, -7);
        $sum7 = fn (array $series) => array_sum(array_column($last7($series), 'value'));

        $sent7 = $sum7($sent);
        $delivered = array_map(fn ($s, $b) => ['date' => $s['date'], 'value' => $s['value'] - $b['value']], $sent, $bounced);
        $rate = fn (int $n) => $sent7 > 0 ? round($n / $sent7 * 100, 1) : 0;

        return Inertia::render('Dashboard', [
            'stats' => [
                ['label' => 'Sent', 'value' => $sent7, 'suffix' => '', 'spark' => $last7($sent)],
                ['label' => 'Delivered', 'value' => $sum7($delivered), 'suffix' => '', 'spark' => $last7($delivered)],
                ['label' => 'Open rate', 'value' => $rate($sum7($opens)), 'suffix' => '%', 'spark' => $last7($opens)],
                ['label' => 'Click rate', 'value' => $rate($sum7($clicks)), 'suffix' => '%', 'spark' => $last7($clicks)],
            ],
            'chart' => $sent,
            'campaigns' => Campaign::with('template:id,name')->latest()->limit(5)->get(),
            'templates' => Template::orderBy('name')->get(['id', 'name']),
            'contactCount' => Contact::where('status', 'active')->count(),
            'readiness' => $this->readiness(),
        ]);
    }

    /** Aggregate counts for the dashboard's readiness tile. One scan of active contacts. */
    private function readiness(): array
    {
        $tiers = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $unmapped = 0;

        Contact::where('status', 'active')
            ->whereNotNull('email')->where('email', '!=', '')
            ->select('id', 'industry', 'industry_slug', 'business', 'company', 'personalized_opener', 'pain_evidence', 'recommended_offer', 'email')
            ->chunkById(2000, function ($rows) use (&$tiers, &$unmapped) {
                foreach ($rows as $r) {
                    $tier = $r->readinessTier();
                    if (isset($tiers[$tier])) {
                        $tiers[$tier]++;
                    }
                    if ($r->industry && ! $r->industry_slug) {
                        $unmapped++;
                    }
                }
            }, 'id');

        return ['tiers' => $tiers, 'unmapped' => $unmapped];
    }

    /** Count of sends per day for the last 30 days on a timestamp column, zero-filled. */
    private function daily(string $column, ?callable $scope = null): array
    {
        $from = today()->subDays(29);
        $rows = CampaignSend::query()
            ->selectRaw("DATE($column) as d, COUNT(*) as c")
            ->where($column, '>=', $from)
            ->when($scope, $scope)
            ->groupBy('d')
            ->pluck('c', 'd');

        return collect(range(0, 29))->map(function ($i) use ($from, $rows) {
            $date = $from->copy()->addDays($i)->toDateString();

            return ['date' => Carbon::parse($date)->format('M j'), 'value' => (int) ($rows[$date] ?? 0)];
        })->all();
    }
}
