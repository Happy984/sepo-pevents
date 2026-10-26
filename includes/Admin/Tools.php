<?php

declare(strict_types=1);

namespace SepoEvents\Admin;

use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\PostType;
use SepoEvents\Reports;
use SepoEvents\Rsvps;
use SepoEvents\Tools\Pages;

defined('ABSPATH') || exit;

/**
 * The manager tools that are pages of their own, served through
 * admin-post.php so WordPress handles sign-in:
 *
 *   ?action=sepo_events_checkin   the door check-in scanner
 *   ?action=sepo_events_cards     printable attendee cards with QR codes
 *   ?action=sepo_events_print     printable events or RSVPs table ("Export PDF")
 *   ?action=sepo_events_export    CSV download (needs a nonce)
 *
 * A card's QR code opens the check-in page with the event and code filled
 * in. Someone not signed in is sent to the login screen and brought back.
 */
final class Tools
{
    private const TOOLS = ['checkin', 'cards', 'print', 'export'];

    public static function hook(): void
    {
        foreach (self::TOOLS as $tool) {
            add_action('admin_post_sepo_events_' . $tool, [self::class, $tool]);
            add_action('admin_post_nopriv_sepo_events_' . $tool, [self::class, 'login']);
        }
    }

    /** @param array<string,int|string> $args */
    public static function url(string $tool, array $args = []): string
    {
        $args = array_merge(['action' => 'sepo_events_' . $tool], $args);
        if ($tool === 'export') {
            $args['_wpnonce'] = wp_create_nonce('sepo_events_export');
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }

    public static function login(): void
    {
        $back = isset($_SERVER['REQUEST_URI']) ? home_url(wp_unslash((string) $_SERVER['REQUEST_URI'])) : admin_url(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        wp_safe_redirect(wp_login_url(esc_url_raw($back)));
        exit;
    }

    private static function gate(): void
    {
        if (!Plugin::canManage()) {
            wp_die(esc_html__('You need permission to manage events to use this page.', 'sepo-events'), esc_html__('Not allowed', 'sepo-events'), ['response' => 403]);
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
    }

    public static function checkin(): void
    {
        self::gate();
        // phpcs:disable WordPress.Security.NonceVerification -- read-only page; the check-in itself is a nonce-checked REST call
        $event = isset($_GET['event']) ? absint($_GET['event']) : 0;
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        // phpcs:enable
        $events = [];
        $soon = array_merge(Event::list(['when' => 'upcoming', 'include_private' => true]), Event::list(['when' => 'past', 'include_private' => true, 'limit' => 20]));
        foreach ($soon as $c) {
            $events[] = ['id' => $c['id'], 'title' => $c['title'], 'when' => $c['starts_utc'] !== null ? Event::when($c) : ''];
        }
        if ($event > 0 && !in_array($event, array_column($events, 'id'), true) && get_post_type($event) === PostType::TYPE) {
            $c = Event::config($event);
            array_unshift($events, ['id' => $c['id'], 'title' => $c['title'], 'when' => $c['starts_utc'] !== null ? Event::when($c) : '']);
        }
        if ($event === 0 && $events !== []) {
            $live = array_filter(Event::list(['when' => 'upcoming']), [Event::class, 'isLive']);
            $event = $live ? (int) reset($live)['id'] : (int) $events[0]['id'];
        }
        header('Content-Type: text/html; charset=utf-8');
        echo Pages::checkin([ // phpcs:ignore WordPress.Security.EscapeOutput -- full page, escaped inside
            'api'    => rest_url(\SepoEvents\Rest::NS),
            'nonce'  => wp_create_nonce('wp_rest'),
            'events' => $events,
            'event'  => $event,
            'code'   => $code,
            'back'   => Screens::dashboardUrl($event > 0 ? ['event' => $event] : []),
        ]);
        exit;
    }

    public static function cards(): void
    {
        self::gate();
        $eventId = isset($_GET['event']) ? absint($_GET['event']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        $only = isset($_GET['id']) ? sanitize_text_field(wp_unslash((string) $_GET['id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $post = get_post($eventId);
        if (!$post || $post->post_type !== PostType::TYPE) {
            wp_die(esc_html__('Event not found.', 'sepo-events'), '', ['response' => 404]);
        }
        $c = Event::config($post);
        $records = $only !== '' ? array_filter([Rsvps::findById($eventId, $only)]) : Rsvps::going($eventId);
        // Oldest registration first, so cards come off the printer in sign-up order.
        $records = array_reverse(array_values($records));

        $people = [];
        foreach ($records as $r) {
            $people[] = ['name' => $r['name'], 'email' => $r['guest_email'], 'phone' => $r['phone'], 'type' => 'primary', 'code' => $r['checkin_code'], 'status' => $r['status']];
            foreach ($r['attendees'] as $a) {
                if (trim((string) ($a['name'] ?? '')) === '') {
                    continue;
                }
                $people[] = ['name' => (string) $a['name'], 'email' => (string) ($a['email'] ?? ''), 'phone' => (string) ($a['phone'] ?? ''), 'type' => 'attendee', 'code' => (string) ($a['code'] ?? ''), 'status' => $r['status']];
            }
        }
        // A card whose code can never scan is worse than no card.
        $people = array_values(array_filter($people, static fn($p) => $p['code'] !== ''));
        header('Content-Type: text/html; charset=utf-8');
        echo Pages::cards($people, $c); // phpcs:ignore WordPress.Security.EscapeOutput -- full page, escaped inside
        exit;
    }

    public static function print(): void
    {
        self::gate();
        // phpcs:disable WordPress.Security.NonceVerification -- read-only page
        $type = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : 'events';
        $event = isset($_GET['event']) ? absint($_GET['event']) : 0;
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        // phpcs:enable
        header('Content-Type: text/html; charset=utf-8');
        if ($type === 'rsvps') {
            $r = Reports::rsvps($event, in_array($status, Rsvps::STATUSES, true) ? $status : '', 5000);
            /* translators: %d: number of records */
            $sub = [sprintf(_n('%d record', '%d records', count($r['rows']), 'sepo-events'), count($r['rows']))];
            if ($event > 0) {
                $sub[] = get_the_title($event);
            }
            if ($status !== '') {
                $sub[] = $status;
            }
            /* translators: %s: date and time */
            $sub[] = sprintf(__('generated %s', 'sepo-events'), wp_date('j M Y, H:i'));
            echo Pages::printRsvps($r['rows'], implode(' · ', $sub)); // phpcs:ignore WordPress.Security.EscapeOutput -- full page, escaped inside
            exit;
        }
        echo Pages::printEvents(Reports::events()['events']); // phpcs:ignore WordPress.Security.EscapeOutput -- full page, escaped inside
        exit;
    }

    public static function export(): void
    {
        if (!Plugin::canManage()) {
            wp_die(esc_html__('You need permission to manage events to export.', 'sepo-events'), '', ['response' => 403]);
        }
        check_admin_referer('sepo_events_export');
        // phpcs:disable WordPress.Security.NonceVerification -- checked above
        $type = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : 'events';
        $event = isset($_GET['event']) ? absint($_GET['event']) : 0;
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        // phpcs:enable

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($type === 'rsvps' ? 'rsvps' : 'events') . '-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        if ($type === 'rsvps') {
            $r = Reports::rsvps($event, in_array($status, Rsvps::STATUSES, true) ? $status : '', 5000);
            self::csv($out, ['Event', 'Registered', 'Name', 'Email', 'Phone', 'Seats', 'Status', 'Check-in code', 'Checked in', 'Checked in at', 'Amount', 'Currency', 'Proof files', 'Payment checked', 'Attendees']);
            foreach ($r['rows'] as $row) {
                $att = [];
                foreach ($row['attendees'] as $a) {
                    $att[] = trim((string) ($a['name'] ?? ($a['email'] ?? '')));
                }
                self::csv($out, [
                    $row['event_title'],
                    $row['created'] ? wp_date('Y-m-d H:i', (int) $row['created']) : '',
                    $row['name'], $row['guest_email'], $row['phone'], (string) $row['guests'], $row['status'], $row['checkin_code'],
                    $row['checked_in'] ? 'yes' : 'no',
                    $row['checked_in_at'] ? wp_date('Y-m-d H:i', (int) $row['checked_in_at']) : '',
                    Rsvps::money($row) > 0 ? number_format(Rsvps::money($row), 2, '.', '') : '',
                    $row['currency'],
                    (string) count($row['proof_files']),
                    $row['verified_at'] ? wp_date('Y-m-d H:i', (int) $row['verified_at']) : '',
                    implode('; ', array_filter($att)),
                ]);
            }
        } else {
            self::csv($out, ['Event', 'ID', 'Starts', 'Ends', 'Time zone', 'Location', 'Capacity', 'Going', 'Waitlist', 'Cancelled', 'Seats taken', 'Seats left', 'Checked in', 'Collected', 'Currency', 'Private', 'No page']);
            foreach (Reports::events()['events'] as $e) {
                self::csv($out, [
                    $e['title'], (string) $e['id'],
                    $e['starts'] !== null ? Event::local((int) $e['starts'], (string) $e['tz'], 'Y-m-d H:i') : '',
                    $e['ends'] !== null ? Event::local((int) $e['ends'], (string) $e['tz'], 'Y-m-d H:i') : '',
                    $e['tz'], $e['location'],
                    $e['capacity'] > 0 ? (string) $e['capacity'] : 'uncapped',
                    (string) $e['going'], (string) $e['waitlist'], (string) $e['cancelled'], (string) $e['seats_taken'],
                    $e['capacity'] > 0 ? (string) $e['seats_left'] : '',
                    (string) $e['checked_in'], number_format((float) $e['collected'], 2, '.', ''), $e['currency'],
                    $e['private'] ? 'yes' : 'no', $e['orphan'] ? 'yes' : 'no',
                ]);
            }
        }
        fclose($out);
        exit;
    }

    /**
     * One CSV row. A cell that a spreadsheet would read as a formula gets a
     * leading apostrophe, so a name like "=HYPERLINK(…)" stays text.
     *
     * @param resource $out
     * @param list<mixed> $cells
     */
    private static function csv($out, array $cells): void
    {
        $cells = array_map(static function ($v): string {
            $v = (string) $v;
            return $v !== '' && str_contains("=+-@\t\r", $v[0]) && !is_numeric($v) ? "'" . $v : $v;
        }, $cells);
        fputcsv($out, $cells, ',', '"', '');
    }
}
