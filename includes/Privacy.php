<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * WordPress's own privacy tools (Tools → Export Personal Data and Erase
 * Personal Data) include RSVPs, and the privacy policy guide gets a
 * suggested paragraph.
 */
final class Privacy
{
    public static function hook(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['sepo-events'] = ['exporter_friendly_name' => __('Event RSVPs', 'sepo-events'), 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['sepo-events'] = ['eraser_friendly_name' => __('Event RSVPs', 'sepo-events'), 'callback' => [self::class, 'erase']];
            return $e;
        });
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content(__('Sepo Events', 'sepo-events'), wpautop(__('When you RSVP to an event we store the name, email address and phone number you give us, the names and contact details of anyone you register with you, any payment amount you record and any proof of payment you attach, and whether each person checked in. We use them to manage the event and to email you about your place. If you RSVP without signing in, a cookie in your browser remembers your RSVP so you can see or cancel it.', 'sepo-events')));
            }
        });
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        foreach (Rsvps::forEmail($email) as $r) {
            $post = get_post((int) $r['event_id']);
            $items = [
                ['name' => __('Event', 'sepo-events'), 'value' => $post ? get_the_title($post) : '#' . $r['event_id']],
                ['name' => __('Status', 'sepo-events'), 'value' => $r['status']],
                ['name' => __('Name', 'sepo-events'), 'value' => $r['name']],
                ['name' => __('Email', 'sepo-events'), 'value' => $r['guest_email']],
                ['name' => __('Phone', 'sepo-events'), 'value' => $r['phone']],
                ['name' => __('Seats', 'sepo-events'), 'value' => (string) $r['guests']],
                ['name' => __('Registered', 'sepo-events'), 'value' => wp_date('Y-m-d H:i', (int) $r['created'])],
            ];
            foreach ($r['proofs'] as $p) {
                $items[] = ['name' => __('Proof of payment file', 'sepo-events'), 'value' => (string) ($p['name'] ?? '')];
            }
            foreach ($r['attendees'] as $a) {
                if (strtolower((string) ($a['email'] ?? '')) === strtolower($email)) {
                    $items[] = ['name' => __('Registered as a guest of', 'sepo-events'), 'value' => $r['name'] !== '' ? $r['name'] : $r['guest_email']];
                }
            }
            $data[] = ['group_id' => 'sepo-event-rsvps', 'group_label' => __('Event RSVPs', 'sepo-events'), 'item_id' => 'rsvp-' . $r['id'], 'data' => $items];
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        $n = Rsvps::eraseEmail($email);
        return ['items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
