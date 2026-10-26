<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/** Site-wide settings, in one option. */
final class Settings
{
    public const OPTION = 'sepo_events_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'accent'              => '',
            'index_page'          => 0,
            'currency'            => 'ZMW',
            'confirm_emails'      => 1,
            'notify_email'        => '',
            'proof_max_mb'        => 5,
            'delete_on_uninstall' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $o = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($o) ? $o : []);
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    /** The currency an event uses when it names none. */
    public static function currency(): string
    {
        return Event::currency((string) self::get('currency'), 'ZMW');
    }

    /** White or near-black, whichever reads better on the colour. */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!ctype_xdigit($hex) || strlen($hex) !== 6) {
            return '#ffffff';
        }
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($hex, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($hex, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($hex, 4, 2)));
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0575) ? '#ffffff' : '#0b1215';
    }

    /** The page that lists the events, made once if it does not exist. */
    public static function ensureIndexPage(): int
    {
        $id = (int) self::get('index_page');
        if ($id > 0 && get_post_status($id)) {
            return $id;
        }
        $existing = get_page_by_path('events');
        if ($existing && str_contains((string) $existing->post_content, '[sepo_events')) {
            self::update(['index_page' => (int) $existing->ID]);
            return (int) $existing->ID;
        }
        $id = (int) wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Events', 'sepo-events'),
            'post_name'    => 'events',
            'post_excerpt' => __('Upcoming events and workshops.', 'sepo-events'),
            'post_content' => '<!-- wp:shortcode -->[sepo_events]<!-- /wp:shortcode -->',
        ]);
        if ($id > 0) {
            self::update(['index_page' => $id]);
        }
        return $id;
    }

    /** The events list page's address, or the home page when there is none. */
    public static function indexUrl(): string
    {
        $id = (int) self::get('index_page');
        $url = $id > 0 && get_post_status($id) === 'publish' ? get_permalink($id) : '';
        return $url ? (string) $url : home_url('/');
    }
}
