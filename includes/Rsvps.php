<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * RSVP storage: one row per registration in {prefix}sepo_event_rsvps.
 *
 * STATUSES
 *   going      holds seats; counts against capacity
 *   waitlist   arrived when the event was full; does not count
 *   cancelled  withdrew; does not count, kept for history
 *
 * RECORDS AND SEATS
 *   A registration (record) claims one or more seats (`guests`). "How many
 *   are coming" is the seat count; "how many signed up" is the record count.
 *   counts() returns both.
 *
 * CAPACITY IS CHECKED UNDER A LOCK
 *   Every change to an event's RSVPs takes a database lock named after the
 *   event, re-reads the rows, decides, writes and releases. Two people
 *   cannot both take the last seat.
 *
 * THE WAITLIST MOVES
 *   When a seat is freed (a cancellation, a removal), the oldest waiting
 *   registrations that now fit are moved to going, in the same lock.
 *
 * CHECK-IN
 *   Each registrant has a check-in code, and each named attendee their own.
 *   A person is found by code first, then by email (any case), then by
 *   phone (digits only). Attendance is a map keyed `primary` or
 *   `attendee_<n>`, so a second scan reports "already checked in".
 */
final class Rsvps
{
    public const STATUSES = ['going', 'waitlist', 'cancelled'];
    private const MAX_SEATS = 50;
    private const MAX_ATTENDEES = 50;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var array<int,list<array<string,mixed>>> */
    private static array $memo = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sepo_event_rsvps';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t} (
  row_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  id varchar(40) NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  guest_email varchar(191) NOT NULL DEFAULT '',
  name varchar(191) NOT NULL DEFAULT '',
  phone varchar(64) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'going',
  guests int(10) unsigned NOT NULL DEFAULT 1,
  amount decimal(12,2) NULL,
  currency varchar(8) NOT NULL DEFAULT '',
  attendees longtext NULL,
  checkins longtext NULL,
  checkin_code varchar(32) NOT NULL DEFAULT '',
  proofs longtext NULL,
  verified_at bigint(20) NOT NULL DEFAULT 0,
  verified_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created bigint(20) NOT NULL DEFAULT 0,
  updated bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (row_id),
  UNIQUE KEY id (id),
  KEY event_status (event_id,status),
  KEY user_id (user_id),
  KEY guest_email (guest_email),
  KEY checkin_code (checkin_code)
) {$charset};");
    }

    // ------------------------------------------------------------ reading

    /**
     * Every RSVP for one event, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEvent(int $eventId, bool $fresh = false): array
    {
        global $wpdb;
        if ($eventId <= 0) {
            return [];
        }
        if (!$fresh && isset(self::$memo[$eventId])) {
            return self::$memo[$eventId];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE event_id = %d ORDER BY created DESC, row_id DESC', $eventId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return self::$memo[$eventId] = array_map([self::class, 'decode'], $rows);
    }

    /** One record by id, from any event. @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id), ARRAY_A); // phpcs:ignore WordPress.DB
        return $row ? self::decode($row) : null;
    }

    /** The record a signed-in user or a guest email holds for an event. @return array<string,mixed>|null */
    public static function findFor(int $eventId, int $userId, string $email = ''): ?array
    {
        $email = strtolower(trim($email));
        foreach (self::forEvent($eventId) as $r) {
            if ($userId > 0 && (int) $r['user_id'] === $userId) {
                return $r;
            }
            if ($userId === 0 && $email !== '' && (int) $r['user_id'] === 0 && $r['guest_email'] === $email) {
                return $r;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $eventId, string $id): ?array
    {
        foreach (self::forEvent($eventId) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    public static function going(int $eventId): array
    {
        return array_values(array_filter(self::forEvent($eventId), static fn($r) => $r['status'] === 'going'));
    }

    /**
     * @return array{going:int,waitlist:int,cancelled:int,records:int,seats_taken:int,seats_left:?int,collected:float,refundable:float,checked_in:int}
     */
    public static function counts(int $eventId, int $capacity = 0): array
    {
        $n = ['going' => 0, 'waitlist' => 0, 'cancelled' => 0, 'seats' => 0, 'collected' => 0.0, 'refundable' => 0.0, 'checked_in' => 0];
        foreach (self::forEvent($eventId) as $r) {
            $money = self::money($r);
            $n['checked_in'] += count($r['checkins']);
            if ($r['status'] === 'going') {
                $n['going']++;
                $n['seats'] += max(1, (int) $r['guests']);
                $n['collected'] += $money;
            } elseif ($r['status'] === 'waitlist') {
                $n['waitlist']++;
                $n['collected'] += $money;
            } elseif ($r['status'] === 'cancelled') {
                $n['cancelled']++;
                $n['refundable'] += $money;
            }
        }
        return [
            'going'       => $n['going'],
            'waitlist'    => $n['waitlist'],
            'cancelled'   => $n['cancelled'],
            'records'     => $n['going'] + $n['waitlist'],
            'seats_taken' => $n['seats'],
            'seats_left'  => $capacity > 0 ? max(0, $capacity - $n['seats']) : null,
            'collected'   => round($n['collected'], 2),
            'refundable'  => round($n['refundable'], 2),
            'checked_in'  => $n['checked_in'],
        ];
    }

    /**
     * RSVPs across events, newest first.
     *
     * @param list<int> $eventIds empty for every event
     * @return array{rows:list<array<string,mixed>>,truncated:bool}
     */
    public static function across(array $eventIds, string $status, int $limit): array
    {
        global $wpdb;
        $limit = max(1, min(5000, $limit));
        $where = [];
        $args = [];
        $eventIds = array_values(array_filter(array_map('intval', $eventIds)));
        if ($eventIds !== []) {
            $where[] = 'event_id IN (' . implode(',', array_fill(0, count($eventIds), '%d')) . ')';
            array_push($args, ...$eventIds);
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = %s';
            $args[] = $status;
        }
        $sql = 'SELECT * FROM ' . self::table() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created DESC, row_id DESC LIMIT ' . ($limit + 1);
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        $truncated = count($rows) > $limit;
        return ['rows' => array_map([self::class, 'decode'], array_slice($rows, 0, $limit)), 'truncated' => $truncated];
    }

    /** Events that hold at least one RSVP. @return list<int> */
    public static function eventIds(): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col('SELECT DISTINCT event_id FROM ' . self::table()) ?: []); // phpcs:ignore WordPress.DB
    }

    /** Every record that names this email, as registrant or attendee. @return list<array<string,mixed>> */
    public static function forEmail(string $email): array
    {
        global $wpdb;
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $like = '%' . $wpdb->esc_like('"' . $email . '"') . '%';
        $user = get_user_by('email', $email);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE guest_email = %s OR attendees LIKE %s OR (user_id > 0 AND user_id = %d) ORDER BY created DESC', $email, $like, $user ? (int) $user->ID : -1), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB
        return array_map([self::class, 'decode'], $rows);
    }

    // ------------------------------------------------------------ writing

    /**
     * Reserve seats, or join the waitlist when they do not fit.
     *
     * A signed-in user has one record per event, found by account; a guest
     * has one per email. Sending again updates that record. A code once
     * issued never changes, and attendance already recorded is kept.
     *
     * New proof files in $data['proofs'] are added to any the record already has.
     *
     * @param array{user_id?:int,email?:string,name?:string,phone?:string,guests?:int,amount?:float|null,attendees?:array<int,mixed>,currency?:string,proofs?:list<array<string,mixed>>} $data
     * @return array{status:string,previous:string,record:array<string,mixed>,seats_taken:int,capacity:int,created:bool}
     */
    public static function rsvp(int $eventId, array $data, int $capacity): array
    {
        global $wpdb;
        $userId = max(0, (int) ($data['user_id'] ?? 0));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($userId === 0 && $email === '') {
            throw new \InvalidArgumentException(__('Sign in or provide an email to RSVP.', 'sepo-events'));
        }
        $want = max(1, min(self::MAX_SEATS, (int) ($data['guests'] ?? 1)));
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 191);
        $phone = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 64);
        $amount = isset($data['amount']) && is_numeric($data['amount']) ? round(max(0.0, (float) $data['amount']), 2) : null;
        $attendees = self::cleanAttendees($data['attendees'] ?? []);

        return self::locked($eventId, static function (array $rows) use ($eventId, $userId, $email, $want, $name, $phone, $amount, $attendees, $capacity, $data, $wpdb): array {
            $existing = null;
            foreach ($rows as $r) {
                if (($userId > 0 && (int) $r['user_id'] === $userId) || ($userId === 0 && (int) $r['user_id'] === 0 && $r['guest_email'] === $email)) {
                    $existing = $r;
                    break;
                }
            }
            $held = 0;
            foreach ($rows as $r) {
                if ($r['status'] === 'going' && (!$existing || $r['id'] !== $existing['id'])) {
                    $held += max(1, (int) $r['guests']);
                }
            }
            $status = ($capacity <= 0 || $held + $want <= $capacity) ? 'going' : 'waitlist';
            $now = time();

            $prevAttendees = $existing ? $existing['attendees'] : [];
            foreach ($attendees as $i => &$a) {
                $a['code'] = (string) ($prevAttendees[$i]['code'] ?? '') ?: self::newCode();
            }
            unset($a);

            $record = [
                'id'           => $existing['id'] ?? ('rsvp_' . $now . '_' . strtolower(wp_generate_password(8, false, false))),
                'event_id'     => $eventId,
                'user_id'      => $userId,
                'guest_email'  => $email !== '' ? $email : (string) ($existing['guest_email'] ?? ''),
                'name'         => $name !== '' ? $name : (string) ($existing['name'] ?? ''),
                'phone'        => $phone !== '' ? $phone : (string) ($existing['phone'] ?? ''),
                'status'       => $status,
                'guests'       => $want,
                'amount'       => $amount ?? ($existing['amount'] ?? null),
                'currency'     => (string) ($data['currency'] ?? ($existing['currency'] ?? '')),
                'attendees'    => $attendees,
                'checkins'     => $existing['checkins'] ?? [],
                'checkin_code' => (string) ($existing['checkin_code'] ?? '') ?: self::newCode(),
                'proofs'       => array_merge($existing['proofs'] ?? [], (array) ($data['proofs'] ?? [])),
                // A change to what was paid needs a fresh look; new proof files alone do too.
                'verified_at'  => !empty($data['proofs']) || ($amount !== null && $amount !== ($existing['amount'] ?? null)) ? 0 : (int) ($existing['verified_at'] ?? 0),
                'verified_by'  => !empty($data['proofs']) || ($amount !== null && $amount !== ($existing['amount'] ?? null)) ? 0 : (int) ($existing['verified_by'] ?? 0),
                'created'      => (int) ($existing['created'] ?? $now),
                'updated'      => $now,
            ];
            $cols = self::encode($record);
            if ($existing) {
                $wpdb->update(self::table(), $cols, ['id' => $record['id']]);
            } else {
                $wpdb->insert(self::table(), $cols);
            }
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException(__('Could not record your RSVP. Please try again.', 'sepo-events'));
            }
            $taken = $held + ($status === 'going' ? $want : 0);
            return [
                'status'      => $status,
                'previous'    => (string) ($existing['status'] ?? ''),
                'record'      => $record,
                'seats_taken' => $taken,
                'capacity'    => $capacity,
                'created'     => $existing === null,
            ];
        });
    }

    /**
     * Mark a record cancelled; waiting registrations that now fit move up.
     *
     * @return array{ok:bool,record:?array<string,mixed>,promoted:list<array<string,mixed>>}
     */
    public static function cancel(int $eventId, string $id, int $capacity): array
    {
        global $wpdb;
        return self::locked($eventId, static function (array $rows) use ($id, $capacity, $wpdb): array {
            foreach ($rows as $i => $r) {
                if ($r['id'] !== $id) {
                    continue;
                }
                if ($r['status'] === 'cancelled') {
                    return ['ok' => true, 'record' => $r, 'promoted' => []];
                }
                $wpdb->update(self::table(), ['status' => 'cancelled', 'updated' => time()], ['id' => $id]);
                $rows[$i]['status'] = 'cancelled';
                return ['ok' => true, 'record' => $rows[$i], 'promoted' => self::promote($rows, $capacity)];
            }
            return ['ok' => false, 'record' => null, 'promoted' => []];
        });
    }

    /**
     * Remove a record entirely; waiting registrations that now fit move up.
     *
     * @return array{ok:bool,promoted:list<array<string,mixed>>}
     */
    public static function delete(int $eventId, string $id, int $capacity): array
    {
        global $wpdb;
        return self::locked($eventId, static function (array $rows) use ($id, $capacity, $wpdb): array {
            $kept = [];
            $found = false;
            foreach ($rows as $r) {
                if ($r['id'] === $id) {
                    $found = true;
                    continue;
                }
                $kept[] = $r;
            }
            if (!$found) {
                return ['ok' => false, 'promoted' => []];
            }
            foreach ($rows as $r) {
                if ($r['id'] === $id) {
                    Proofs::remove((int) $r['event_id'], $r['proofs']);
                }
            }
            $wpdb->delete(self::table(), ['id' => $id]);
            do_action('sepo_events_rsvp_deleted', $id);
            return ['ok' => true, 'promoted' => self::promote($kept, $capacity)];
        });
    }

    /** Remove every RSVP for an event. Returns how many were removed. */
    public static function purge(int $eventId): int
    {
        global $wpdb;
        $n = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE event_id = %d', $eventId)); // phpcs:ignore WordPress.DB
        Proofs::removeEvent($eventId);
        unset(self::$memo[$eventId]);
        return $n;
    }

    /**
     * Check one person in by code, email or phone.
     *
     * @return array{found:bool,already:bool,refused:string,person:array{type:string,name:string,email:string,phone:string},record_id:string,status:string,at:?int,matched_by:string}
     */
    public static function checkIn(int $eventId, string $value): array
    {
        global $wpdb;
        $value = trim($value);
        $miss = ['found' => false, 'already' => false, 'refused' => '', 'person' => ['type' => '', 'name' => '', 'email' => '', 'phone' => ''], 'record_id' => '', 'status' => '', 'at' => null, 'matched_by' => ''];
        if ($eventId <= 0 || $value === '') {
            return $miss;
        }
        $code = strtoupper(preg_replace('/[\s-]+/', '', $value) ?? '');
        $email = strtolower($value);
        $phone = self::phoneKey($value);

        return self::locked($eventId, static function (array $rows) use ($code, $email, $phone, $miss, $wpdb): array {
            // Live registrations first: a cancelled record matching the same email must not win.
            usort($rows, static fn($a, $b) => ($a['status'] === 'cancelled') <=> ($b['status'] === 'cancelled'));
            foreach (['code', 'email', 'phone'] as $by) {
                foreach ($rows as $r) {
                    $slots = [['primary', 'primary', $r['checkin_code'], $r['guest_email'], $r['phone'], $r['name']]];
                    foreach ($r['attendees'] as $i => $a) {
                        $slots[] = ['attendee_' . $i, 'attendee', (string) ($a['code'] ?? ''), strtolower((string) ($a['email'] ?? '')), (string) ($a['phone'] ?? ''), (string) ($a['name'] ?? '')];
                    }
                    foreach ($slots as [$key, $type, $slotCode, $slotEmail, $slotPhone, $slotName]) {
                        $hit = match ($by) {
                            'code'  => $slotCode !== '' && strtoupper($slotCode) === $code,
                            'email' => $slotEmail !== '' && $slotEmail === $email,
                            default => $phone !== '' && strlen($phone) >= 6 && self::phoneKey($slotPhone) === $phone,
                        };
                        if (!$hit) {
                            continue;
                        }
                        $result = [
                            'found'      => true,
                            'already'    => isset($r['checkins'][$key]),
                            'refused'    => '',
                            'person'     => ['type' => $type, 'name' => $slotName, 'email' => $slotEmail, 'phone' => $slotPhone],
                            'record_id'  => $r['id'],
                            'status'     => $r['status'],
                            'at'         => isset($r['checkins'][$key]) ? (int) $r['checkins'][$key]['at'] : null,
                            'matched_by' => $by,
                        ];
                        if ($r['status'] === 'cancelled') {
                            $result['refused'] = 'cancelled';
                            return $result;
                        }
                        if (!$result['already']) {
                            $checkins = $r['checkins'];
                            $checkins[$key] = ['at' => time()];
                            $wpdb->update(self::table(), ['checkins' => wp_json_encode($checkins), 'updated' => time()], ['id' => $r['id']]);
                            $result['at'] = $checkins[$key]['at'];
                        }
                        return $result;
                    }
                }
            }
            return $miss;
        });
    }

    /** Remove a person's details from every record that names their email. Returns records changed. */
    public static function eraseEmail(string $email): int
    {
        global $wpdb;
        $n = 0;
        foreach (self::forEmail($email) as $r) {
            if ($r['guest_email'] === strtolower($email) || (int) $r['user_id'] > 0) {
                Proofs::remove((int) $r['event_id'], $r['proofs']);
                $wpdb->delete(self::table(), ['id' => $r['id']]);
                $n++;
                continue;
            }
            $attendees = array_map(static function (array $a) use ($email): array {
                if (strtolower((string) ($a['email'] ?? '')) === strtolower($email)) {
                    $a = ['name' => __('Removed', 'sepo-events'), 'code' => (string) ($a['code'] ?? '')];
                }
                return $a;
            }, $r['attendees']);
            $wpdb->update(self::table(), ['attendees' => wp_json_encode($attendees)], ['id' => $r['id']]);
            $n++;
        }
        self::$memo = [];
        return $n;
    }

    /**
     * Add proof files to an existing record. The payment goes back to
     * waiting for a manager's check.
     *
     * @param list<array<string,mixed>> $proofs
     * @return array<string,mixed>|null the updated record
     */
    public static function addProofs(int $eventId, string $id, array $proofs): ?array
    {
        global $wpdb;
        return self::locked($eventId, static function (array $rows) use ($id, $proofs, $wpdb): ?array {
            foreach ($rows as $r) {
                if ($r['id'] !== $id) {
                    continue;
                }
                $r['proofs'] = array_merge($r['proofs'], $proofs);
                $r['verified_at'] = 0;
                $r['verified_by'] = 0;
                $wpdb->update(self::table(), ['proofs' => wp_json_encode($r['proofs']), 'verified_at' => 0, 'verified_by' => 0, 'updated' => time()], ['id' => $id]);
                return $r;
            }
            return null;
        });
    }

    /** Mark a record's payment as checked by a manager, or clear the mark. */
    public static function verify(int $eventId, string $id, bool $verified): bool
    {
        global $wpdb;
        $n = $wpdb->update(self::table(), [
            'verified_at' => $verified ? time() : 0,
            'verified_by' => $verified ? get_current_user_id() : 0,
            'updated'     => time(),
        ], ['id' => $id, 'event_id' => $eventId]);
        unset(self::$memo[$eventId]);
        return (bool) $n;
    }

    // ------------------------------------------------------------ guest access

    /** The secret that lets a guest manage their own RSVP. */
    public static function token(string $id): string
    {
        return substr(hash_hmac('sha256', 'spe-rsvp|' . $id, wp_salt('auth')), 0, 32);
    }

    public static function cookieName(int $eventId): string
    {
        return 'spe_rsvp_' . $eventId;
    }

    /** The guest record this browser holds a key for, if any. @return array<string,mixed>|null */
    public static function fromCookie(int $eventId): ?array
    {
        $raw = isset($_COOKIE[self::cookieName($eventId)]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::cookieName($eventId)])) : '';
        return self::fromKey($eventId, $raw);
    }

    /** A record from an "id.token" key, when the token is right. @return array<string,mixed>|null */
    public static function fromKey(int $eventId, string $key): ?array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2 || !hash_equals(self::token($parts[0]), $parts[1])) {
            return null;
        }
        return self::findById($eventId, $parts[0]);
    }

    public static function key(string $id): string
    {
        return $id . '.' . self::token($id);
    }

    public static function setCookie(int $eventId, string $id): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::cookieName($eventId), self::key($id), [
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::cookieName($eventId)] = self::key($id);
    }

    // ------------------------------------------------------------ internals

    /** Total money recorded on a record: the registrant's amount plus each attendee's. @param array<string,mixed> $r */
    public static function money(array $r): float
    {
        $total = isset($r['amount']) && is_numeric($r['amount']) ? max(0.0, (float) $r['amount']) : 0.0;
        foreach ($r['attendees'] as $a) {
            if (isset($a['amount']) && is_numeric($a['amount'])) {
                $total += max(0.0, (float) $a['amount']);
            }
        }
        return round($total, 2);
    }

    /**
     * Run a change to one event's RSVPs under its lock, with fresh rows.
     *
     * @template T
     * @param callable(list<array<string,mixed>>):T $fn
     * @return T
     */
    private static function locked(int $eventId, callable $fn)
    {
        global $wpdb;
        $name = substr('spe_' . $wpdb->prefix . $eventId, 0, 64);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $name)); // phpcs:ignore WordPress.DB
        if ($got === '0') {
            throw new \RuntimeException(__('The event is busy. Please try again in a moment.', 'sepo-events'));
        }
        try {
            return $fn(self::forEvent($eventId, true));
        } finally {
            unset(self::$memo[$eventId]);
            if ($got === '1') {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); // phpcs:ignore WordPress.DB
            }
        }
    }

    /**
     * Move waiting registrations, oldest first, into seats that are now free.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function promote(array $rows, int $capacity): array
    {
        global $wpdb;
        $held = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'going') {
                $held += max(1, (int) $r['guests']);
            }
        }
        $waiting = array_values(array_filter($rows, static fn($r) => $r['status'] === 'waitlist'));
        usort($waiting, static fn($a, $b) => $a['created'] <=> $b['created']);
        $moved = [];
        foreach ($waiting as $r) {
            $need = max(1, (int) $r['guests']);
            if ($capacity > 0 && $held + $need > $capacity) {
                continue;
            }
            $wpdb->update(self::table(), ['status' => 'going', 'updated' => time()], ['id' => $r['id']]);
            $held += $need;
            $r['status'] = 'going';
            $moved[] = $r;
        }
        return $moved;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        $attendees = json_decode((string) ($row['attendees'] ?? ''), true);
        $checkins = json_decode((string) ($row['checkins'] ?? ''), true);
        $proofs = json_decode((string) ($row['proofs'] ?? ''), true);
        return [
            'id'           => (string) $row['id'],
            'event_id'     => (int) $row['event_id'],
            'user_id'      => (int) $row['user_id'],
            'guest_email'  => (string) $row['guest_email'],
            'name'         => (string) $row['name'],
            'phone'        => (string) $row['phone'],
            'status'       => (string) $row['status'],
            'guests'       => max(1, (int) $row['guests']),
            'amount'       => $row['amount'] === null ? null : (float) $row['amount'],
            'currency'     => (string) $row['currency'],
            'attendees'    => is_array($attendees) ? array_values(array_filter($attendees, 'is_array')) : [],
            'checkins'     => is_array($checkins) ? $checkins : [],
            'checkin_code' => (string) $row['checkin_code'],
            'proofs'       => is_array($proofs) ? array_values(array_filter($proofs, 'is_array')) : [],
            'verified_at'  => (int) ($row['verified_at'] ?? 0),
            'verified_by'  => (int) ($row['verified_by'] ?? 0),
            'created'      => (int) $row['created'],
            'updated'      => (int) $row['updated'],
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function encode(array $r): array
    {
        return [
            'id'           => $r['id'],
            'event_id'     => (int) $r['event_id'],
            'user_id'      => (int) $r['user_id'],
            'guest_email'  => (string) $r['guest_email'],
            'name'         => (string) $r['name'],
            'phone'        => (string) $r['phone'],
            'status'       => (string) $r['status'],
            'guests'       => (int) $r['guests'],
            'amount'       => $r['amount'],
            'currency'     => (string) $r['currency'],
            'attendees'    => $r['attendees'] ? wp_json_encode(array_values($r['attendees'])) : null,
            'checkins'     => $r['checkins'] ? wp_json_encode($r['checkins']) : null,
            'checkin_code' => (string) $r['checkin_code'],
            'proofs'       => !empty($r['proofs']) ? wp_json_encode(array_values($r['proofs'])) : null,
            'verified_at'  => (int) ($r['verified_at'] ?? 0),
            'verified_by'  => (int) ($r['verified_by'] ?? 0),
            'created'      => (int) $r['created'],
            'updated'      => (int) $r['updated'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function cleanAttendees(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $p = [];
            foreach (['name', 'email', 'phone'] as $k) {
                $v = trim((string) ($entry[$k] ?? ''));
                if ($v !== '') {
                    $p[$k] = mb_substr($k === 'email' ? strtolower($v) : $v, 0, 191);
                }
            }
            if (isset($entry['amount']) && $entry['amount'] !== '' && is_numeric($entry['amount'])) {
                $p['amount'] = round(max(0.0, (float) $entry['amount']), 2);
            }
            if ($p !== []) {
                $out[] = $p;
            }
            if (count($out) >= self::MAX_ATTENDEES) {
                break;
            }
        }
        return $out;
    }

    /** A short check-in code with no look-alike characters, easy to read out or type at the door. */
    private static function newCode(): string
    {
        $out = '';
        $max = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < 10; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Digits only, so "097 123 4567" and "097-123-4567" are the same number. */
    private static function phoneKey(string $raw): string
    {
        return (string) preg_replace('/\D+/', '', $raw);
    }
}
