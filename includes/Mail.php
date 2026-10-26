<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * Emails sent through wp_mail, so whatever mail set-up the site uses
 * (an SMTP plugin, a transactional mail service) carries them.
 *
 *   to the registrant   when they reserve, join the waitlist, move up from
 *                       the waitlist, or cancel (Events → Settings, on by
 *                       default)
 *   to the host         a copy of every new RSVP, and a note when proof of
 *                       payment is added later, when an address is set
 */
final class Mail
{
    /** @param array<string,mixed> $record @param array<string,mixed> $c */
    public static function confirmation(array $record, array $c, string $kind): void
    {
        $to = (string) $record['guest_email'];
        if ($to === '' && (int) $record['user_id'] > 0) {
            $u = get_userdata((int) $record['user_id']);
            $to = $u ? (string) $u->user_email : '';
        }
        if ($to === '' || !is_email($to) || !Settings::get('confirm_emails')) {
            return;
        }
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $subjects = [
            /* translators: %s: event title */
            'going'     => __('You are going: %s', 'sepo-events'),
            /* translators: %s: event title */
            'waitlist'  => __('You are on the waitlist: %s', 'sepo-events'),
            /* translators: %s: event title */
            'promoted'  => __('A seat opened up — you are going: %s', 'sepo-events'),
            /* translators: %s: event title */
            'cancelled' => __('RSVP cancelled: %s', 'sepo-events'),
        ];
        $intro = [
            'going'     => __('Your place is confirmed.', 'sepo-events'),
            'waitlist'  => __('The event is full, so you are on the waitlist. We will email you if a seat opens up.', 'sepo-events'),
            'promoted'  => __('A seat opened up and your place is now confirmed.', 'sepo-events'),
            'cancelled' => __('Your RSVP has been cancelled.', 'sepo-events'),
        ];
        $name = trim((string) $record['name']);
        /* translators: %s: person's name */
        $lines = [$name !== '' ? sprintf(__('Hello %s,', 'sepo-events'), $name) : __('Hello,', 'sepo-events'), '', $intro[$kind] ?? '', ''];
        $lines[] = $c['title'];
        $when = self::whenLine($c);
        if ($when !== '') {
            $lines[] = $when;
        }
        if ($c['location'] !== '') {
            $lines[] = $c['location'];
        }
        if ($kind !== 'cancelled') {
            /* translators: %d: number of seats */
            $lines[] = sprintf(_n('%d seat', '%d seats', (int) $record['guests'], 'sepo-events'), (int) $record['guests']);
        }
        if (in_array($kind, ['going', 'promoted'], true) && $record['checkin_code'] !== '') {
            $lines[] = '';
            /* translators: %s: check-in code */
            $lines[] = sprintf(__('Your check-in code: %s', 'sepo-events'), $record['checkin_code']);
            foreach ($record['attendees'] as $a) {
                if (!empty($a['name']) && !empty($a['code'])) {
                    $lines[] = sprintf('%s: %s', $a['name'], $a['code']);
                }
            }
        }
        $lines[] = '';
        if ($kind !== 'cancelled') {
            /* translators: %s: link */
            $lines[] = sprintf(__('Event page: %s', 'sepo-events'), self::manageUrl($record, $c));
            /* translators: %s: link */
            $lines[] = sprintf(__('Add to your calendar: %s', 'sepo-events'), Event::icsUrl((int) $c['id']));
            $lines[] = __('Plans changed? Open the event page to cancel your RSVP.', 'sepo-events');
            $lines[] = '';
        }
        $lines[] = $site;

        wp_mail($to, sprintf($subjects[$kind] ?? '%s', $c['title']), implode("\n", $lines));
    }

