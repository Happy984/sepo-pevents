<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * Events are posts of type sepo_event. The title is the event name, the
 * excerpt its one-line description, the content the details, and the
 * featured image its banner. Schedule, place and registration settings are
 * post meta written by the Event details box.
 *
 * Addresses: /events/<slug>/ shows one event, /events/<slug>.ics is its
 * calendar file. The list of events is a page (made on activation at
 * /events/) holding the [sepo_events] shortcode.
 *
 * Events can be sorted into categories (Workshops, Sports, Services…), which
 * visitors filter by on the events list.
 *
 * A private event uses WordPress's own Private visibility: only people who
 * can edit it see it, and nobody can RSVP to it.
 */
final class PostType
{
    public const TYPE = 'sepo_event';
    public const CATEGORY = 'sepo_event_category';

    public static function hook(): void
    {
        add_action('init', [self::class, 'register']);
        add_filter('manage_' . self::TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . self::TYPE . '_posts_custom_column', [self::class, 'column'], 10, 2);
        add_filter('manage_edit-' . self::TYPE . '_sortable_columns', static function (array $c): array {
            $c['spe_when'] = 'spe_when';
            return $c;
        });
        add_action('pre_get_posts', [self::class, 'sortByStart']);
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
        // An event deleted for good takes its RSVPs with it; the bin keeps them.
        add_action('before_delete_post', static function (int $postId): void {
            if (get_post_type($postId) === self::TYPE) {
                Rsvps::purge($postId);
            }
        });
    }

    public static function register(): void
    {
        register_post_type(self::TYPE, [
            'labels' => [
                'name'                  => __('Events', 'sepo-events'),
                'singular_name'         => __('Event', 'sepo-events'),
                'add_new'               => __('Add event', 'sepo-events'),
                'add_new_item'          => __('Add event', 'sepo-events'),
                'edit_item'             => __('Edit event', 'sepo-events'),
                'new_item'              => __('New event', 'sepo-events'),
                'view_item'             => __('View event', 'sepo-events'),
                'view_items'            => __('View events', 'sepo-events'),
                'search_items'          => __('Search events', 'sepo-events'),
                'not_found'             => __('No events found.', 'sepo-events'),
                'not_found_in_trash'    => __('No events in the bin.', 'sepo-events'),
                'all_items'             => __('All events', 'sepo-events'),
                'menu_name'             => __('Events', 'sepo-events'),
                'featured_image'        => __('Event banner', 'sepo-events'),
                'set_featured_image'    => __('Set event banner', 'sepo-events'),
                'remove_featured_image' => __('Remove event banner', 'sepo-events'),
                'use_featured_image'    => __('Use as event banner', 'sepo-events'),
                'item_published'        => __('Event published.', 'sepo-events'),
                'item_updated'          => __('Event updated.', 'sepo-events'),
            ],
            'public'          => true,
            'show_in_rest'    => true,
            'has_archive'     => false,
            'rewrite'         => ['slug' => 'events', 'with_front' => false],
            'menu_icon'       => 'dashicons-calendar-alt',
            'menu_position'   => 27,
            'supports'        => ['title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'taxonomies'      => [self::CATEGORY],
        ]);
        register_taxonomy(self::CATEGORY, self::TYPE, [
            'labels' => [
                'name'          => __('Event categories', 'sepo-events'),
                'singular_name' => __('Event category', 'sepo-events'),
                'menu_name'     => __('Categories', 'sepo-events'),
                'all_items'     => __('All categories', 'sepo-events'),
                'edit_item'     => __('Edit category', 'sepo-events'),
                'add_new_item'  => __('Add category', 'sepo-events'),
                'new_item_name' => __('New category name', 'sepo-events'),
                'search_items'  => __('Search categories', 'sepo-events'),
                'not_found'     => __('No categories found.', 'sepo-events'),
                'back_to_items' => __('← All categories', 'sepo-events'),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'event-category', 'with_front' => false],
        ]);
    }

    /** @param array<string,string> $cols @return array<string,string> */
    public static function columns(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            if ($k === 'date') {
                continue;
            }
            $out[$k] = $v;
            if ($k === 'title') {
                $out['spe_when']  = __('When', 'sepo-events');
                $out['spe_where'] = __('Where', 'sepo-events');
                $out['spe_seats'] = __('Seats', 'sepo-events');
                $out['spe_rsvps'] = __('RSVPs', 'sepo-events');
            }
        }
        $out['date'] = __('Published', 'sepo-events');
        return $out;
    }

