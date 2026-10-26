<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * The numbers behind the RSVP dashboard. The dashboard, the CSV exports and
 * the print exports all call these two functions, so a total means the
 * same thing wherever it appears.
 */
final class Reports
{
    /**
     * Every event, every RSVP number, in one pass. Events that hold RSVPs
     * but no longer exist are listed too, marked as having no page.
     *
     * @return array{events:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public static function events(): array
    {
        $now = time();
        $rows = [];
        foreach (Event::list(['include_private' => true]) as $c) {
            $rows[$c['id']] = self::eventRow($c, false);
        }
        // Events in the bin are listed so they can be restored or deleted for good; they count towards no total.
        $binned = get_posts(['post_type' => PostType::TYPE, 'post_status' => 'trash', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true]);
        foreach (array_unique(array_merge(Rsvps::eventIds(), array_map('intval', $binned))) as $id) {
            if (isset($rows[$id])) {
                continue;
            }
            $post = get_post($id);
            if ($post && $post->post_type === PostType::TYPE) {
                $rows[$id] = self::eventRow(Event::config($post), false);
                continue;
            }
            $rows[$id] = [
                'id' => $id, 'title' => $post ? get_the_title($post) : sprintf(/* translators: %d: event id */ __('Event #%d', 'sepo-events'), $id),
                'url' => '', 'edit' => '', 'starts' => null, 'ends' => null, 'tz' => 'UTC', 'location' => '',
                'capacity' => 0, 'paid' => false, 'price' => 0.0, 'currency' => '', 'private' => false, 'status' => '', 'orphan' => true, 'trashed' => false, 'can_delete' => Plugin::canManage(),
            ];
        }

        $totals = [
            'events' => 0, 'upcoming' => 0, 'past' => 0, 'undated' => 0, 'orphans' => 0, 'with_rsvps' => 0,
            'going' => 0, 'waitlist' => 0, 'cancelled' => 0, 'records' => 0, 'seats_taken' => 0, 'seats_left' => 0,
            'capacity_total' => 0, 'full' => 0, 'checked_in' => 0,
        ];
        $collected = [];
        $refundable = [];
        $out = [];

        foreach ($rows as $id => $row) {
            $n = Rsvps::counts((int) $id, (int) $row['capacity']);
            $out[] = $row + $n;
            if ($row['trashed']) {
                continue;
            }

            $totals['events']++;
            if ($row['orphan']) {
                $totals['orphans']++;
            }
            if ($row['starts'] === null) {
                $totals['undated']++;
            } elseif (($row['ends'] ?? ($row['starts'] + 3600)) >= $now) {
                $totals['upcoming']++;
            } else {
                $totals['past']++;
            }
            if ($n['records'] + $n['cancelled'] > 0) {
                $totals['with_rsvps']++;
            }
            foreach (['going', 'waitlist', 'cancelled', 'records', 'seats_taken', 'checked_in'] as $k) {
                $totals[$k] += $n[$k];
            }
            if ((int) $row['capacity'] > 0) {
                $totals['capacity_total'] += (int) $row['capacity'];
                $totals['seats_left'] += (int) $n['seats_left'];
                if ((int) $n['seats_left'] <= 0) {
                    $totals['full']++;
                }
            }
            $cur = (string) $row['currency'] !== '' ? (string) $row['currency'] : '—';
            if ($n['collected'] > 0) {
                $collected[$cur] = round(($collected[$cur] ?? 0) + $n['collected'], 2);
            }
            if ($n['refundable'] > 0) {
                $refundable[$cur] = round(($refundable[$cur] ?? 0) + $n['refundable'], 2);
            }
        }
        arsort($collected);
        arsort($refundable);
        $totals['collected'] = (object) $collected;
        $totals['refundable'] = (object) $refundable;

        // Dated events newest first, then undated ones by title.
        usort($out, static function (array $a, array $b): int {
            if ($a['trashed'] !== $b['trashed']) {
                return $a['trashed'] ? 1 : -1;
            }
            if ($a['starts'] !== null && $b['starts'] !== null) {
                return $b['starts'] <=> $a['starts'];
            }
            if ($a['starts'] !== null || $b['starts'] !== null) {
                return $a['starts'] !== null ? -1 : 1;
            }
            return strcasecmp((string) $a['title'], (string) $b['title']);
        });

        return ['events' => $out, 'totals' => $totals];
    }

    /**
     * RSVP rows with their event's title and currency and a check-in summary.
     *
     * @return array{rows:list<array<string,mixed>>,truncated:bool,limit:int}
     */
    public static function rsvps(int $eventId, string $status, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $got = Rsvps::across($eventId > 0 ? [$eventId] : [], $status, $limit);
        $titles = [];
        $rows = [];
        foreach ($got['rows'] as $r) {
            $eid = (int) $r['event_id'];
            if (!isset($titles[$eid])) {
                $post = get_post($eid);
                $titles[$eid] = $post && $post->post_type === PostType::TYPE ? Event::config($post) : null;
            }
            $c = $titles[$eid];
            $r['event_title'] = $c ? $c['title'] : sprintf(/* translators: %d: event id */ __('Event #%d', 'sepo-events'), $eid);
            if ($r['currency'] === '') {
                $r['currency'] = $c ? $c['currency'] : '';
            }
            $r['checked_in'] = $r['checkins'] !== [];
            $r['checked_in_at'] = $r['checkins'] !== [] ? min(array_map(static fn($x) => (int) ($x['at'] ?? 0), $r['checkins'])) : null;
            $r['checkins'] = (object) $r['checkins'];
            $r['proof_files'] = Proofs::listFor($r);
            $checker = $r['verified_by'] > 0 ? get_userdata((int) $r['verified_by']) : false;
            $r['verified_by_name'] = $checker ? (string) $checker->display_name : '';
            unset($r['proofs']);
            $rows[] = $r;
        }
        return ['rows' => $rows, 'truncated' => $got['truncated'], 'limit' => $limit];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function eventRow(array $c, bool $orphan): array
    {
        return [
            'id'       => $c['id'],
            'title'    => $c['title'],
            'url'      => $c['url'],
            'edit'     => (string) get_edit_post_link($c['id'], 'raw'),
            'starts'   => $c['starts_utc'],
            'ends'     => $c['ends_utc'],
            'tz'       => $c['tz'],
            'location' => $c['location'],
            'capacity' => $c['capacity'],
            'paid'     => $c['paid'],
            'price'    => $c['price'],
            'currency' => $c['currency'],
            'private'  => $c['private'],
            'status'   => $c['status'],
            'orphan'   => $orphan,
            'trashed'  => $c['status'] === 'trash',
            // The bin keeps an event's RSVPs; deleting for good removes them and their proof files.
            'can_delete' => current_user_can('delete_post', $c['id']),
        ];
    }
}