    /** A copy of a new RSVP to the host address. @param array<string,mixed> $record @param array<string,mixed> $c */
    public static function host(array $record, array $c): void
    {
        $to = trim((string) Settings::get('notify_email'));
        if ($to === '' || !is_email($to)) {
            return;
        }
        $lines = [
            /* translators: %s: event title */
            sprintf(__('New RSVP for %s', 'sepo-events'), $c['title']),
            '',
            /* translators: %s: name */
            sprintf(__('Name: %s', 'sepo-events'), $record['name'] !== '' ? $record['name'] : '—'),
            /* translators: %s: email */
            sprintf(__('Email: %s', 'sepo-events'), $record['guest_email'] !== '' ? $record['guest_email'] : '—'),
        ];
        if ($record['phone'] !== '') {
            /* translators: %s: phone */
            $lines[] = sprintf(__('Phone: %s', 'sepo-events'), $record['phone']);
        }
        /* translators: %d: seats */
        $lines[] = sprintf(__('Seats: %d', 'sepo-events'), (int) $record['guests']);
        /* translators: %s: status */
        $lines[] = sprintf(__('Status: %s', 'sepo-events'), $record['status'] === 'going' ? __('going', 'sepo-events') : __('waitlist', 'sepo-events'));
        $total = Rsvps::money($record);
        if ($total > 0) {
            /* translators: %s: money */
            $lines[] = sprintf(__('Recorded payment: %s', 'sepo-events'), Event::money($total, (string) $c['currency']));
        }
        if (!empty($record['proofs'])) {
            /* translators: %d: number of files */
            $lines[] = sprintf(_n('Proof of payment: %d file attached', 'Proof of payment: %d files attached', count($record['proofs']), 'sepo-events'), count($record['proofs']));
        }
        $lines[] = '';
        /* translators: %s: link */
        $lines[] = sprintf(__('All RSVPs: %s', 'sepo-events'), Admin\Screens::dashboardUrl(['event' => $c['id']]));
        /* translators: %s: event title */
        wp_mail($to, sprintf(__('New RSVP: %s', 'sepo-events'), $c['title']), implode("\n", $lines));
    }

    /** A note to the host that proof of payment arrived on an existing RSVP. @param array<string,mixed> $record @param array<string,mixed> $c */
    public static function proofAdded(array $record, array $c, int $files): void
    {
        $to = trim((string) Settings::get('notify_email'));
        if ($to === '' || !is_email($to)) {
            return;
        }
        $who = $record['name'] !== '' ? $record['name'] : $record['guest_email'];
        $lines = [
            /* translators: 1: person, 2: number of files, 3: event title */
            sprintf(_n('%1$s attached %2$d proof of payment file for %3$s.', '%1$s attached %2$d proof of payment files for %3$s.', $files, 'sepo-events'), $who, $files, $c['title']),
            '',
            /* translators: %s: link */
            sprintf(__('Check it here: %s', 'sepo-events'), Admin\Screens::dashboardUrl(['event' => $c['id']])),
        ];
        /* translators: %s: event title */
        wp_mail($to, sprintf(__('Proof of payment to check: %s', 'sepo-events'), $c['title']), implode("\n", $lines));
    }

    /**
     * The event page, carrying the guest's key so the link opens their own
     * RSVP on any device. Signed-in users get the plain address.
     *
     * @param array<string,mixed> $record @param array<string,mixed> $c
     */
    public static function manageUrl(array $record, array $c): string
    {
        if ((int) $record['user_id'] > 0) {
            return (string) $c['url'];
        }
        return add_query_arg(['spe_key' => Rsvps::key((string) $record['id'])], (string) $c['url']) . '#rsvp';
    }

    /** @param array<string,mixed> $c */
    private static function whenLine(array $c): string
    {
        if ($c['starts_utc'] === null) {
            return '';
        }
        if ($c['all_day']) {
            return Event::local($c['starts_utc'], $c['tz'], 'l, j F Y');
        }
        return Event::local($c['starts_utc'], $c['tz'], 'l, j F Y, H:i') . ($c['ends_utc'] ? '–' . Event::local($c['ends_utc'], $c['tz'], 'H:i') : '') . ' (' . $c['tz'] . ')';
    }
}
