<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * One event's settings, read from post meta and normalised into the shape
 * the event page, the RSVP endpoint, the reports and the exports all use.
 * The page and the endpoint must agree on what the event allows, so both
 * read it here.
 *
 * Times are stored as the local date-time the editor typed plus the event's
 * time zone, and as UTC timestamps (_spe_starts_utc, _spe_ends_utc) for
 * sorting and querying.
 */
final class Event
{
    public const META   = '_spe_event';
    public const STARTS = '_spe_starts_utc';
    public const ENDS   = '_spe_ends_utc';

    /** The fields the RSVP form can ask for. Email is always asked. */
    public const FIELDS = ['email', 'name', 'phone'];

    /** @var array<int,array<string,mixed>> */
    private static array $memo = [];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'starts'        => '',
            'ends'          => '',
            'timezone'      => '',
            'all_day'       => false,
            'location'      => '',
            'capacity'      => 0,
            'closed'        => false,
            'require_login' => false,
            'fields'        => ['email', 'name'],
            'allow_guests'  => false,
            'max_seats'     => 5,
            'paid'          => false,
            'price'         => 0.0,
            'currency'      => '',
            'proof'         => 'off',
            'host'          => '',
            'host_bio'      => '',
            'details'       => [],
        ];
    }

    /**
     * Clean editor input into the stored shape.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $bool = static fn($v): bool => in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
        $dt = static function ($v): string {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $v) === 1 ? $v : '';
        };

        $tz = trim((string) ($in['timezone'] ?? ''));
        if ($tz !== '' && !self::validZone($tz)) {
            $tz = '';
        }

        $fields = ['email'];
        foreach ((array) ($in['fields'] ?? []) as $f) {
            $f = strtolower(trim((string) $f));
            if (in_array($f, self::FIELDS, true) && !in_array($f, $fields, true)) {
                $fields[] = $f;
            }
        }

        $details = [];
        $raw = $in['details'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }
        foreach ((array) $raw as $row) {
            $parts = is_array($row) ? array_values($row) : array_map('trim', explode('|', (string) $row, 2));
            $label = sanitize_text_field((string) ($parts[0] ?? ''));
            if ($label === '') {
                continue;
            }
            $details[] = ['label' => $label, 'note' => sanitize_text_field((string) ($parts[1] ?? ''))];
        }

        return [
            'starts'        => $dt($in['starts'] ?? ''),
            'ends'          => $dt($in['ends'] ?? ''),
            'timezone'      => $tz,
            'all_day'       => $bool($in['all_day'] ?? ''),
            'location'      => sanitize_text_field((string) ($in['location'] ?? '')),
            'capacity'      => max(0, (int) ($in['capacity'] ?? 0)),
            'closed'        => $bool($in['closed'] ?? ''),
            'require_login' => $bool($in['require_login'] ?? ''),
            'fields'        => $fields,
            'allow_guests'  => $bool($in['allow_guests'] ?? ''),
            'max_seats'     => max(1, min(50, (int) ($in['max_seats'] ?? $d['max_seats']))),
            'paid'          => $bool($in['paid'] ?? ''),
            'price'         => max(0.0, round((float) ($in['price'] ?? 0), 2)),
            'currency'      => self::currency((string) ($in['currency'] ?? ''), ''),
            'proof'         => in_array($in['proof'] ?? '', ['optional', 'required'], true) ? (string) $in['proof'] : 'off',
            'host'          => sanitize_text_field((string) ($in['host'] ?? '')),
            'host_bio'      => sanitize_textarea_field((string) ($in['host_bio'] ?? '')),
            'details'       => $details,
        ];
    }

    /** Store an event's settings and its UTC times. @param array<string,mixed> $settings */
    public static function save(int $postId, array $settings): void
    {
        $s = self::sanitize($settings);
        update_post_meta($postId, self::META, $s);
        $tz = $s['timezone'] !== '' ? $s['timezone'] : wp_timezone_string();
        $starts = self::toUtc($s['starts'], $tz, false);
        $ends = self::toUtc($s['ends'], $tz, $s['all_day']);
        update_post_meta($postId, self::STARTS, $starts ?? 0);
        update_post_meta($postId, self::ENDS, $ends ?? 0);
        unset(self::$memo[$postId]);
    }

    /**
     * The normalised event.
     *
     * @param int|\WP_Post $post
     * @return array{
     *   id:int, title:string, description:string, url:string, status:string,
     *   starts_utc:?int, ends_utc:?int, tz:string, all_day:bool,
     *   location:string, capacity:int, closed:bool, require_login:bool, private:bool,
     *   fields:list<string>, allow_guests:bool, max_seats:int,
     *   paid:bool, price:float, currency:string, is_paid:bool, proof:string,
     *   host:string, host_bio:string, details:list<array{label:string,note:string}>
     * }
     */
    public static function config($post): array
    {
        $post = get_post($post);
        $id = $post ? (int) $post->ID : 0;
        if ($id > 0 && isset(self::$memo[$id])) {
            return self::$memo[$id];
        }
        $raw = $id > 0 ? get_post_meta($id, self::META, true) : [];
        $s = array_merge(self::defaults(), is_array($raw) ? $raw : []);

        $tz = (string) $s['timezone'];
        if ($tz === '' || !self::validZone($tz)) {
            $tz = wp_timezone_string();
        }
        if (!self::validZone($tz)) {
            $tz = 'UTC';
        }
        $allDay = (bool) $s['all_day'];
        $currency = self::currency((string) $s['currency'], Settings::currency());
        $price = max(0.0, round((float) $s['price'], 2));

        $c = [
            'id'            => $id,
            'title'         => $post ? (get_the_title($post) ?: __('Event', 'sepo-events')) : __('Event', 'sepo-events'),
            'description'   => $post ? trim(wp_strip_all_tags((string) $post->post_excerpt)) : '',
            'url'           => $post ? (string) get_permalink($post) : '',
            'status'        => $post ? (string) $post->post_status : '',
            'starts_utc'    => self::toUtc((string) $s['starts'], $tz, false),
            'ends_utc'      => self::toUtc((string) $s['ends'], $tz, $allDay),
            'tz'            => $tz,
            'all_day'       => $allDay,
            'location'      => (string) $s['location'],
            'capacity'      => max(0, (int) $s['capacity']),
            'closed'        => (bool) $s['closed'],
            'require_login' => (bool) $s['require_login'],
            'private'       => $post ? $post->post_status === 'private' : false,
            'fields'        => array_values(array_unique(array_merge(['email'], array_intersect((array) $s['fields'], self::FIELDS)))),
            'allow_guests'  => (bool) $s['allow_guests'],
            'max_seats'     => max(1, min(50, (int) $s['max_seats'])),
            'paid'          => (bool) $s['paid'],
            'price'         => $price,
            'currency'      => $currency,
            'is_paid'       => (bool) $s['paid'] && $price > 0,
            // Proof of payment applies to paid events only: off, optional or required.
            'proof'         => (bool) $s['paid'] && $price > 0 && in_array($s['proof'], ['optional', 'required'], true) ? (string) $s['proof'] : 'off',
            'host'          => (string) $s['host'],
            'host_bio'      => (string) $s['host_bio'],
            'details'       => array_values(array_filter((array) $s['details'], 'is_array')),
        ];
        if ($id > 0) {
            self::$memo[$id] = $c;
        }
        return $c;
    }

    /** The settings as stored, for the editor. @return array<string,mixed> */
    public static function stored(int $postId): array
    {
        $raw = get_post_meta($postId, self::META, true);
        return array_merge(self::defaults(), is_array($raw) ? $raw : []);
    }

    public static function validZone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A local date-time in the event's zone as a Unix timestamp; null when
     * empty or unreadable. A date with no time is the start of that day, or
     * the end of it when $endOfDay is set (an all-day event's last day).
     */
    public static function toUtc(string $local, string $tz, bool $endOfDay): ?int
    {
        $local = trim($local);
        if ($local === '') {
            return null;
        }
        try {
            $zone = new \DateTimeZone(self::validZone($tz) ? $tz : 'UTC');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $local) === 1 || $endOfDay) {
                $day = substr($local, 0, 10);
                $local = $endOfDay ? $day . ' 23:59:59' : $day . ' 00:00:00';
            }
            return (new \DateTimeImmutable($local, $zone))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /** A UTC timestamp written in the event's zone, with month and day names in the site language. */
    public static function local(?int $utc, string $tz, string $format = 'D j M Y, H:i'): string
    {
        if ($utc === null) {
            return '';
        }
        $zone = self::validZone($tz) ? new \DateTimeZone($tz) : new \DateTimeZone('UTC');
        return (string) wp_date($format, $utc, $zone);
    }

    /** "2h 30m" between two times, or '' when either is missing. */
    public static function duration(?int $starts, ?int $ends): string
    {
        if ($starts === null || $ends === null || $ends <= $starts) {
            return '';
        }
        $mins = (int) floor(($ends - $starts) / 60);
        if ($mins >= 1440) {
            $days = (int) round($mins / 1440);
            /* translators: %d: number of days */
            return sprintf(_n('%d day', '%d days', $days, 'sepo-events'), $days);
        }
        if ($mins < 60) {
            return $mins . 'm';
        }
        $h = intdiv($mins, 60);
        $m = $mins % 60;
        return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
    }

    /**
     * A currency code: upper-case letters, 2 to 6 of them. Anything else
     * gives way to the fallback, since the dashboard sums money by this code.
     */
    public static function currency(string $raw, string $fallback): string
    {
        $code = strtoupper(trim($raw));
        return preg_match('/^[A-Z]{2,6}$/', $code) === 1 ? $code : $fallback;
    }

    /** When the event finishes: its end, or an hour after it starts. @param array<string,mixed> $c */
    public static function endsAt(array $c): ?int
    {
        if ($c['starts_utc'] === null) {
            return null;
        }
        return $c['ends_utc'] ?? ($c['all_day'] ? self::toUtc(gmdate('Y-m-d', (int) $c['starts_utc']), 'UTC', true) : $c['starts_utc'] + 3600);
    }

    /** @param array<string,mixed> $c */
    public static function isOver(array $c, ?int $now = null): bool
    {
        $end = self::endsAt($c);
        return $end !== null && $end < ($now ?? time());
    }

    /** @param array<string,mixed> $c */
    public static function isLive(array $c, ?int $now = null): bool
    {
        $now ??= time();
        return $c['starts_utc'] !== null && $c['starts_utc'] <= $now && !self::isOver($c, $now);
    }

    /** "Thu 6 Nov 2026, 18:00" or, for an all-day event, "Thu 6 Nov 2026". @param array<string,mixed> $c */
    public static function when(array $c): string
    {
        return self::local($c['starts_utc'], $c['tz'], $c['all_day'] ? 'D j M Y' : 'D j M Y, H:i');
    }

    /** Money with its code, "ZMW 150.00". */
    public static function money(float $amount, string $currency): string
    {
        return trim($currency . ' ' . number_format_i18n($amount, 2));
    }

    /**
     * Published events, soonest first. Undated events come last.
     *
     * @param array{when?:string,limit?:int,include_private?:bool} $o when: upcoming, past or all
     * @return list<array<string,mixed>>
     */
    public static function list(array $o = []): array
    {
        $statuses = ['publish'];
        if (!empty($o['include_private'])) {
            $statuses[] = 'private';
        }
        $ids = get_posts([
            'post_type'        => PostType::TYPE,
            'post_status'      => $statuses,
            'posts_per_page'   => 1000,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);
        $now = time();
        $when = $o['when'] ?? 'all';
        $out = [];
        foreach ($ids as $id) {
            $c = self::config((int) $id);
            $over = self::isOver($c, $now);
            if ($when === 'upcoming' && ($over || $c['starts_utc'] === null)) {
                continue;
            }
            if ($when === 'past' && !$over) {
                continue;
            }
            $out[] = $c;
        }
        usort($out, static fn($a, $b) => ($a['starts_utc'] ?? PHP_INT_MAX) <=> ($b['starts_utc'] ?? PHP_INT_MAX));
        if ($when === 'past') {
            $out = array_reverse($out);
        }
        $limit = (int) ($o['limit'] ?? 0);
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** The event's calendar file address. */
    public static function icsUrl(int $id): string
    {
        $post = get_post($id);
        if ($post && get_option('permalink_structure') && $post->post_status === 'publish') {
            return home_url('/events/' . $post->post_name . '.ics');
        }
        return add_query_arg(['spe_ics' => $id], home_url('/'));
    }
}
