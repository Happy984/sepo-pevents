<?php

declare(strict_types=1);

namespace SepoEvents;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined('ABSPATH') || exit;

/**
 * The REST API, under /wp-json/sepo-events/v1.
 *
 *   POST /rsvp            reserve, or join the waitlist        anyone the event allows
 *   POST /cancel          withdraw your own RSVP               owner, guest key or manager
 *   POST /proof           add proof of payment to your RSVP    owner or guest key
 *   GET  /upcoming        the next events, for widgets         anyone
 *   GET  /admin/stats     every event with counts and money    managers
 *   GET  /admin/rsvps     RSVP rows, filtered                  managers
 *   POST /admin/delete    remove one RSVP, or purge an event   managers
 *   POST /admin/checkin   check one person in                  managers
 *   POST /admin/verify    mark a payment as checked            managers
 *   POST /admin/event     move an event to the bin, restore    managers who may
 *                         it, or delete it for good            delete the event
 *
 * /rsvp and /proof take multipart form data when files are attached: proof
 * files under proof[], and attendees as a JSON string.
 *
 * Signed-in requests carry the standard wp_rest nonce. A guest's own RSVP is
 * recognised by the key cookie set when they registered, or by the link in
 * their confirmation email.
 */
final class Rest
{
    public const NS = 'sepo-events/v1';

