<?php

declare(strict_types=1);

namespace SepoEvents\Render;

use SepoEvents\Admin\Tools;
use SepoEvents\Frontend;
use SepoEvents\Rest;
use SepoEvents\Settings;

defined('ABSPATH') || exit;

/**
 * The RSVP dashboard: a stats strip, then two tabs — Events (one row per
 * event) and RSVPs (one row per registration, with filters, exports and the
 * destructive actions). Used on Events → RSVPs and by [sepo_event_rsvps].
 *
 * The script reads every address it needs from data attributes, so it
 * never builds a URL of its own.
 */
final class Dashboard
{
    public static function render(int $eventId = 0): string
    {
        Frontend::register();
        wp_enqueue_style('sepo-events-dashboard');
        wp_enqueue_script('sepo-events-dashboard');
        wp_enqueue_script('sepo-events');

        $attrs = [
            'api'           => rest_url(Rest::NS),
            'nonce'         => wp_create_nonce('wp_rest'),
            'events-page'   => Settings::indexUrl(),
            'checkin'       => Tools::url('checkin'),
            'cards'         => Tools::url('cards'),
            'print-events'  => Tools::url('print', ['type' => 'events']),
            'print-rsvps'   => Tools::url('print', ['type' => 'rsvps']),
            'export-events' => Tools::url('export', ['type' => 'events']),
            'export-rsvps'  => Tools::url('export', ['type' => 'rsvps']),
            'new-event'     => admin_url('post-new.php?post_type=sepo_event'),
            'event'         => (string) $eventId,
        ];
        $data = '';
        foreach ($attrs as $k => $v) {
            $data .= ' data-eva-' . $k . '="' . esc_attr($v) . '"';
        }

        $t = static fn(string $s): string => esc_html($s);
        $loading = str_repeat('<div class="eva-tile"><b>—</b><span>' . $t(__('Loading', 'sepo-events')) . '</span></div>', 8);

        return '<div class="eva-scope"><div class="eva-wrap" data-eva-root' . $data . '>'
            . '<div class="eva-head"><div><h2>' . $t(__('Event RSVPs', 'sepo-events')) . '</h2><p>' . $t(__('Every event, every seat, every RSVP — in one place.', 'sepo-events')) . '</p></div>'
            . '<div class="eva-actions">'
            . '<button type="button" class="eva-btn" data-eva-refresh>' . $t(__('Refresh', 'sepo-events')) . '</button>'
            . '<a class="eva-btn" href="' . esc_url(Settings::indexUrl()) . '">' . $t(__('Open events page', 'sepo-events')) . '</a>'
            . '<a class="eva-btn eva-btn--primary" href="' . esc_url(Tools::url('checkin', $eventId > 0 ? ['event' => $eventId] : [])) . '" target="_blank" rel="noopener" data-eva-scan-link>' . $t(__('Scan / check-in', 'sepo-events')) . '</a>'
            . '</div></div>'
            . '<div class="eva-msg" data-eva-msg role="status" hidden></div>'
            . '<div class="eva-tiles" data-eva-tiles>' . $loading . '</div>'
            . '<div class="eva-tabs" role="tablist">'
            . '<button type="button" class="eva-tab is-on" role="tab" aria-selected="true" data-eva-tab="events">' . $t(__('Events', 'sepo-events')) . ' <span class="eva-count" data-eva-event-count>0</span></button>'
            . '<button type="button" class="eva-tab" role="tab" aria-selected="false" data-eva-tab="rsvps">' . $t(__('RSVPs', 'sepo-events')) . ' <span class="eva-count" data-eva-rsvp-count>0</span></button>'
            . '</div>'

            . '<div class="eva-panel is-on" data-eva-panel="events"><section class="eva-sec">'
            . '<div class="eva-sec-head"><span class="eva-sec-title">' . $t(__('Every event on the site', 'sepo-events')) . '</span>'
            . '<div class="eva-export-group"><a class="eva-btn eva-btn--sm" data-eva-export-events-csv href="#">' . $t(__('Export CSV', 'sepo-events')) . '</a>'
            . '<a class="eva-btn eva-btn--sm" data-eva-export-events-pdf href="#" target="_blank" rel="noopener">' . $t(__('Export PDF', 'sepo-events')) . '</a></div></div>'
            . '<div class="eva-scroll" data-eva-events><div class="eva-empty">' . $t(__('Loading events…', 'sepo-events')) . '</div></div>'
            . '<p class="eva-note">' . wp_kses(__('<strong>Seats taken</strong> is a headcount — the sum of the seats each registration claimed. <strong>Going</strong> is the number of registrations behind it. An event that was deleted but still has RSVPs is marked <code>no page</code>; its records are still counted. <strong>Cards</strong> opens printable attendee badges with a QR check-in code; <strong>Scan</strong> opens the door check-in for that event.', 'sepo-events'), ['strong' => [], 'code' => []]) . '</p>'
            . '</section></div>'

            . '<div class="eva-panel" data-eva-panel="rsvps"><section class="eva-sec">'
            . '<div class="eva-filter">'
            . '<label><span>' . $t(__('Event', 'sepo-events')) . '</span><select data-eva-filter-event><option value="">' . $t(__('All events', 'sepo-events')) . '</option></select></label>'
            . '<label><span>' . $t(__('Status', 'sepo-events')) . '</span><select data-eva-filter-status>'
            . '<option value="">' . $t(__('Any status', 'sepo-events')) . '</option>'
            . '<option value="going">' . $t(__('Going', 'sepo-events')) . '</option>'
            . '<option value="waitlist">' . $t(__('Waitlist', 'sepo-events')) . '</option>'
            . '<option value="cancelled">' . $t(__('Cancelled', 'sepo-events')) . '</option></select></label>'
            . '<label class="eva-search"><span>' . $t(__('Find', 'sepo-events')) . '</span><input type="search" data-eva-search placeholder="' . esc_attr__('Name, email, phone or code', 'sepo-events') . '"></label>'
            . '<button type="button" class="eva-btn" data-eva-apply-filter>' . $t(__('Apply', 'sepo-events')) . '</button>'
            . '<div class="eva-spacer eva-export-group"><a class="eva-btn eva-btn--sm" data-eva-export-rsvps-csv href="#">' . $t(__('Export CSV', 'sepo-events')) . '</a>'
            . '<a class="eva-btn eva-btn--sm" data-eva-export-rsvps-pdf href="#" target="_blank" rel="noopener">' . $t(__('Export PDF', 'sepo-events')) . '</a></div>'
            . '<button type="button" class="eva-btn eva-btn--danger eva-btn--sm" data-eva-purge disabled title="' . esc_attr__('Remove every RSVP for the selected event', 'sepo-events') . '">' . $t(__('Purge event', 'sepo-events')) . '</button>'
            . '</div>'
            . '<div class="eva-scroll" data-eva-rsvps><div class="eva-empty">' . $t(__('Open this tab to load RSVPs.', 'sepo-events')) . '</div></div>'
            . '<p class="eva-note">' . wp_kses(__('<strong>Cancel</strong> keeps the record and marks it <code>cancelled</code> — who withdrew is a question a host sometimes has to answer. <strong>Delete</strong> removes the row entirely. <strong>Purge event</strong> removes every RSVP for the selected event. Neither Delete nor Purge can be undone. When a seat is freed, the oldest waiting registrations that fit move to going and are emailed. Tap a name to see full attendee and check-in details. Exports follow the current filter.', 'sepo-events'), ['strong' => [], 'code' => []]) . '</p>'
            . '</section></div>'

            . self::modal('eva-modal', 'data-eva-modal', '<button type="button" class="eva-btn" data-eva-modal-cancel></button><button type="button" class="eva-btn eva-btn--primary" data-eva-modal-confirm></button>')
            . self::modal('eva-detail', 'data-eva-detail-modal', '<button type="button" class="eva-btn eva-btn--primary" data-eva-detail-close>' . $t(__('Close', 'sepo-events')) . '</button>')
            . '</div></div>'
            . '<script>window.SepoEventsDashboardText=' . wp_json_encode(self::i18n()) . ';</script>';
    }

