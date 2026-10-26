<?php

declare(strict_types=1);

namespace SepoEvents;

use SepoEvents\Render\Dashboard;
use SepoEvents\Render\EventView;
use SepoEvents\Render\ListView;

defined('ABSPATH') || exit;

/**
 * Events on the site: each event's own page, the calendar file, the
 * shortcodes, and the scripts and styles they need.
 *
 *   [sepo_events]                 the events list (show, per_page, view, category, toolbar, limit, past, title)
 *   [sepo_event id="12"]          one event with its RSVP form, anywhere
 *   [sepo_event_rsvps]            the RSVP dashboard, for managers
 */
final class Frontend
{
    public static function hook(): void
    {
        add_action('init', [self::class, 'rewrites']);
        add_filter('query_vars', static function (array $v): array {
            $v[] = 'spe_ics';
            $v[] = 'spe_ics_slug';
            return $v;
        });
        add_action('template_redirect', [self::class, 'ics'], 1);
        add_action('template_redirect', [self::class, 'guestKey'], 2);
        add_action('template_redirect', static function (): void {
            // A signed-in visitor or a guest with an RSVP sees their own state: keep that page out of shared caches.
            if (is_singular(PostType::TYPE) && (is_user_logged_in() || Rsvps::fromCookie((int) get_queried_object_id()))) {
                nocache_headers();
            }
        });
        add_action('wp_enqueue_scripts', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'register']);
        add_action('enqueue_block_assets', [self::class, 'register']);
        add_filter('the_content', [self::class, 'content'], 20);
        add_action('init', static function (): void {
            add_shortcode('sepo_events', [self::class, 'listShortcode']);
            add_shortcode('sepo_event', [self::class, 'eventShortcode']);
            add_shortcode('sepo_event_rsvps', [self::class, 'dashboardShortcode']);
        });
        add_filter('body_class', static function (array $c): array {
            if (is_singular(PostType::TYPE)) {
                $c[] = 'sepo-event-page';
            }
            return $c;
        });
    }

    /**
     * Event pages and the events list sit in the theme's content column, like
     * any page content. A site can widen them to the theme's wide width with
     * add_filter('sepo_events_wide', '__return_true'); themes differ in how
     * they draw wide content, so it is off by default.
     */
    public static function wideClass(): string
    {
        return apply_filters('sepo_events_wide', false) ? ' alignwide' : '';
    }

    /** /events/<slug>.ics serves the calendar file. */
    public static function rewrites(): void
    {
        add_rewrite_rule('^events/([^/]+)\.ics$', 'index.php?spe_ics_slug=$matches[1]', 'top');
    }

    public static function register(): void
    {
        if (wp_style_is('sepo-events', 'registered')) {
            return;
        }
        wp_register_style('sepo-events', SPE_URL . 'assets/css/events.css', [], Plugin::assetVersion('assets/css/events.css'));
        wp_register_script('sepo-events', SPE_URL . 'assets/js/events.js', [], Plugin::assetVersion('assets/js/events.js'), true);
        wp_register_style('sepo-events-dashboard', SPE_URL . 'assets/css/dashboard.css', [], Plugin::assetVersion('assets/css/dashboard.css'));
        wp_register_script('sepo-events-dashboard', SPE_URL . 'assets/js/dashboard.js', [], Plugin::assetVersion('assets/js/dashboard.js'), true);
        $accent = (string) Settings::get('accent');
        if ($accent !== '') {
            $css = '.ev-scope,.eva-scope{--ev-accent:' . $accent . ';--ev-on-accent:' . Settings::textOn($accent) . ';}';
            wp_add_inline_style('sepo-events', $css);
            wp_add_inline_style('sepo-events-dashboard', $css);
        }
        wp_add_inline_script('sepo-events', 'window.SepoEventsConfig=' . wp_json_encode(self::config()) . ';', 'before');
    }

    public static function enqueue(): void
    {
        self::register();
        wp_enqueue_style('sepo-events');
        wp_enqueue_script('sepo-events');
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        return [
            'api'   => esc_url_raw(rest_url(Rest::NS)),
            // Only signed-in visitors get a nonce: a cached page would carry a stale one for guests.
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'i18n'  => [
                'attendee'       => __('Attendee %d', 'sepo-events'),
                'fullName'       => __('Full name', 'sepo-events'),
                'attendeeName'   => __('Attendee name', 'sepo-events'),
                'email'          => __('Email', 'sepo-events'),
                'phone'          => __('Phone', 'sepo-events'),
                'amountPaid'     => __('Amount paid (%s)', 'sepo-events'),
                'totalOne'       => __('Expected total for %d seat', 'sepo-events'),
                'totalMany'      => __('Expected total for %d seats', 'sepo-events'),
                'reserving'      => __('Reserving…', 'sepo-events'),
                'cancelling'     => __('Cancelling…', 'sepo-events'),
                'required'       => __('Please fill in the highlighted fields.', 'sepo-events'),
                'badEmail'       => __('That email address does not look right.', 'sepo-events'),
                'network'        => __('Could not reach the site. Check your connection and try again.', 'sepo-events'),
                'cancelTitle'    => __('Cancel your RSVP?', 'sepo-events'),
                'cancelBody'     => __('Your seat is released for someone else. You can RSVP again later while seats remain.', 'sepo-events'),
                'leaveTitle'     => __('Leave the waitlist?', 'sepo-events'),
                'leaveBody'      => __('You will no longer be offered a seat if one opens up.', 'sepo-events'),
                'keep'           => __('Keep it', 'sepo-events'),
                'confirmCancel'  => __('Cancel RSVP', 'sepo-events'),
                'confirmLeave'   => __('Leave waitlist', 'sepo-events'),
                'uploading'      => __('Uploading…', 'sepo-events'),
                'proofNeeded'    => __('Attach your proof of payment.', 'sepo-events'),
                'proofChoose'    => __('Choose a file to attach.', 'sepo-events'),
                /* translators: %s: file name */
                'proofType'      => __('"%s" is not an accepted file type.', 'sepo-events'),
                /* translators: 1: file name, then size in MB */
                'proofSize'      => __('"%s" is larger than %d MB.', 'sepo-events'),
                /* translators: %d: number of files */
                'proofCount'     => __('Attach at most %d files.', 'sepo-events'),
                /* translators: %s: file name */
                'removeFile'     => __('Remove %s', 'sepo-events'),
                'prev'           => __('Previous', 'sepo-events'),
                'next'           => __('Next', 'sepo-events'),
                /* translators: 1: first shown, 2: last shown, 3: total */
                'showing'        => __('Showing %1$s–%2$s of %3$s events', 'sepo-events'),
                /* translators: %s: page number */
                'page'           => __('Page %s', 'sepo-events'),
            ],
        ];
    }

    public static function content(string $content): string
    {
        if (!is_singular(PostType::TYPE) || !in_the_loop() || !is_main_query() || get_post_type() !== PostType::TYPE) {
            return $content;
        }
        static $done = [];
        $id = (int) get_the_ID();
        if (isset($done[$id])) {
            return $content;
        }
        $done[$id] = true;
        self::enqueue();
        // The theme already prints the title above the content.
        return EventView::render($id, ['showTitle' => (bool) apply_filters('sepo_events_show_title_on_event_page', false, $id), 'body' => $content]);
    }

    /** @param array<string,string>|string $atts */
    public static function listShortcode($atts): string
    {
        $a = shortcode_atts(['show' => 'all', 'limit' => 0, 'past' => 0, 'title' => '', 'per_page' => 9, 'view' => 'grid', 'category' => '', 'toolbar' => 'yes'], (array) $atts, 'sepo_events');
        self::enqueue();
        return ListView::render([
            'show'     => (string) $a['show'],
            'limit'    => (int) $a['limit'],
            'past'     => (int) $a['past'],
            'title'    => (string) $a['title'],
            'per_page' => (int) $a['per_page'],
            'view'     => (string) $a['view'],
            'category' => (string) $a['category'],
            'toolbar'  => !in_array(strtolower((string) $a['toolbar']), ['no', '0', 'false'], true),
        ]);
    }

    /** @param array<string,string>|string $atts */
    public static function eventShortcode($atts): string
    {
        $a = shortcode_atts(['id' => 0, 'slug' => '', 'title' => 'yes'], (array) $atts, 'sepo_event');
        $id = absint($a['id']);
        if ($id === 0 && $a['slug'] !== '') {
            $p = get_page_by_path(sanitize_title((string) $a['slug']), OBJECT, PostType::TYPE);
            $id = $p ? (int) $p->ID : 0;
        }
        $post = get_post($id);
        if (!$post || $post->post_type !== PostType::TYPE || !in_array($post->post_status, ['publish', 'private'], true)) {
            return current_user_can('edit_posts') ? '<p class="ev-error">' . esc_html__('Choose an event: [sepo_event id="…"]', 'sepo-events') . '</p>' : '';
        }
        // An event placed inside its own text would render itself forever.
        static $rendering = [];
        if (isset($rendering[$id])) {
            return '';
        }
        $rendering[$id] = true;
        self::enqueue();
        $body = (string) apply_filters('the_content', (string) $post->post_content);
        $html = EventView::render($id, ['showTitle' => !in_array(strtolower((string) $a['title']), ['no', '0', 'false'], true), 'body' => $body]);
        unset($rendering[$id]);
        return $html;
    }

    public static function dashboardShortcode(): string
    {
        self::register();
        if (!Plugin::canManage()) {
            wp_enqueue_style('sepo-events');
            return '<div class="ev-scope"><div class="ev-wrap"><div class="ev-empty">' . esc_html__('You need permission to manage events to see RSVPs.', 'sepo-events') . '</div></div></div>';
        }
        return Dashboard::render();
    }

    /** The calendar file for one event, in iCalendar format. */
    public static function ics(): void
    {
        $id = absint(get_query_var('spe_ics'));
        $slug = sanitize_title((string) get_query_var('spe_ics_slug'));
        if ($id === 0 && $slug === '') {
            return;
        }
        $post = $id > 0 ? get_post($id) : get_page_by_path($slug, OBJECT, PostType::TYPE);
        if (!$post || $post->post_type !== PostType::TYPE || $post->post_status !== 'publish' || post_password_required($post)) {
            status_header(404);
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__('Event not found.', 'sepo-events');
            exit;
        }
        $c = Event::config($post);
        if ($c['starts_utc'] === null) {
            status_header(422);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html__('This event has no start time.', 'sepo-events');
            exit;
        }
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . self::icsText((string) get_bloginfo('name')) . '//Sepo Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT'];
        $lines[] = 'UID:sepo-event-' . $post->ID . '@' . ($host ?: 'localhost');
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        if ($c['all_day']) {
            $lines[] = 'DTSTART;VALUE=DATE:' . Event::local($c['starts_utc'], $c['tz'], 'Ymd');
            $lastDay = Event::endsAt($c) ?? $c['starts_utc'];
            $lines[] = 'DTEND;VALUE=DATE:' . Event::local($lastDay + DAY_IN_SECONDS, $c['tz'], 'Ymd');
        } else {
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $c['starts_utc']);
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', (int) Event::endsAt($c));
        }
        $lines[] = 'SUMMARY:' . self::icsText($c['title']);
        if ($c['description'] !== '') {
            $lines[] = 'DESCRIPTION:' . self::icsText($c['description']);
        }
        if ($c['location'] !== '') {
            $lines[] = 'LOCATION:' . self::icsText($c['location']);
        }
        $lines[] = 'URL:' . $c['url'];
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $out = '';
        foreach ($lines as $line) {
            $out .= self::icsFold($line) . "\r\n";
        }
        status_header(200);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($post->post_name ?: 'event') . '.ics"');
        header('Cache-Control: public, max-age=3600');
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- iCalendar text, escaped by icsText()
        exit;
    }

    /**
     * The link in a guest's confirmation email carries their key: store it
     * in this browser and open the RSVP tab without the key in the address.
     */
    public static function guestKey(): void
    {
        if (!is_singular(PostType::TYPE) || !isset($_GET['spe_key'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $id = (int) get_queried_object_id();
        $key = sanitize_text_field(wp_unslash((string) $_GET['spe_key'])); // phpcs:ignore WordPress.Security.NonceVerification
        $record = Rsvps::fromKey($id, $key);
        if ($record) {
            Rsvps::setCookie($id, (string) $record['id']);
        }
        wp_safe_redirect(remove_query_arg('spe_key') . '#rsvp');
        exit;
    }

    private static function icsText(string $v): string
    {
        $v = str_replace('\\', '\\\\', wp_specialchars_decode($v, ENT_QUOTES));
        $v = str_replace(["\r\n", "\r", "\n"], '\\n', $v);
        return str_replace([';', ','], ['\\;', '\\,'], $v);
    }

    /** Fold to 75 octets (RFC 5545) without splitting a UTF-8 character. */
    private static function icsFold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }
        return $out . $line;
    }
}