    public static function hook(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $manager = static fn(): bool => Plugin::canManage();
        register_rest_route(self::NS, '/rsvp', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'rsvp'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/cancel', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'cancel'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/proof', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'proof'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/admin/event', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'event'], 'permission_callback' => $manager]);
        register_rest_route(self::NS, '/admin/verify', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'verify'], 'permission_callback' => $manager]);
        register_rest_route(self::NS, '/upcoming', ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'upcoming'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/admin/stats', ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'stats'], 'permission_callback' => $manager]);
        register_rest_route(self::NS, '/admin/rsvps', ['methods' => WP_REST_Server::READABLE, 'callback' => [self::class, 'rsvps'], 'permission_callback' => $manager]);
        register_rest_route(self::NS, '/admin/delete', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'delete'], 'permission_callback' => $manager]);
        register_rest_route(self::NS, '/admin/checkin', ['methods' => WP_REST_Server::CREATABLE, 'callback' => [self::class, 'checkin'], 'permission_callback' => $manager]);
    }

    // ------------------------------------------------------------ public

    public static function rsvp(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $post = get_post($eventId);
        if (!$post || $post->post_type !== PostType::TYPE || !in_array($post->post_status, ['publish', 'private'], true) || ($post->post_status === 'private' && !Plugin::canManage())) {
            return self::error(__('Event not found.', 'sepo-events'), 404);
        }
        if (trim((string) $req->get_param('sf_website')) !== '') {
            return self::error(__('Your RSVP could not be sent.', 'sepo-events'), 400);
        }
        $c = Event::config($post);
        if ($c['private']) {
            return self::error(__('This event is private. RSVPs are closed.', 'sepo-events'), 403);
        }
        if ($c['closed']) {
            return self::error(__('Registration for this event is closed.', 'sepo-events'), 422);
        }
        if (Event::isOver($c)) {
            return self::error(__('This event has already finished.', 'sepo-events'), 422);
        }
        $userId = get_current_user_id();
        if ($c['require_login'] && $userId === 0) {
            return self::error(__('Please sign in to RSVP.', 'sepo-events'), 401);
        }
        if ($userId === 0 && !self::withinRate()) {
            return self::error(__('Too many RSVPs from this connection. Please try again in a few minutes.', 'sepo-events'), 429);
        }

        $email = sanitize_email((string) $req->get_param('email'));
        if ($email === '' && $userId > 0) {
            $email = (string) wp_get_current_user()->user_email;
        }
        if ($email === '' || !is_email($email)) {
            return self::error(__('Enter a valid email address to RSVP.', 'sepo-events'), 422);
        }
        $name = sanitize_text_field((string) $req->get_param('name'));
        if ($name === '' && $userId > 0) {
            $name = (string) wp_get_current_user()->display_name;
        }
        $phone = sanitize_text_field((string) $req->get_param('phone'));
        if (in_array('name', $c['fields'], true) && $name === '') {
            return self::error(__('Enter your name.', 'sepo-events'), 422);
        }
        if (in_array('phone', $c['fields'], true) && $phone === '') {
            return self::error(__('Enter your phone number.', 'sepo-events'), 422);
        }

        // A guest email already on the list can only be changed by the person holding its key.
        if ($userId === 0) {
            $existing = Rsvps::findFor($eventId, 0, $email);
            $mine = Rsvps::fromCookie($eventId);
            if ($existing && (!$mine || $mine['id'] !== $existing['id'])) {
                return self::error(__('This email already has an RSVP for this event. Use the link in your confirmation email to change or cancel it.', 'sepo-events'), 409);
            }
        }

        $guests = $c['allow_guests'] ? max(1, min($c['max_seats'], (int) $req->get_param('guests'))) : 1;
        $rawAttendees = $req->get_param('attendees');
        if (is_string($rawAttendees)) {
            $rawAttendees = json_decode(wp_unslash($rawAttendees), true);
        }
        $attendees = [];
        if ($c['allow_guests'] && $guests > 1 && is_array($rawAttendees)) {
            $attendees = array_slice(array_values(array_filter($rawAttendees, 'is_array')), 0, $guests - 1);
            $attendees = array_map(static fn(array $a): array => [
                'name'   => sanitize_text_field((string) ($a['name'] ?? '')),
                'email'  => sanitize_email((string) ($a['email'] ?? '')),
                'phone'  => sanitize_text_field((string) ($a['phone'] ?? '')),
                'amount' => $c['is_paid'] && isset($a['amount']) && is_numeric($a['amount']) ? (float) $a['amount'] : '',
            ], $attendees);
        }
        $amount = $c['is_paid'] && is_numeric($req->get_param('amount')) ? (float) $req->get_param('amount') : null;

        // Proof of payment: checked and stored before the RSVP, removed again if the RSVP fails.
        // Proof already sent counts only for the very record this RSVP updates.
        $current = Rsvps::findFor($eventId, $userId, $userId > 0 ? '' : $email);
        $had = $current && $current['status'] !== 'cancelled' ? count($current['proofs']) : 0;
        $uploads = $c['proof'] !== 'off' ? Proofs::fromUpload((array) ($req->get_file_params()['proof'] ?? [])) : [];
        if ($c['proof'] === 'required' && $uploads === [] && $had === 0) {
            return self::error(__('Attach your proof of payment.', 'sepo-events'), 422);
        }
        try {
            $proofs = Proofs::store($eventId, $uploads, $had);
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return self::error($e->getMessage(), 500);
        }

        try {
            $out = Rsvps::rsvp($eventId, [
                'user_id'   => $userId,
                'email'     => $email,
                'name'      => $name,
                'phone'     => $phone,
                'guests'    => $guests,
                'amount'    => $amount,
                'attendees' => $attendees,
                'currency'  => $c['is_paid'] ? $c['currency'] : '',
                'proofs'    => $proofs,
            ], $c['capacity']);
        } catch (\InvalidArgumentException $e) {
            Proofs::remove($eventId, $proofs);
            return self::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Proofs::remove($eventId, $proofs);
            return self::error($e->getMessage() ?: __('Could not record your RSVP. Please try again.', 'sepo-events'), 500);
        }

        $record = $out['record'];
        if ($userId === 0) {
            Rsvps::setCookie($eventId, (string) $record['id']);
        }
        if ($out['created'] || $out['previous'] !== $out['status']) {
            Mail::confirmation($record, $c, $out['status']);
        }
        if ($out['created']) {
            Mail::host($record, $c);
        } elseif ($proofs !== []) {
            Mail::proofAdded($record, $c, count($proofs));
        }
        /**
         * After an RSVP is saved, new or changed.
         *
         * @param array<string,mixed> $record
         * @param array<string,mixed> $event the normalised event
         * @param bool                $created
         */
        do_action('sepo_events_rsvp', $record, $c, $out['created']);

        return self::ok([
            'status'      => $out['status'],
            'seats_taken' => $out['seats_taken'],
            'capacity'    => $out['capacity'],
            'message'     => $out['status'] === 'going' ? __('You are confirmed.', 'sepo-events') : __('The event is full — you are on the waitlist.', 'sepo-events'),
        ]);
    }

    public static function cancel(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $post = get_post($eventId);
        if (!$post || $post->post_type !== PostType::TYPE) {
            return self::error(__('Event not found.', 'sepo-events'), 404);
        }
        $c = Event::config($post);
        $id = sanitize_text_field((string) $req->get_param('id'));
        $userId = get_current_user_id();

        $record = null;
        if ($id !== '' && Plugin::canManage()) {
            $record = Rsvps::findById($eventId, $id);
        }
        if (!$record && $userId > 0) {
            $record = Rsvps::findFor($eventId, $userId);
        }
        if (!$record) {
            $key = sanitize_text_field((string) $req->get_param('key'));
            $record = $key !== '' ? Rsvps::fromKey($eventId, $key) : Rsvps::fromCookie($eventId);
        }
        if (!$record) {
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }

        try {
            $out = Rsvps::cancel($eventId, (string) $record['id'], $c['capacity']);
        } catch (\Throwable $e) {
            return self::error(__('Could not cancel that RSVP.', 'sepo-events'), 500);
        }
        if (!$out['ok']) {
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }
        if ($record['status'] !== 'cancelled' && $out['record']) {
            Mail::confirmation($out['record'], $c, 'cancelled');
        }
        self::announcePromoted($out['promoted'], $c);
        do_action('sepo_events_rsvp_cancelled', $out['record'], $c);
        return self::ok(['cancelled' => true, 'promoted' => count($out['promoted'])]);
    }

    public static function proof(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $post = get_post($eventId);
        if (!$post || $post->post_type !== PostType::TYPE) {
            return self::error(__('Event not found.', 'sepo-events'), 404);
        }
        $c = Event::config($post);
        if ($c['proof'] === 'off') {
            return self::error(__('This event does not take proof of payment.', 'sepo-events'), 422);
        }
        $userId = get_current_user_id();
        $record = $userId > 0 ? Rsvps::findFor($eventId, $userId) : Rsvps::fromCookie($eventId);
        if (!$record && ($key = sanitize_text_field((string) $req->get_param('key'))) !== '') {
            $record = Rsvps::fromKey($eventId, $key);
        }
        if (!$record || $record['status'] === 'cancelled') {
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }
        if (Event::isOver($c)) {
            return self::error(__('This event has already finished.', 'sepo-events'), 422);
        }
        $uploads = Proofs::fromUpload((array) ($req->get_file_params()['proof'] ?? []));
        if ($uploads === []) {
            return self::error(__('Choose a file to attach.', 'sepo-events'), 422);
        }
        try {
            $proofs = Proofs::store($eventId, $uploads, count($record['proofs']));
        } catch (\InvalidArgumentException $e) {
            return self::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return self::error($e->getMessage(), 500);
        }
        $updated = Rsvps::addProofs($eventId, (string) $record['id'], $proofs);
        if (!$updated) {
            Proofs::remove($eventId, $proofs);
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }
        Mail::proofAdded($updated, $c, count($proofs));
        do_action('sepo_events_proof_added', $updated, $c, $proofs);
        return self::ok(['files' => count($updated['proofs']), 'message' => __('Proof of payment received. The organisers will check it.', 'sepo-events')]);
    }

    /**
     * Move an event to the bin (its RSVPs stay), restore it to the status
     * it had, or delete it for good, which removes its RSVPs and proof
     * files. An event that no longer exists only has RSVPs to remove.
     */
    public static function event(WP_REST_Request $req)
    {
        $id = absint($req->get_param('event'));
        $action = sanitize_key((string) $req->get_param('do'));
        if ($id <= 0 || !in_array($action, ['trash', 'restore', 'delete'], true)) {
            return self::error(__('Choose an event and what to do with it.', 'sepo-events'), 422);
        }
        $post = get_post($id);
        if (!$post || $post->post_type !== PostType::TYPE) {
            if ($action !== 'delete') {
                return self::error(__('Event not found.', 'sepo-events'), 404);
            }
            return self::ok(['deleted' => true, 'removed' => Rsvps::purge($id)]);
        }
        if (!current_user_can('delete_post', $id)) {
            return self::error(__('You are not allowed to delete this event.', 'sepo-events'), 403);
        }
        if ($action === 'trash') {
            return wp_trash_post($id) ? self::ok(['trashed' => true]) : self::error(__('The event could not be moved to the bin.', 'sepo-events'), 500);
        }
        if ($action === 'restore') {
            add_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3);
            $ok = wp_untrash_post($id);
            remove_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10);
            return $ok ? self::ok(['restored' => true]) : self::error(__('The event could not be restored.', 'sepo-events'), 500);
        }
        $removed = count(Rsvps::forEvent($id, true));
        return wp_delete_post($id, true) ? self::ok(['deleted' => true, 'removed' => $removed]) : self::error(__('The event could not be deleted.', 'sepo-events'), 500);
    }

    public static function verify(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $id = sanitize_text_field((string) $req->get_param('id'));
        $on = rest_sanitize_boolean($req->get_param('verified'));
        if ($eventId <= 0 || $id === '' || !Rsvps::findById($eventId, $id)) {
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }
        Rsvps::verify($eventId, $id, $on);
        do_action('sepo_events_payment_verified', $id, $on);
        return self::ok(['verified' => $on]);
    }

    public static function upcoming(WP_REST_Request $req): WP_REST_Response
    {
        $limit = max(1, min(50, (int) ($req->get_param('limit') ?: 5)));
        $out = [];
        foreach (Event::list(['when' => 'upcoming', 'limit' => $limit]) as $c) {
            $out[] = [
                'id'          => $c['id'],
                'url'         => $c['url'],
                'title'       => $c['title'],
                'description' => $c['description'],
                'starts_utc'  => $c['starts_utc'],
                'ends_utc'    => $c['ends_utc'],
                'tz'          => $c['tz'],
                'all_day'     => $c['all_day'],
                'location'    => $c['location'],
                'capacity'    => $c['capacity'],
                'paid'        => $c['is_paid'],
                'price'       => $c['price'],
                'currency'    => $c['currency'],
                'ics'         => Event::icsUrl($c['id']),
            ];
        }
        return self::ok(['events' => $out]);
    }

    // ------------------------------------------------------------ managers

    public static function stats(): WP_REST_Response
    {
        $r = Reports::events();
        return self::ok(['events' => $r['events'], 'totals' => $r['totals'], 'at' => gmdate('c')]);
    }

    public static function rsvps(WP_REST_Request $req)
    {
        $status = strtolower(sanitize_key((string) $req->get_param('status')));
        if ($status !== '' && !in_array($status, Rsvps::STATUSES, true)) {
            return self::error(__('Unknown status filter.', 'sepo-events'), 422);
        }
        $r = Reports::rsvps(absint($req->get_param('event')), $status, (int) ($req->get_param('limit') ?: 200));
        return self::ok(['rsvps' => $r['rows'], 'count' => count($r['rows']), 'truncated' => $r['truncated'], 'limit' => $r['limit']]);
    }

    public static function delete(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $id = sanitize_text_field((string) $req->get_param('id'));
        $purge = rest_sanitize_boolean($req->get_param('purge'));
        if ($eventId <= 0) {
            return self::error(__('An event reference is required.', 'sepo-events'), 422);
        }
        if ($purge && $id !== '') {
            return self::error(__('Pass either id or purge, not both.', 'sepo-events'), 422);
        }
        if ($purge) {
            $n = Rsvps::purge($eventId);
            do_action('sepo_events_rsvps_purged', $eventId, $n);
            return self::ok(['purged' => true, 'removed' => $n]);
        }
        if ($id === '') {
            return self::error(__('Pass an id, or purge: true to remove all RSVPs for the event.', 'sepo-events'), 422);
        }
        $post = get_post($eventId);
        $c = $post && $post->post_type === PostType::TYPE ? Event::config($post) : null;
        $out = Rsvps::delete($eventId, $id, $c ? $c['capacity'] : 0);
        if (!$out['ok']) {
            return self::error(__('No RSVP matches that reference.', 'sepo-events'), 404);
        }
        if ($c) {
            self::announcePromoted($out['promoted'], $c);
        }
        return self::ok(['deleted' => true, 'promoted' => count($out['promoted'])]);
    }

    public static function checkin(WP_REST_Request $req)
    {
        $eventId = absint($req->get_param('event'));
        $code = sanitize_text_field((string) $req->get_param('code'));
        if ($eventId <= 0 || $code === '') {
            return self::error(__('An event and a code are required.', 'sepo-events'), 422);
        }
        $post = get_post($eventId);
        if (!$post || $post->post_type !== PostType::TYPE) {
            return self::error(__('Event not found.', 'sepo-events'), 404);
        }
        $r = Rsvps::checkIn($eventId, $code);
        if (!$r['found']) {
            return self::error(__('That code, email or phone was not recognised for this event.', 'sepo-events'), 404);
        }
        if ($r['refused'] === 'cancelled') {
            /* translators: %s: person's name */
            return self::error(sprintf(__('%s cancelled their RSVP. They are not checked in.', 'sepo-events'), $r['person']['name'] !== '' ? $r['person']['name'] : __('This guest', 'sepo-events')), 409);
        }
        if (!$r['already']) {
            do_action('sepo_events_checked_in', $r, $eventId);
        }
        return self::ok($r + ['event' => ['id' => $eventId, 'title' => get_the_title($post)]]);
    }

    // ------------------------------------------------------------ helpers

    /** @param list<array<string,mixed>> $promoted @param array<string,mixed> $c */
    private static function announcePromoted(array $promoted, array $c): void
    {
        foreach ($promoted as $r) {
            Mail::confirmation($r, $c, 'promoted');
            do_action('sepo_events_rsvp_promoted', $r, $c);
        }
    }

    /** Guests may send 20 RSVPs per 10 minutes from one address. */
    private static function withinRate(): bool
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $ip = (string) apply_filters('sepo_events_ip', $ip);
        if ($ip === '') {
            return true;
        }
        $key = 'spe_rate_' . md5($ip);
        $n = (int) get_transient($key);
        if ($n >= 20) {
            return false;
        }
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    /** @param array<string,mixed> $data */
    private static function ok(array $data): WP_REST_Response
    {
        return new WP_REST_Response(['status' => 'success', 'data' => $data], 200);
    }

    private static function error(string $message, int $code): WP_Error
    {
        return new WP_Error('sepo_events', $message, ['status' => $code]);
    }
}
