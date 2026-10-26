<?php

declare(strict_types=1);

namespace SepoEvents\Tools;

use SepoEvents\Admin\Tools;
use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\Rsvps;

defined('ABSPATH') || exit;

/**
 * Full HTML pages that stand outside the theme and wp-admin: the door
 * check-in scanner, printable attendee cards, and the printable tables
 * behind "Export PDF". Printing and saving as PDF are the browser's job.
 */
final class Pages
{
    private static function e(string $v): string
    {
        return esc_html($v);
    }

    /** The language attribute and favicon every page shares. */
    private static function head(string $title): string
    {
        $icon = get_site_icon_url(64);
        return '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title>' . ($icon ? '<link rel="icon" href="' . esc_url($icon) . '">' : '');
    }

    // ------------------------------------------------------------ check-in

    /**
     * Two modes from one toggle: Scan (the camera reads cards) and Manual
     * (type a code, email or phone). Both send the same request; the store
     * tries the value as a code, then an email, then a phone number.
     *
     * Reading QR codes uses the browser's built-in BarcodeDetector. Where a
     * browser has none, any phone's own camera app still works: a card's
     * code is a link to this page with the guest filled in.
     *
     * @param array{api:string,nonce:string,events:list<array{id:int,title:string,when:string}>,event:int,code:string,back:string} $ctx
     */
    public static function checkin(array $ctx): string
    {
        $options = '';
        foreach ($ctx['events'] as $ev) {
            $options .= '<option value="' . (int) $ev['id'] . '"' . selected((int) $ev['id'], $ctx['event'], false) . '>' . esc_html($ev['title'] . ($ev['when'] !== '' ? ' — ' . $ev['when'] : '')) . '</option>';
        }
        $cfg = wp_json_encode([
            'api'   => untrailingslashit($ctx['api']),
            'nonce' => $ctx['nonce'],
            'code'  => $ctx['code'],
            'i18n'  => [
                'starting'    => __('Starting camera…', 'sepo-events'),
                'scanning'    => __('Scanning… hold a card inside the frame.', 'sepo-events'),
                'noCamera'    => __('Camera unavailable — use Manual.', 'sepo-events'),
                'noDetector'  => __('This browser cannot read QR codes from the camera. Point your phone\'s own camera app at the card instead — it opens this page with the guest filled in — or use Manual.', 'sepo-events'),
                'missing'     => __('Missing details', 'sepo-events'),
                'missingBody' => __('Choose an event and enter a code, email or phone.', 'sepo-events'),
                'incomplete'  => __('Incomplete', 'sepo-events'),
                'notIn'       => __('Not checked in', 'sepo-events'),
                'error'       => __('Error', 'sepo-events'),
                'noReply'     => __('No response', 'sepo-events'),
                'noReplyBody' => __('The site did not return a usable reply.', 'sepo-events'),
                'network'     => __('Network error', 'sepo-events'),
                'networkBody' => __('Could not reach the site.', 'sepo-events'),
                'noName'      => __('(no name on file)', 'sepo-events'),
                'already'     => __('Already checked in', 'sepo-events'),
                'checkedIn'   => __('Checked in', 'sepo-events'),
                'waitlist'    => __('On the waitlist — checked in', 'sepo-events'),
                'alreadyLog'  => __('%s — already in', 'sepo-events'),
                'inLog'       => __('%s — checked in', 'sepo-events'),
                'guest'       => __('Guest', 'sepo-events'),
                'byEmail'     => __('found by email', 'sepo-events'),
                'byPhone'     => __('found by phone', 'sepo-events'),
                'at'          => __('at %s', 'sepo-events'),
            ],
        ]);
        $t = static fn(string $s): string => esc_html($s);

        return self::head(__('Event check-in', 'sepo-events')) . '<style>' . self::checkinCss() . '</style></head><body>'
            . '<div class="ck-page">'
            . '<div class="ck-head"><h1>' . $t(__('Event check-in', 'sepo-events')) . '</h1><p class="sub">' . $t(__('Scan a card, or look someone up by their code, email or phone.', 'sepo-events')) . '</p>'
            . '<a class="ck-back" href="' . esc_url($ctx['back']) . '">' . $t(__('← RSVP dashboard', 'sepo-events')) . '</a></div>'
            . '<div class="ck-grid">'
            . '<div class="ck-scan-col" id="scanCol"><div class="scan-wrap"><video id="video" playsinline muted></video><div class="scan-frame"></div><p class="scan-note" id="scanNote" hidden></p></div>'
            . '<p class="scan-hint" id="camHint">' . $t(__('Starting camera…', 'sepo-events')) . '</p></div>'
            . '<div class="ck-side-col">'
            . '<div class="ck-toggle" role="tablist" aria-label="' . esc_attr__('Check-in mode', 'sepo-events') . '">'
            . '<button type="button" id="modeScan" class="is-on" role="tab" aria-selected="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="3"></rect><circle cx="12" cy="13" r="3.5"></circle></svg>' . $t(__('Scan', 'sepo-events')) . '</button>'
            . '<button type="button" id="modeManual" role="tab" aria-selected="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path></svg>' . $t(__('Manual', 'sepo-events')) . '</button>'
            . '</div>'
            . '<div class="manual">'
            . '<div><label for="eventInput">' . $t(__('Event', 'sepo-events')) . '</label><select id="eventInput">' . ($options !== '' ? $options : '<option value="">' . $t(__('No events yet', 'sepo-events')) . '</option>') . '</select>'
            . '<p class="hint">' . $t(__('Scanned cards switch to their own event automatically.', 'sepo-events')) . '</p></div>'
            . '<div><label for="codeInput">' . $t(__('Code, email or phone', 'sepo-events')) . '</label>'
            . '<div class="row"><input type="text" id="codeInput" placeholder="' . esc_attr__('Card code, email, or phone number', 'sepo-events') . '" value="' . esc_attr($ctx['code']) . '" autocomplete="off" autocapitalize="characters" spellcheck="false">'
            . '<button type="button" class="primary" id="manualBtn">' . $t(__('Check in', 'sepo-events')) . '</button></div>'
            . '<p class="hint">' . $t(__('Any of the three works. The card\'s code is the fastest; the email or the number the guest registered with will also find them.', 'sepo-events')) . '</p></div>'
            . '</div>'
            . '<div class="result" id="result" role="status" aria-live="polite"><p class="name" id="resName"></p><p class="meta" id="resMeta"></p><p class="meta" id="resEvent"></p><span class="tag" id="resTag"></span></div>'
            . '<div class="log"><h2>' . $t(__('Recent scans', 'sepo-events')) . '</h2><ul id="logList"></ul></div>'
            . '</div></div></div>'
            . '<script>window.SepoCheckin=' . $cfg . ';</script>'
            . '<script src="' . esc_url(SPE_URL . 'assets/js/checkin.js?ver=' . rawurlencode(Plugin::assetVersion('assets/js/checkin.js'))) . '"></script>'
            . '</body></html>';
    }

