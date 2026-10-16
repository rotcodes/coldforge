<?php

namespace App\Http\Controllers;

use App\Models\CampaignSend;
use App\Models\Contact;
use Illuminate\Http\Request;

/** Public, signed endpoints hit from inside sent emails. */
class TrackingController extends Controller
{
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, CampaignSend $send)
    {
        $this->markOpened($send);
        $send->log('opened', [], $request->ip(), $request->userAgent());

        return response(base64_decode(self::GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, CampaignSend $send)
    {
        $url = base64_decode(strtr((string) $request->query('u'), '-_', '+/'), true);
        abort_unless($url && preg_match('#^https?://#i', $url), 404);

        $this->markOpened($send); // a click proves an open even if images were blocked
        if (! $send->first_clicked_at) {
            $send->first_clicked_at = now();
            $send->campaign()->increment('clicked_count');
        }
        $send->clicked_count++;
        $send->save();
        $send->log('clicked', ['url' => $url], $request->ip(), $request->userAgent());

        return redirect()->away($url);
    }

    public function unsubscribe(Request $request, Contact $contact)
    {
        if ($contact->status === 'active') {
            $contact->update(['status' => 'unsubscribed']);
        }

        $send = CampaignSend::where('id', $request->query('s'))->where('contact_id', $contact->id)->first();
        if ($send && ! $send->unsubscribed_at) {
            $send->update(['unsubscribed_at' => now()]);
            $send->campaign()->increment('unsubscribed_count');
            $send->log('unsubscribed', [], $request->ip(), $request->userAgent());
        }

        return $request->isMethod('post') ? response('', 200) : view('unsubscribed', ['email' => $contact->email]);
    }

    private function markOpened(CampaignSend $send): void
    {
        if (! $send->opened_at) {
            $send->update(['opened_at' => now()]);
            $send->campaign()->increment('opened_count');
        }
    }
}
