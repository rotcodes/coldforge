<?php

namespace App\Services;

use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Template;
use Illuminate\Support\Facades\URL;

/**
 * Turns a template + contact into a ready-to-send email.
 * With a CampaignSend it also adds tracking: click-proxied links, open pixel and unsubscribe footer.
 */
class EmailRenderer
{
    /** @return array{subject: string, html: string, text: ?string, unsubscribe_url: ?string} */
    public static function render(Template $template, Contact $contact, ?CampaignSend $send = null): array
    {
        $vars = $contact->templateVars();
        $unsubscribeUrl = $send ? URL::signedRoute('unsubscribe', ['contact' => $contact->id, 's' => $send->id]) : null;
        $vars['unsubscribe_url'] = $unsubscribeUrl ?? '#';

        $html = self::fill($template->html_body, $vars, escape: true);
        $text = $template->text_body ? self::fill($template->text_body, $vars) : null;

        if ($send) {
            $html = self::trackLinks($html, $send);
            $hasOwnUnsub = str_contains($template->html_body, 'unsubscribe_url');
            $html = self::appendFooter($html, $send, $hasOwnUnsub ? null : $unsubscribeUrl);
            if ($text !== null && ! str_contains($template->text_body, 'unsubscribe_url')) {
                $text .= "\n\n--\nUnsubscribe: {$unsubscribeUrl}";
            }
        }

        return [
            'subject' => self::fill($template->subject, $vars),
            'html' => $html,
            'text' => $text,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /** Replace {{var}} and {{ var }}; unknown vars become empty strings. */
    public static function fill(string $content, array $vars, bool $escape = false): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars, $escape) {
            $value = (string) ($vars[$m[1]] ?? '');

            return $escape && $m[1] !== 'unsubscribe_url' ? e($value) : $value;
        }, $content);
    }

    private static function trackLinks(string $html, CampaignSend $send): string
    {
        $skip = url('u').'/';

        return preg_replace_callback('/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i', function ($m) use ($send, $skip) {
            $target = html_entity_decode($m[2]);
            if (str_starts_with($target, $skip)) {
                return $m[0];
            }
            $tracked = URL::signedRoute('track.click', ['send' => $send->id, 'u' => rtrim(strtr(base64_encode($target), '+/', '-_'), '=')]);

            return 'href="'.e($tracked).'"';
        }, $html);
    }

    private static function appendFooter(string $html, CampaignSend $send, ?string $unsubscribeUrl): string
    {
        $pixel = '<img src="'.e(URL::signedRoute('track.open', ['send' => $send->id])).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px" />';

        $footer = '';
        if ($unsubscribeUrl) {
            $address = config('coldforge.footer_address');
            $footer = '<p style="margin-top:32px;font-size:12px;color:#888;font-family:sans-serif">'
                .($address ? e($address).'<br>' : '')
                .'Don\'t want these emails? <a href="'.e($unsubscribeUrl).'" style="color:#888">Unsubscribe</a></p>';
        }

        $insert = $footer.$pixel;

        return stripos($html, '</body>') !== false
            ? preg_replace('/<\/body>/i', $insert.'</body>', $html, 1)
            : $html.$insert;
    }
}