    private static function checkinCss(): string
    {
        return <<<'CSS'
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f1419;color:#e8ecef;min-height:100vh;padding:20px 16px 60px}
.ck-page{width:100%;max-width:980px;margin:0 auto}
.ck-head{text-align:center;margin:0 0 22px;position:relative}
h1{font-size:1.05rem;margin:0 0 4px}
p.sub{margin:0;color:#9aa4ad;font-size:.85rem}
.ck-back{display:inline-block;margin-top:8px;color:#9aa4ad;font-size:.8rem;text-decoration:none}
.ck-back:hover{color:#e8ecef}
.ck-grid{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:28px;align-items:flex-start}
@media (max-width:900px){.ck-grid{grid-template-columns:1fr;gap:20px}}
.ck-grid.is-manual{grid-template-columns:minmax(0,560px);justify-content:center}
.ck-scan-col{display:flex;flex-direction:column;align-items:center}
.ck-scan-col[hidden]{display:none}
.ck-side-col{display:flex;flex-direction:column}
.ck-side-col>*{margin-bottom:22px}
.ck-side-col>*:last-child{margin-bottom:0}
.scan-wrap{width:100%;max-width:420px;position:relative;border-radius:16px;overflow:hidden;background:#000;aspect-ratio:1/1}
video{width:100%;height:100%;object-fit:cover;display:block}
.scan-frame{position:absolute;inset:12%;border:3px solid rgba(255,255,255,.6);border-radius:16px;pointer-events:none}
.scan-note{position:absolute;inset:0;margin:0;display:flex;align-items:center;justify-content:center;padding:28px;text-align:center;font-size:.9rem;line-height:1.55;color:#c3cad0;background:#171d24}
.scan-hint{margin-top:10px;font-size:.8rem;color:#9aa4ad;text-align:center}
.ck-toggle{display:inline-flex;padding:4px;border-radius:12px;background:#171d24;border:1px solid #2a333b;align-self:flex-start}
.ck-toggle button{font:inherit;font-size:.85rem;font-weight:650;padding:.5rem 1rem;border-radius:9px;border:none;background:transparent;color:#9aa4ad;cursor:pointer;display:inline-flex;align-items:center;gap:.4rem;transition:background .15s,color .15s}
.ck-toggle button:hover{color:#e8ecef}
.ck-toggle button.is-on{background:#0b3d33;color:#fff}
.ck-toggle button svg{width:15px;height:15px;display:block}
.manual{width:100%;display:flex;flex-direction:column;gap:10px}
.manual label{display:block;margin-bottom:4px;font-size:.78rem;color:#9aa4ad;text-transform:uppercase;letter-spacing:.05em}
.manual .hint{font-size:.72rem;color:#6f7885;margin:.3rem 0 0;line-height:1.4}
.row{display:flex;gap:8px}
input[type=text],select{flex:1;width:100%;font:inherit;font-size:.95rem;padding:.65rem .8rem;border-radius:10px;border:1px solid #2a333b;background:#171d24;color:#e8ecef;min-width:0}
input[type=text]:focus,select:focus{outline:2px solid #2ea08a;outline-offset:1px}
button.primary{font:inherit;font-size:.9rem;font-weight:650;padding:.65rem 1.1rem;border-radius:10px;border:none;background:#0b3d33;color:#fff;cursor:pointer;white-space:nowrap}
button.primary:hover{filter:brightness(1.15)}
button.primary:disabled{opacity:.5;cursor:not-allowed}
button:focus-visible{outline:2px solid #2ea08a;outline-offset:2px}
.result{width:100%;border-radius:14px;padding:16px 18px;display:none}
.result.show{display:block}
.result.ok{background:rgba(46,160,67,.16);border:1px solid #2ea043}
.result.warn{background:rgba(214,158,46,.16);border:1px solid #d69e2e}
.result.err{background:rgba(161,18,18,.2);border:1px solid #c94848}
.result .name{font-size:1.15rem;font-weight:700;margin:0 0 2px}
.result .meta{font-size:.85rem;color:#c3cad0;margin:0 0 2px}
.result .tag{display:inline-block;margin-top:8px;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 9px;border-radius:999px;background:rgba(255,255,255,.12)}
.log{width:100%}
.log h2{font-size:.78rem;color:#9aa4ad;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px}
.log ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}
.log li{font-size:.82rem;padding:8px 10px;border-radius:9px;background:#171d24;display:flex;justify-content:space-between;gap:10px}
.log li b{font-weight:650}
.log li span{color:#9aa4ad;white-space:nowrap}
CSS;
    }

    // ------------------------------------------------------------ cards

    /**
     * One badge per named person, sized 4in × 6in, one per printed page.
     * The QR code is drawn here as SVG and opens the check-in page with the
     * event and the person's code filled in.
     *
     * @param list<array{name:string,email:string,phone:string,type:string,code:string,status:string}> $people
     * @param array<string,mixed> $c
     */
    public static function cards(array $people, array $c): string
    {
        $title = (string) $c['title'];
        $when = $c['starts_utc'] !== null ? Event::local($c['starts_utc'], $c['tz'], $c['all_day'] ? 'D j M Y' : 'D j M Y, H:i') : '';
        if ($people === []) {
            return self::head(__('Attendee cards', 'sepo-events')) . '</head><body style="font:15px/1.5 sans-serif;padding:40px;text-align:center;color:#16211d">'
                . esc_html__('No one with a name and a confirmed seat to print a card for yet.', 'sepo-events') . '</body></html>';
        }
        $cards = '';
        foreach ($people as $p) {
            $url = Tools::url('checkin', ['event' => (int) $c['id'], 'code' => $p['code']]);
            $cards .= '<div class="badge">'
                . '<div class="badge-head"><div class="badge-event">' . esc_html($title) . '</div>'
                . '<span class="role">' . esc_html($p['type'] === 'primary' ? __('Registrant', 'sepo-events') : __('Guest', 'sepo-events')) . '</span>'
                . ($p['status'] === 'waitlist' ? '<span class="role role--wait">' . esc_html__('Waitlist', 'sepo-events') . '</span>' : '') . '</div>'
                . '<div class="badge-name">' . esc_html($p['name'] !== '' ? $p['name'] : __('Guest', 'sepo-events')) . '</div>'
                . '<div class="badge-contact">' . esc_html($p['email']) . ($p['email'] !== '' && $p['phone'] !== '' ? '<br>' : '') . esc_html($p['phone']) . '</div>'
                . '<div class="badge-qr">' . Qr::svg($url, 200, 'M', __('Check-in code', 'sepo-events')) . '<div class="badge-code">' . esc_html($p['code']) . '</div></div>'
                . '<div class="badge-foot">' . esc_html($when) . ($when !== '' && $c['location'] !== '' ? '<br>' : '') . esc_html((string) $c['location']) . '</div>'
                . '</div>';
        }
        $count = count($people);
        /* translators: %d: number of cards */
        $label = sprintf(_n('%d card', '%d cards', $count, 'sepo-events'), $count);
        $accent = (string) (\SepoEvents\Settings::get('accent') ?: '#0b3d33');

        return self::head(sprintf(/* translators: %s: event title */ __('Attendee cards — %s', 'sepo-events'), $title))
            . '<style>
@page{size:4in 6in;margin:0}
*{box-sizing:border-box}
body{margin:0;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#fafafa;color:#16211d}
.toolbar{position:sticky;top:0;z-index:5;background:#16211d;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.toolbar button{font:inherit;font-size:.85rem;font-weight:650;padding:.5rem 1rem;border-radius:8px;border:none;background:' . $accent . ';color:#fff;cursor:pointer}
.toolbar button:hover{filter:brightness(1.2)}
.badge{width:4in;height:6in;padding:.4in;margin:24px auto;background:#fff;border:1px solid #ddd;border-radius:14px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.16in;page-break-after:always;break-after:page;overflow:hidden}
.badge-head{width:100%;display:flex;flex-direction:column;align-items:center;gap:.08in}
.badge-event{font-size:1rem;font-weight:700;color:' . $accent . ';text-transform:uppercase;letter-spacing:.03em}
.role{font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;border:1px solid #ccc;border-radius:999px;padding:2px 10px}
.role--wait{color:#7a5300;border-color:#d69e2e}
.badge-name{font-size:1.6rem;font-weight:800;line-height:1.15;word-break:break-word}
.badge-contact{font-size:.85rem;color:#4b5563;word-break:break-word}
.badge-qr svg{display:block;margin:0 auto}
.badge-code{margin-top:4px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.9rem;font-weight:700;letter-spacing:.14em}
.badge-foot{margin-top:auto;font-size:.78rem;color:#6b7280;border-top:1px solid #eee;padding-top:.12in;width:100%}
@media print{.toolbar{display:none}body{background:#fff}.badge{margin:0;border:none;border-radius:0}}
@media screen and (max-width:440px){.badge{width:100%;height:auto;min-height:6in;margin:16px 0}}
</style></head><body>'
            . '<div class="toolbar"><b>' . esc_html($title . ' — ' . $label) . '</b><button type="button" id="printBtn">' . esc_html__('Print / Save as PDF', 'sepo-events') . '</button></div>'
            . $cards
            . '<script>document.getElementById("printBtn").addEventListener("click",function(){window.print();});</script>'
            . '</body></html>';
    }

    // ------------------------------------------------------------ print tables

    private static function printShell(string $title, string $subtitle, string $body): string
    {
        return self::head($title) . '<style>
@page{size:A4 landscape;margin:14mm}
*{box-sizing:border-box}
body{margin:0;padding:16px;font:12px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#16211d}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.toolbar button{font:inherit;font-size:.85rem;font-weight:650;padding:.5rem 1rem;border-radius:8px;border:none;background:#0b3d33;color:#fff;cursor:pointer}
h1{font-size:1.1rem;margin:0}
p.sub{margin:2px 0 0;color:#6b7280;font-size:.82rem}
.scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.78rem}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #ddd;vertical-align:top}
th{background:#f3f4f6;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:#4b5563}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
tr:nth-child(even) td{background:#fafafa}
@media print{.toolbar button{display:none}body{padding:0}.scroll{overflow:visible}}
</style></head><body>'
            . '<div class="toolbar"><div><h1>' . esc_html($title) . '</h1><p class="sub">' . esc_html($subtitle) . '</p></div>'
            . '<button type="button" id="printBtn">' . esc_html__('Print / Save as PDF', 'sepo-events') . '</button></div>'
            . '<div class="scroll">' . $body . '</div>'
            . '<script>document.getElementById("printBtn").addEventListener("click",function(){window.print();});</script>'
            . '</body></html>';
    }

    /** @param list<array<string,mixed>> $events */
    public static function printEvents(array $events): string
    {
        $rows = '';
        foreach ($events as $e) {
            $cap = (int) $e['capacity'];
            $rows .= '<tr><td>' . esc_html((string) $e['title']) . '</td>'
                . '<td>' . esc_html($e['starts'] !== null ? Event::local((int) $e['starts'], (string) $e['tz'], 'j M Y, H:i') : '—') . '</td>'
                . '<td>' . esc_html((string) $e['location']) . '</td>'
                . '<td class="num">' . ($cap > 0 ? $cap : '∞') . '</td>'
                . '<td class="num">' . (int) $e['seats_taken'] . '</td>'
                . '<td class="num">' . ($cap > 0 ? (int) $e['seats_left'] : '—') . '</td>'
                . '<td class="num">' . (int) $e['going'] . '</td>'
                . '<td class="num">' . (int) $e['waitlist'] . '</td>'
                . '<td class="num">' . (int) $e['cancelled'] . '</td>'
                . '<td class="num">' . (int) $e['checked_in'] . '</td>'
                . '<td class="num">' . ((float) $e['collected'] > 0 ? esc_html(Event::money((float) $e['collected'], (string) $e['currency'])) : '—') . '</td></tr>';
        }
        $head = '<tr><th>' . self::e(__('Event', 'sepo-events')) . '</th><th>' . self::e(__('When', 'sepo-events')) . '</th><th>' . self::e(__('Location', 'sepo-events')) . '</th>'
            . '<th class="num">' . self::e(__('Capacity', 'sepo-events')) . '</th><th class="num">' . self::e(__('Seats taken', 'sepo-events')) . '</th><th class="num">' . self::e(__('Seats left', 'sepo-events')) . '</th>'
            . '<th class="num">' . self::e(__('Going', 'sepo-events')) . '</th><th class="num">' . self::e(__('Waitlist', 'sepo-events')) . '</th><th class="num">' . self::e(__('Cancelled', 'sepo-events')) . '</th>'
            . '<th class="num">' . self::e(__('Checked in', 'sepo-events')) . '</th><th class="num">' . self::e(__('Collected', 'sepo-events')) . '</th></tr>';
        $body = '<table><thead>' . $head . '</thead><tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="11">' . self::e(__('No events.', 'sepo-events')) . '</td></tr>') . '</tbody></table>';
        /* translators: 1: number of events, 2: date and time */
        $sub = sprintf(_n('%1$d event — generated %2$s', '%1$d events — generated %2$s', count($events), 'sepo-events'), count($events), wp_date('j M Y, H:i'));
        return self::printShell(__('Events', 'sepo-events'), $sub, $body);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function printRsvps(array $rows, string $subtitle): string
    {
        $body = '';
        foreach ($rows as $r) {
            $att = [];
            foreach ($r['attendees'] as $a) {
                $att[] = trim((string) ($a['name'] ?? ''));
            }
            $money = Rsvps::money($r);
            $body .= '<tr><td>' . esc_html((string) $r['event_title']) . '</td>'
                . '<td>' . esc_html($r['name'] !== '' ? (string) $r['name'] : '—') . '</td>'
                . '<td>' . esc_html((string) $r['guest_email']) . '</td>'
                . '<td>' . esc_html((string) $r['phone']) . '</td>'
                . '<td class="num">' . (int) $r['guests'] . '</td>'
                . '<td>' . esc_html((string) $r['status']) . '</td>'
                . '<td>' . esc_html($r['checked_in'] ? __('Yes', 'sepo-events') : __('No', 'sepo-events')) . '</td>'
                . '<td class="num">' . ($money > 0 ? esc_html(Event::money($money, (string) $r['currency'])) : '—') . '</td>'
                . '<td>' . esc_html(implode(', ', array_filter($att))) . '</td></tr>';
        }
        $head = '<tr><th>' . self::e(__('Event', 'sepo-events')) . '</th><th>' . self::e(__('Name', 'sepo-events')) . '</th><th>' . self::e(__('Email', 'sepo-events')) . '</th><th>' . self::e(__('Phone', 'sepo-events')) . '</th>'
            . '<th class="num">' . self::e(__('Seats', 'sepo-events')) . '</th><th>' . self::e(__('Status', 'sepo-events')) . '</th><th>' . self::e(__('Checked in', 'sepo-events')) . '</th>'
            . '<th class="num">' . self::e(__('Paid', 'sepo-events')) . '</th><th>' . self::e(__('Attendees', 'sepo-events')) . '</th></tr>';
        $table = '<table><thead>' . $head . '</thead><tbody>' . ($body !== '' ? $body : '<tr><td colspan="9">' . self::e(__('No RSVPs.', 'sepo-events')) . '</td></tr>') . '</tbody></table>';
        return self::printShell(__('RSVPs', 'sepo-events'), $subtitle, $table);
    }
}