    private static function modal(string $id, string $attr, string $foot): string
    {
        $isDetail = $id === 'eva-detail';
        return '<div class="eva-modal" ' . $attr . ' hidden role="dialog" aria-modal="true" aria-labelledby="' . $id . '-title">'
            . '<div class="eva-modal__panel">'
            . '<div class="eva-modal__head" id="' . $id . '-title" ' . ($isDetail ? 'data-eva-detail-title' : 'data-eva-modal-title') . '></div>'
            . '<div class="eva-modal__body" ' . ($isDetail ? 'data-eva-detail-body' : 'data-eva-modal-body') . '></div>'
            . '<div class="eva-modal__foot">' . $foot . '</div></div></div>';
    }

    /** @return array<string,string> */
    private static function i18n(): array
    {
        return [
            'events' => __('Events', 'sepo-events'), 'upcomingPast' => __('%1$s upcoming · %2$s past', 'sepo-events'),
            'registrations' => __('Registrations', 'sepo-events'), 'ofEvents' => __('%1$s of %2$s events', 'sepo-events'),
            'seatsTaken' => __('Seats taken', 'sepo-events'), 'leftOf' => __('%1$s left of %2$s', 'sepo-events'), 'noCapped' => __('no capped events', 'sepo-events'),
            'waitlist' => __('Waitlist', 'sepo-events'), 'cancelled' => __('Cancelled', 'sepo-events'), 'checkedIn' => __('Checked in', 'sepo-events'),
            'full' => __('Full events', 'sepo-events'), 'collected' => __('Collected', 'sepo-events'), 'cancelledMoney' => __('%s cancelled', 'sepo-events'),
            'noEvents' => __('No events yet.', 'sepo-events'), 'addEvent' => __('Add an event', 'sepo-events'),
            'colEvent' => __('Event', 'sepo-events'), 'colWhen' => __('When', 'sepo-events'), 'colLocation' => __('Location', 'sepo-events'),
            'colCapacity' => __('Capacity', 'sepo-events'), 'colTaken' => __('Seats taken', 'sepo-events'), 'colLeft' => __('Seats left', 'sepo-events'),
            'colGoing' => __('Going', 'sepo-events'), 'colWaitlist' => __('Waitlist', 'sepo-events'), 'colCancelled' => __('Cancelled', 'sepo-events'),
            'colChecked' => __('Checked in', 'sepo-events'), 'colCollected' => __('Collected', 'sepo-events'), 'colRegistered' => __('Registered', 'sepo-events'),
            'colRegistrant' => __('Registrant', 'sepo-events'), 'colAttendees' => __('Attendees', 'sepo-events'), 'colSeats' => __('Seats', 'sepo-events'), 'colStatus' => __('Status', 'sepo-events'),
            'noPage' => __('no page', 'sepo-events'), 'private' => __('private', 'sepo-events'), 'draft' => __('draft', 'sepo-events'), 'past' => __('past', 'sepo-events'), 'fullTag' => __('full', 'sepo-events'),
            'cards' => __('Cards', 'sepo-events'), 'scan' => __('Scan', 'sepo-events'), 'edit' => __('Edit', 'sepo-events'), 'rsvps' => __('RSVPs', 'sepo-events'),
            'allEvents' => __('All events', 'sepo-events'), 'none' => __('(none)', 'sepo-events'),
            'noMatch' => __('No RSVPs match the filter.', 'sepo-events'), 'newest' => __('Showing the newest %s. Narrow by event to see the rest.', 'sepo-events'),
            'loadingRsvps' => __('Loading RSVPs…', 'sepo-events'), 'couldNotLoad' => __('%1$s could not be loaded: %2$s', 'sepo-events'), 'someFailed' => __('Some sections could not be loaded.', 'sepo-events'),
            'checkedInTag' => __('checked in', 'sepo-events'), 'notCheckedIn' => __('not checked in', 'sepo-events'), 'paid' => __('Paid %s', 'sepo-events'),
            'seatsNoDetails' => __('%s seats, no attendee details recorded', 'sepo-events'), 'attendeeN' => __('Attendee %s', 'sepo-events'),
            'cancel' => __('Cancel', 'sepo-events'), 'delete' => __('Delete', 'sepo-events'), 'card' => __('Card', 'sepo-events'),
            'going' => __('going', 'sepo-events'), 'waitlistS' => __('waitlist', 'sepo-events'), 'cancelledS' => __('cancelled', 'sepo-events'),
            'dEvent' => __('Event', 'sepo-events'), 'dStatus' => __('Status', 'sepo-events'), 'dSeats' => __('Seats', 'sepo-events'), 'dEmail' => __('Email', 'sepo-events'),
            'dPhone' => __('Phone', 'sepo-events'), 'dPaid' => __('Paid', 'sepo-events'), 'dCode' => __('Check-in code', 'sepo-events'), 'dRegChecked' => __('Registrant checked in', 'sepo-events'),
            'yes' => __('Yes', 'sepo-events'), 'no' => __('No', 'sepo-events'), 'dAttendees' => __('Attendees', 'sepo-events'), 'registration' => __('Registration', 'sepo-events'),
            'cancelTitle' => __('Cancel this RSVP?', 'sepo-events'), 'cancelBody' => __('The seat is freed and the record is marked cancelled. It stays in the table.', 'sepo-events'),
            'cancelYes' => __('Cancel RSVP', 'sepo-events'), 'keepIt' => __('Keep it', 'sepo-events'),
            'deleteTitle' => __('Delete this RSVP?', 'sepo-events'), 'deleteBody' => __('This removes the row entirely. The record is gone — no undo, no history.', 'sepo-events'), 'deleteYes' => __('Delete permanently', 'sepo-events'),
            'purgeTitle' => __('Purge every RSVP for this event?', 'sepo-events'), 'purgeBody' => __("Remove every RSVP for \"%s\".\n\nThis cannot be undone.", 'sepo-events'), 'purgeYes' => __('Purge event', 'sepo-events'), 'keepThem' => __('Keep them', 'sepo-events'),
            'cancelledMsg' => __('RSVP cancelled.', 'sepo-events'), 'deletedMsg' => __('RSVP deleted.', 'sepo-events'), 'purgedMsg' => __('Purged %s RSVPs.', 'sepo-events'), 'movedUp' => __('%s moved up from the waitlist.', 'sepo-events'),
            'failed' => __('%1$s failed: %2$s', 'sepo-events'), 'selectEvent' => __('Select an event first.', 'sepo-events'),
            'proofChecked' => __('payment checked', 'sepo-events'), 'proofToCheck' => __('proof to check', 'sepo-events'),
            'dProof' => __('Proof of payment', 'sepo-events'), 'noProof' => __('No proof attached.', 'sepo-events'),
            'markChecked' => __('Mark payment as checked', 'sepo-events'), 'unmark' => __('Undo checked', 'sepo-events'),
            'checkedBy' => __('Checked by %1$s, %2$s', 'sepo-events'), 'checkedMsg' => __('Payment marked as checked.', 'sepo-events'), 'uncheckedMsg' => __('Payment check removed.', 'sepo-events'),
            'open' => __('Open', 'sepo-events'),
            'delete_' => __('Delete', 'sepo-events'), 'restore' => __('Restore', 'sepo-events'), 'deleteForever' => __('Delete permanently', 'sepo-events'),
            'inBin' => __('in bin', 'sepo-events'), 'removeRsvps' => __('Remove RSVPs', 'sepo-events'),
            'trashTitle' => __('Move "%s" to the bin?', 'sepo-events'),
            'trashBody' => __('The event page goes offline and it disappears from the events list. Its RSVPs are kept, and you can restore it from this table or from Events → Bin.', 'sepo-events'),
            'trashYes' => __('Move to bin', 'sepo-events'),
            'foreverTitle' => __('Delete "%s" permanently?', 'sepo-events'),
            'foreverBody' => __("This deletes the event and its %s RSVPs, with any proof of payment files.\n\nIt cannot be undone.", 'sepo-events'),
            'orphanTitle' => __('Remove the RSVPs for "%s"?', 'sepo-events'),
            'orphanBody' => __("The event itself is already gone. This removes its %s RSVPs and any proof of payment files.\n\nIt cannot be undone.", 'sepo-events'),
            'trashedMsg' => __('Event moved to the bin.', 'sepo-events'), 'restoredMsg' => __('Event restored.', 'sepo-events'), 'deletedEventMsg' => __('Event deleted with %s RSVPs.', 'sepo-events'),
        ];
    }
}
