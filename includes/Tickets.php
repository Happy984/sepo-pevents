<?php

declare(strict_types=1);

namespace SepoEvents;

use SepoEvents\Admin\Tools;
use SepoEvents\Tools\Qr;

defined('ABSPATH') || exit;

/**
 * Tickets with QR codes.
 *
 * A free event's ticket is ready as soon as the seat is confirmed. For a
 * paid event the ticket is issued only once a manager has marked the
 * payment as checked: then it is emailed, with a QR code for each person,
 * and shown on the event page and on a ticket page that can be printed.
 * Clearing the check takes the ticket back, and the door scanner warns
 * about anyone whose payment has not been checked.
 */
final class Tickets
{
    public static function hook(): void
    {
        add_action('template_redirect', [self::class, 'page'], 1);
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $c */
    public static function ready(array $r, array $c): bool
    {
        if (($r['status'] ?? '') !== 'going' || (string) ($r['checkin_code'] ?? '') === '') {
            return false;
        }
        return !self::waitingForPayment($r, $c);
    }

    /** A paid event whose payment has not been checked yet. @param array<string,mixed> $r @param array<string,mixed> $c */
    public static function waitingForPayment(array $r, array $c): bool
    {
        return !empty($c['is_paid']) && (int) ($r['verified_at'] ?? 0) <= 0;
    }

    /** The ticket page for one registration, reached with its private key. @param array<string,mixed> $r */
    public static function url(array $r): string
    {
        return add_query_arg(['spe_ticket' => Rsvps::key((string) $r['id']), 'event' => (int) $r['event_id']], home_url('/'));
    }

    /** What the door scanner opens: the check-in page with the event and code filled in. */
    public static function scanUrl(int $eventId, string $code): string
    {
        return Tools::url('checkin', ['event' => $eventId, 'code' => $code]);
    }

    /** Everyone on a registration who gets a code: the registrant first, then named guests. @param array<string,mixed> $r @return list<array{name:string,code:string,type:string}> */
    public static function people(array $r): array
    {
        $out = [['name' => (string) $r['name'], 'code' => (string) $r['checkin_code'], 'type' => 'primary']];
        foreach ((array) $r['attendees'] as $a) {
            if (!empty($a['code'])) {
                $out[] = ['name' => (string) ($a['name'] ?? ''), 'code' => (string) $a['code'], 'type' => 'attendee'];
            }
        }
        return array_values(array_filter($out, static fn($p) => $p['code'] !== ''));
    }

    private static function recipient(array $r): string
    {
        $to = (string) $r['guest_email'];
        if ($to === '' && (int) $r['user_id'] > 0) {
            $u = get_userdata((int) $r['user_id']);
            $to = $u ? (string) $u->user_email : '';
        }
        return is_email($to) ? $to : '';
    }

    /**
     * Email the ticket: one QR code per person, as pictures inside the
     * message, with a link to the ticket page as well. Returns the address
     * it went to, or '' when there was none or sending failed.
     *
     * @param array<string,mixed> $r @param array<string,mixed> $c
     */
    public static function send(array $r, array $c): string
    {
        $to = self::recipient($r);
        if ($to === '' || !self::ready($r, $c)) {
            return '';
        }
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $accent = (string) (Settings::get('accent') ?: '#0b3d33');
        $people = self::people($r);
        $files = [];
        $codes = '';
        foreach ($people as $i => $p) {
            $png = self::png(self::scanUrl((int) $c['id'], $p['code']));
            $cid = 'spe-qr-' . $i;
            if ($png !== '') {
                $files[] = [$png, $cid];
            }
            $codes .= '<tr><td style="padding:16px 0;border-top:1px solid #e6e9e8;text-align:center">'
                . ($png !== '' ? '<img src="cid:' . $cid . '" width="200" height="200" alt="' . esc_attr(sprintf(/* translators: %s: code */ __('QR code %s', 'sepo-events'), $p['code'])) . '" style="display:block;margin:0 auto 8px;border:0">' : '')
                . '<div style="font:700 20px/1.3 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.14em;color:#16211d">' . esc_html($p['code']) . '</div>'
                . '<div style="font:14px/1.4 Arial,sans-serif;color:#4b5563">' . esc_html($p['name'] !== '' ? $p['name'] : ($p['type'] === 'primary' ? __('Registrant', 'sepo-events') : __('Guest', 'sepo-events'))) . '</div></td></tr>';
        }
        $name = trim((string) $r['name']);
        $when = self::whenLine($c);
        $hello = $name !== '' ? sprintf(/* translators: %s: name */ __('Hello %s,', 'sepo-events'), $name) : __('Hello,', 'sepo-events');
        $intro = !empty($c['is_paid']) ? __('Your payment has been checked and your place is confirmed. Here is your ticket.', 'sepo-events') : __('Your place is confirmed. Here is your ticket.', 'sepo-events');
        $show = count($people) > 1 ? __('Each person shows their own code at the door, on a phone or printed.', 'sepo-events') : __('Show this code at the door, on your phone or printed.', 'sepo-events');
        $html = '<!doctype html><html><body style="margin:0;padding:24px 12px;background:#f3f5f4">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;border:1px solid #e1e6e4">'
            . '<tr><td style="padding:28px 28px 8px;font:15px/1.55 Arial,sans-serif;color:#16211d">'
            . '<p style="margin:0 0 6px;font:700 12px/1.3 Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:' . esc_attr($accent) . '">' . esc_html__('Your ticket', 'sepo-events') . '</p>'
            . '<h1 style="margin:0 0 6px;font:700 22px/1.25 Arial,sans-serif;color:#16211d">' . esc_html((string) $c['title']) . '</h1>'
            . ($when !== '' ? '<p style="margin:0;color:#4b5563">' . esc_html($when) . '</p>' : '')
            . ((string) $c['location'] !== '' ? '<p style="margin:0;color:#4b5563">' . esc_html((string) $c['location']) . '</p>' : '')
            . '<p style="margin:18px 0 0">' . esc_html($hello) . '</p><p style="margin:6px 0 0">' . esc_html($intro) . ' ' . esc_html($show) . '</p>'
            . '</td></tr><tr><td style="padding:8px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $codes . '</table></td></tr>'
            . '<tr><td style="padding:8px 28px 28px;font:14px/1.5 Arial,sans-serif;color:#16211d;text-align:center">'
            . '<a href="' . esc_url(self::url($r)) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:' . esc_attr($accent) . ';color:#ffffff;font-weight:700;text-decoration:none">' . esc_html__('Open or print your ticket', 'sepo-events') . '</a>'
            . '<p style="margin:16px 0 0;color:#4b5563">' . sprintf('<a href="%s" style="color:#16211d">%s</a> · <a href="%s" style="color:#16211d">%s</a>', esc_url(Mail::manageUrl($r, $c)), esc_html__('Event page', 'sepo-events'), esc_url(Event::icsUrl((int) $c['id'])), esc_html__('Add to calendar', 'sepo-events')) . '</p>'
            . '</td></tr></table><p style="margin:14px 0 0;font:12px/1.4 Arial,sans-serif;color:#6b7280">' . esc_html($site) . '</p></td></tr></table></body></html>';

        $plain = [$hello, '', $intro, '', (string) $c['title']];
        if ($when !== '') {
            $plain[] = $when;
        }
        if ((string) $c['location'] !== '') {
            $plain[] = (string) $c['location'];
        }
        $plain[] = '';
        foreach ($people as $p) {
            $plain[] = ($p['name'] !== '' ? $p['name'] . ': ' : '') . $p['code'];
        }
        /* translators: %s: link */
        $plain = array_merge($plain, ['', sprintf(__('Open or print your ticket: %s', 'sepo-events'), self::url($r)), '', $site]);

        $attach = static function ($mailer) use ($files, $plain): void {
            foreach ($files as [$file, $cid]) {
                $mailer->addEmbeddedImage($file, $cid, $cid . '.png', 'base64', 'image/png');
            }
            $mailer->AltBody = implode("\n", $plain);
        };
        add_action('phpmailer_init', $attach);
        /* translators: %s: event title */
        $ok = wp_mail($to, sprintf(__('Your ticket: %s', 'sepo-events'), (string) $c['title']), $html, ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $attach);
        foreach ($files as [$file]) {
            wp_delete_file($file);
        }
        if ($ok) {
            self::markSent((string) $r['id']);
        }
        return $ok ? $to : '';
    }

    /** When the ticket was last emailed, so managers can see it went out. */
    private static function markSent(string $id): void
    {
        $sent = get_option('sepo_events_tickets_sent', []);
        $sent = is_array($sent) ? $sent : [];
        $sent[$id] = time();
        if (count($sent) > 5000) {
            asort($sent);
            $sent = array_slice($sent, -5000, null, true);
        }
        update_option('sepo_events_tickets_sent', $sent, false);
    }

    public static function sentAt(string $id): int
    {
        $sent = get_option('sepo_events_tickets_sent', []);
        return is_array($sent) ? (int) ($sent[$id] ?? 0) : 0;
    }

    /** A PNG of the QR code in a temporary file, for email; '' when the server cannot draw images. */
    private static function png(string $text): string
    {
        if (!function_exists('imagecreate') || !function_exists('imagepng')) {
            return '';
        }
        $m = Qr::matrix($text, 'M');
        $n = count($m);
        $scale = 8;
        $size = ($n + 8) * $scale;
        $img = imagecreate($size, $size);
        if (!$img) {
            return '';
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!empty($m[$y][$x])) {
                    imagefilledrectangle($img, ($x + 4) * $scale, ($y + 4) * $scale, ($x + 5) * $scale - 1, ($y + 5) * $scale - 1, $black);
                }
            }
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = wp_tempnam('spe-qr') . '.png';
        $ok = imagepng($img, $file);
        imagedestroy($img);
        return $ok ? $file : '';
    }