    public static function column(string $col, int $postId): void
    {
        $c = Event::config($postId);
        if ($col === 'spe_when') {
            if ($c['starts_utc'] === null) {
                echo '<span class="spe-muted">' . esc_html__('Date to be announced', 'sepo-events') . '</span>';
                return;
            }
            echo esc_html(Event::local($c['starts_utc'], $c['tz'], $c['all_day'] ? 'D j M Y' : 'D j M Y, H:i'));
            if (Event::isOver($c)) {
                echo ' <span class="spe-badge">' . esc_html__('Past', 'sepo-events') . '</span>';
            } elseif (Event::isLive($c)) {
                echo ' <span class="spe-badge spe-badge--live">' . esc_html__('Now', 'sepo-events') . '</span>';
            }
        } elseif ($col === 'spe_where') {
            echo $c['location'] !== '' ? esc_html($c['location']) : '<span class="spe-muted">—</span>';
        } elseif ($col === 'spe_seats') {
            $n = Rsvps::counts($postId, $c['capacity']);
            echo esc_html($c['capacity'] > 0
                ? sprintf(/* translators: 1: seats taken, 2: capacity */ __('%1$d of %2$d', 'sepo-events'), $n['seats_taken'], $c['capacity'])
                : (string) $n['seats_taken']);
            if ($n['waitlist'] > 0) {
                echo ' <span class="spe-badge spe-badge--wait">' . esc_html(sprintf(/* translators: %d: people waiting */ __('%d waiting', 'sepo-events'), $n['waitlist'])) . '</span>';
            }
            if ($c['closed']) {
                echo ' <span class="spe-badge">' . esc_html__('Closed', 'sepo-events') . '</span>';
            }
        } elseif ($col === 'spe_rsvps') {
            $n = Rsvps::counts($postId, $c['capacity']);
            $url = Admin\Screens::dashboardUrl(['event' => $postId]);
            echo '<a href="' . esc_url($url) . '">' . esc_html(number_format_i18n($n['records'])) . '</a>';
        }
    }

    /** The events list in wp-admin sorts by start time, soonest last, unless asked otherwise. */
    public static function sortByStart(\WP_Query $q): void
    {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== self::TYPE) {
            return;
        }
        $orderby = (string) $q->get('orderby');
        if ($orderby === 'spe_when' || $orderby === '') {
            $q->set('meta_key', Event::STARTS);
            $q->set('orderby', 'meta_value_num');
            if ($orderby === '') {
                $q->set('order', 'DESC');
            }
        }
    }

    /** @param array<string,string> $actions @return array<string,string> */
    public static function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== self::TYPE || !Plugin::canManage()) {
            return $actions;
        }
        $actions['spe_rsvps'] = '<a href="' . esc_url(Admin\Screens::dashboardUrl(['event' => $post->ID])) . '">' . esc_html__('RSVPs', 'sepo-events') . '</a>';
        $actions['spe_cards'] = '<a href="' . esc_url(Admin\Tools::url('cards', ['event' => $post->ID])) . '" target="_blank" rel="noopener">' . esc_html__('Cards', 'sepo-events') . '</a>';
        $actions['spe_checkin'] = '<a href="' . esc_url(Admin\Tools::url('checkin', ['event' => $post->ID])) . '" target="_blank" rel="noopener">' . esc_html__('Check-in', 'sepo-events') . '</a>';
        return $actions;
    }
}