    /** @param array<string,mixed> $c */
    public static function whenLine(array $c): string
    {
        if ($c['starts_utc'] === null) {
            return '';
        }
        if ($c['all_day']) {
            return Event::local($c['starts_utc'], $c['tz'], 'l, j F Y');
        }
        return Event::local($c['starts_utc'], $c['tz'], 'l, j F Y, H:i') . ($c['ends_utc'] ? '–' . Event::local($c['ends_utc'], $c['tz'], 'H:i') : '');
    }

    /** The ticket page: the event, and a QR code for each person, ready to show or print. */
    public static function page(): void
    {
        if (!isset($_GET['spe_ticket'], $_GET['event'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key in the link is the permission.
            return;
        }
        $eventId = absint(wp_unslash($_GET['event'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $key = sanitize_text_field(wp_unslash((string) $_GET['spe_ticket'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $r = Rsvps::fromKey($eventId, $key);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        $post = get_post($eventId);
        if (!$r || !$post || $post->post_type !== PostType::TYPE) {
            self::shell(__('Ticket not found', 'sepo-events'), '<div class="t-note">' . esc_html__('This ticket link is not valid. Open the link in your email again, or ask the organiser.', 'sepo-events') . '</div>', 404);
        }
        $c = Event::config($post);
        if (!self::ready($r, $c)) {
            $why = $r['status'] === 'cancelled' ? __('This registration was cancelled.', 'sepo-events')
                : ($r['status'] !== 'going' ? __('You are on the waitlist. Your ticket will be emailed to you when a seat opens up.', 'sepo-events')
                : __('Your ticket will be ready once your payment has been checked. We will email it to you then.', 'sepo-events'));
            self::shell((string) $c['title'], '<h1>' . esc_html((string) $c['title']) . '</h1><div class="t-note">' . esc_html($why) . '</div><p class="t-links"><a href="' . esc_url(Mail::manageUrl($r, $c)) . '">' . esc_html__('Event page', 'sepo-events') . '</a></p>', 200);
        }
        $when = self::whenLine($c);
        $cards = '';
        foreach (self::people($r) as $p) {
            $cards .= '<section class="t-card"><div class="t-qr">' . Qr::svg(self::scanUrl((int) $c['id'], $p['code']), 220, 'M', sprintf(/* translators: %s: code */ __('QR code %s', 'sepo-events'), $p['code'])) . '</div>'
                . '<p class="t-code">' . esc_html($p['code']) . '</p>'
                . '<p class="t-name">' . esc_html($p['name'] !== '' ? $p['name'] : ($p['type'] === 'primary' ? __('Registrant', 'sepo-events') : __('Guest', 'sepo-events'))) . '</p>'
                . '<p class="t-role">' . esc_html($p['type'] === 'primary' ? __('Registrant', 'sepo-events') : __('Guest', 'sepo-events')) . '</p></section>';
        }
        $body = '<p class="t-kicker">' . esc_html__('Ticket', 'sepo-events') . '</p><h1>' . esc_html((string) $c['title']) . '</h1>'
            . ($when !== '' ? '<p class="t-meta">' . esc_html($when) . '</p>' : '')
            . ((string) $c['location'] !== '' ? '<p class="t-meta">' . esc_html((string) $c['location']) . '</p>' : '')
            . (!empty($c['is_paid']) ? '<p class="t-paid">' . esc_html__('Payment checked', 'sepo-events') . '</p>' : '')
            . '<div class="t-cards">' . $cards . '</div>'
            . '<p class="t-help">' . esc_html__('Show the code at the door, on your phone or printed. Each person has their own code.', 'sepo-events') . '</p>'
            . '<p class="t-links"><button type="button" id="t-print">' . esc_html__('Print or save as PDF', 'sepo-events') . '</button> <a href="' . esc_url(Mail::manageUrl($r, $c)) . '">' . esc_html__('Event page', 'sepo-events') . '</a> <a href="' . esc_url(Event::icsUrl((int) $c['id'])) . '">' . esc_html__('Add to calendar', 'sepo-events') . '</a></p>';
        self::shell((string) $c['title'], $body, 200);
    }

    private static function shell(string $title, string $body, int $status): void
    {
        status_header($status);
        header('Content-Type: text/html; charset=utf-8');
        $accent = (string) (Settings::get('accent') ?: '#0b3d33');
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html($title . ' — ' . __('Ticket', 'sepo-events')) . '</title>'
            . '<style>
:root{--a:' . esc_attr($accent) . ';--ink:#16211d;--muted:#4b5563;--line:#e1e6e4;--bg:#f3f5f4;--card:#fff}
@media (prefers-color-scheme:dark){:root{--ink:#eef2f0;--muted:#c5ccc9;--line:#2f3a36;--bg:#111816;--card:#18211e}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:640px;margin:0 auto;padding:32px 16px 48px;text-align:center}
.t-kicker{margin:0 0 4px;color:var(--a);font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}
@media (prefers-color-scheme:dark){.t-kicker{color:color-mix(in srgb,var(--a) 45%,#fff)}}
h1{margin:0 0 6px;font-size:26px;line-height:1.2}.t-meta{margin:0;color:var(--muted)}
.t-paid{display:inline-block;margin:12px 0 0;padding:4px 12px;border-radius:999px;background:color-mix(in srgb,#1f8a4c 16%,transparent);color:#1f7a45;font-size:13px;font-weight:700}
@media (prefers-color-scheme:dark){.t-paid{color:#8fe0b0}}
.t-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:24px 0 8px}
.t-card{padding:20px;border:1px solid var(--line);border-radius:16px;background:var(--card);break-inside:avoid}
.t-qr{display:inline-block;padding:8px;background:#fff;border-radius:10px;line-height:0}.t-qr svg{max-width:100%;height:auto}
.t-code{margin:10px 0 0;font:700 22px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.14em}
.t-name{margin:4px 0 0;font-weight:650}.t-role{margin:2px 0 0;color:var(--muted);font-size:13px}
.t-help{color:var(--muted);font-size:14px}.t-note{margin:20px 0;padding:16px;border-radius:12px;background:color-mix(in srgb,#d69e2e 16%,transparent)}
.t-links{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;align-items:center;margin-top:18px}
.t-links a,.t-links button{display:inline-flex;align-items:center;min-height:42px;padding:0 18px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);font:600 14px system-ui,sans-serif;text-decoration:none;cursor:pointer}
.t-links button{background:var(--a);border-color:var(--a);color:#fff}
a:focus-visible,button:focus-visible{outline:3px solid var(--a);outline-offset:2px}
@media print{body{background:#fff;color:#000}.t-links,.t-help{display:none}.t-card{border-color:#999}}
</style></head><body><main>' . $body . '</main><script>var b=document.getElementById("t-print");if(b){b.addEventListener("click",function(){window.print();});}</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        exit;
    }
}
