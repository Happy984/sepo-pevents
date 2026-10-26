<?php

declare(strict_types=1);

namespace SepoEvents\Render;

use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\PostType;
use SepoEvents\Rsvps;

defined('ABSPATH') || exit;

/**
 * The list of events: a toolbar (search, Upcoming / Past / All, category,
 * price, grid or list), the cards, and pages.
 *
 * Every card is in the page, so the list reads without JavaScript and
 * search engines see each event. The script filters and pages the cards in
 * place, with no page loads, and remembers each visitor's grid or list choice.
 */
final class ListView
{
    /** @param array{show?:string,limit?:int,past?:int,title?:string,per_page?:int,view?:string,category?:string,toolbar?:bool} $o */
    public static function render(array $o = []): string
    {
        $show = in_array($o['show'] ?? 'all', ['all', 'upcoming', 'past'], true) ? (string) ($o['show'] ?? 'all') : 'all';
        $limit = max(0, (int) ($o['limit'] ?? 0));
        $pastLimit = max(0, (int) ($o['past'] ?? 0));
        $perPage = max(1, min(60, (int) ($o['per_page'] ?? 9)));
        $view = ($o['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
        $toolbar = $o['toolbar'] ?? true;
        $only = array_filter(array_map('sanitize_title', explode(',', (string) ($o['category'] ?? ''))));
        $now = time();

        $upcoming = [];
        $past = [];
        if ($show !== 'past') {
            $upcoming = Event::list(['when' => 'upcoming']);
            foreach (Event::list() as $c) {
                if ($c['starts_utc'] === null) {
                    $upcoming[] = $c;
                }
            }
        }
        if ($show !== 'upcoming') {
            $past = Event::list(['when' => 'past', 'limit' => $pastLimit]);
        }

        // Category slugs per event, and the categories in use, for the filter.
        $catsOf = [];
        $inUse = [];
        foreach (array_merge($upcoming, $past) as $c) {
            $terms = get_the_terms((int) $c['id'], PostType::CATEGORY);
            $catsOf[$c['id']] = [];
            foreach (is_array($terms) ? $terms : [] as $t) {
                $catsOf[$c['id']][] = $t->slug;
                $inUse[$t->slug] = $t->name;
            }
        }
        if ($only !== []) {
            $keep = static fn(array $c): bool => array_intersect($catsOf[$c['id']], $only) !== [];
            $upcoming = array_values(array_filter($upcoming, $keep));
            $past = array_values(array_filter($past, $keep));
            $inUse = array_intersect_key($inUse, array_flip($only));
        }
        if ($limit > 0) {
            $upcoming = array_slice($upcoming, 0, $limit);
            $past = array_slice($past, 0, $limit);
        }
        asort($inUse, SORT_NATURAL | SORT_FLAG_CASE);

        $hasFree = $hasPaid = false;
        foreach (array_merge($upcoming, $past) as $c) {
            $c['is_paid'] ? $hasPaid = true : $hasFree = true;
        }
        $default = $show === 'all' ? ($upcoming !== [] ? 'upcoming' : 'past') : $show;

        ob_start();
        ?>
<div class="ev-scope<?php echo esc_attr(\SepoEvents\Frontend::wideClass()); ?>">
<div class="ev-wrap ev-list" data-ev-list data-per-page="<?php echo (int) $perPage; ?>" data-view="<?php echo esc_attr($view); ?>" data-default="<?php echo esc_attr($default); ?>">
    <?php if (!empty($o['title'])) : ?>
        <div class="ev-head"><h2><?php echo esc_html((string) $o['title']); ?></h2></div>
    <?php endif; ?>

    <?php if ($upcoming === [] && $past === []) : ?>
        <div class="ev-empty">
            <?php esc_html_e('No events yet.', 'sepo-events'); ?>
            <?php if (Plugin::canManage()) : ?>
                <?php
                /* translators: %s: link */
                echo wp_kses(sprintf(__('Create one under <a href="%s">Events → Add event</a>.', 'sepo-events'), esc_url(admin_url('post-new.php?post_type=sepo_event'))), ['a' => ['href' => []]]);
                ?>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <?php if ($toolbar) : ?>
        <div class="ev-toolbar" data-ev-toolbar hidden>
            <div class="ev-search">
                <?php echo Icons::get('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <input type="search" data-ev-search placeholder="<?php esc_attr_e('Search events…', 'sepo-events'); ?>" aria-label="<?php esc_attr_e('Search events', 'sepo-events'); ?>">
            </div>
            <?php if ($show === 'all' && $upcoming !== [] && $past !== []) : ?>
                <div class="ev-seg" role="group" aria-label="<?php esc_attr_e('Which events', 'sepo-events'); ?>">
                    <button type="button" data-ev-when="upcoming"><?php esc_html_e('Upcoming', 'sepo-events'); ?> <span><?php echo count($upcoming); ?></span></button>
                    <button type="button" data-ev-when="past"><?php esc_html_e('Past', 'sepo-events'); ?> <span><?php echo count($past); ?></span></button>
                    <button type="button" data-ev-when="all"><?php esc_html_e('All', 'sepo-events'); ?> <span><?php echo count($upcoming) + count($past); ?></span></button>
                </div>
            <?php endif; ?>
            <?php if (count($inUse) > 1) : ?>
                <select class="ev-select" data-ev-cat aria-label="<?php esc_attr_e('Category', 'sepo-events'); ?>">
                    <option value=""><?php esc_html_e('All categories', 'sepo-events'); ?></option>
                    <?php foreach ($inUse as $slug => $name) : ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php if ($hasFree && $hasPaid) : ?>
                <select class="ev-select" data-ev-price aria-label="<?php esc_attr_e('Price', 'sepo-events'); ?>">
                    <option value=""><?php esc_html_e('Free and paid', 'sepo-events'); ?></option>
                    <option value="free"><?php esc_html_e('Free', 'sepo-events'); ?></option>
                    <option value="paid"><?php esc_html_e('Paid', 'sepo-events'); ?></option>
                </select>
            <?php endif; ?>
            <div class="ev-view-toggle" role="group" aria-label="<?php esc_attr_e('Layout', 'sepo-events'); ?>">
                <button type="button" data-ev-view="grid" aria-label="<?php esc_attr_e('Grid', 'sepo-events'); ?>"><?php echo Icons::get('grid', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
                <button type="button" data-ev-view="list" aria-label="<?php esc_attr_e('List', 'sepo-events'); ?>"><?php echo Icons::get('list', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
            </div>
        </div>
        <?php endif; ?>

        <div class="ev-grid ev-grid--<?php echo esc_attr($view); ?>" data-ev-cards aria-live="polite">
            <?php foreach ($upcoming as $c) : ?>
                <?php echo self::card($c, $now, false, $catsOf[$c['id']] ?? []); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>
            <?php endforeach; ?>
            <?php foreach ($past as $c) : ?>
                <?php echo self::card($c, $now, true, $catsOf[$c['id']] ?? []); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>
            <?php endforeach; ?>
        </div>
        <div class="ev-empty ev-empty--slim" data-ev-none hidden><?php esc_html_e('No events match your search.', 'sepo-events'); ?></div>
        <nav class="ev-pager" data-ev-pager aria-label="<?php esc_attr_e('Event pages', 'sepo-events'); ?>" hidden></nav>
    <?php endif; ?>
</div>
</div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * One event card: the cover image (the event's featured image), a large
     * date block, then the title, time, place and tags in readable sizes.
     *
     * @param array<string,mixed> $c
     */
    /** @param list<string> $cats */
    private static function card(array $c, int $now, bool $past, array $cats = []): string
    {
        $id = (int) $c['id'];
        $tz = (string) $c['tz'];
        $dated = $c['starts_utc'] !== null;

        $cover = has_post_thumbnail($id)
            ? get_the_post_thumbnail($id, 'medium_large', ['class' => 'ev-card-img', 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'sizes' => '(max-width: 600px) 100vw, 400px'])
            : '';
        $text = mb_strtolower(wp_strip_all_tags($c['title'] . ' ' . $c['location'] . ' ' . $c['description'] . ' ' . $c['host']));
        $out = '<a class="ev-card' . ($cover !== '' ? ' has-cover' : '') . ($past ? ' ev-card--past' : '') . '" href="' . esc_url($c['url']) . '"'
            . ' data-when="' . ($past ? 'past' : 'upcoming') . '" data-price="' . ($c['is_paid'] ? 'paid' : 'free') . '"'
            . ' data-cats="' . esc_attr(implode(' ', $cats)) . '" data-text="' . esc_attr($text) . '">';
        $out .= '<div class="ev-card-cover">' . ($cover !== '' ? $cover : '<span class="ev-card-cover-blank">' . Icons::get('calendar', 28) . '</span>');
        if (!$past && Event::isLive($c, $now)) {
            $out .= '<span class="ev-card-live">' . esc_html__('Happening now', 'sepo-events') . '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="ev-card-body">';
        if ($dated) {
            $out .= '<div class="ev-date" aria-hidden="true">'
                . '<span class="ev-date-month">' . esc_html(Event::local($c['starts_utc'], $tz, 'M')) . '</span>'
                . '<span class="ev-date-day">' . esc_html(Event::local($c['starts_utc'], $tz, 'j')) . '</span>'
                . '<span class="ev-date-week">' . esc_html(Event::local($c['starts_utc'], $tz, 'D')) . '</span>'
                . '</div>';
        } else {
            $out .= '<div class="ev-date ev-date--tba" aria-hidden="true"><span class="ev-date-day">' . esc_html__('TBA', 'sepo-events') . '</span></div>';
        }

        $out .= '<div class="ev-card-main"><div class="ev-card-title">' . esc_html($c['title']) . '</div>';
        $out .= '<div class="ev-card-when">' . Icons::get('clock', 15) . '<span>' . esc_html(self::timeLine($c)) . '</span></div>';
        if ($c['location'] !== '') {
            $out .= '<div class="ev-card-loc">' . Icons::get('pin', 15) . '<span>' . esc_html($c['location']) . '</span></div>';
        }
        if (!$past) {
            $tags = [];
            $tags[] = $c['is_paid']
                ? '<span class="ev-tag ev-tag--paid">' . esc_html(Event::money($c['price'], $c['currency'])) . '</span>'
                : '<span class="ev-tag ev-tag--free">' . esc_html__('free', 'sepo-events') . '</span>';
            if ($c['capacity'] > 0) {
                $n = Rsvps::counts($id, $c['capacity']);
                $tags[] = (int) $n['seats_left'] <= 0
                    ? '<span class="ev-tag ev-tag--full">' . esc_html__('full', 'sepo-events') . '</span>'
                    /* translators: %d: seats */
                    : '<span class="ev-tag">' . esc_html(sprintf(_n('%d seat left', '%d seats left', (int) $n['seats_left'], 'sepo-events'), (int) $n['seats_left'])) . '</span>';
            }
            if ($c['closed']) {
                $tags[] = '<span class="ev-tag">' . esc_html__('registration closed', 'sepo-events') . '</span>';
            }
            $out .= '<div class="ev-tags">' . implode('', $tags) . '</div>';
        }
        return $out . '</div></div></a>';
    }

    /** "09:00 – 13:30 · 4h 30m", "All day", "Until Mon 9 Nov" or "Time to be announced". @param array<string,mixed> $c */
    private static function timeLine(array $c): string
    {
        if ($c['starts_utc'] === null) {
            return __('Date to be announced', 'sepo-events');
        }
        $tz = (string) $c['tz'];
        $startDay = Event::local($c['starts_utc'], $tz, 'Y-m-d');
        $endDay = $c['ends_utc'] !== null ? Event::local($c['ends_utc'], $tz, 'Y-m-d') : $startDay;
        if ($c['all_day']) {
            return $endDay !== $startDay
                /* translators: %s: last day */
                ? sprintf(__('All day, until %s', 'sepo-events'), Event::local($c['ends_utc'], $tz, 'D j M'))
                : __('All day', 'sepo-events');
        }
        $line = Event::local($c['starts_utc'], $tz, 'H:i');
        if ($c['ends_utc'] !== null) {
            $line .= ' – ' . ($endDay !== $startDay ? Event::local($c['ends_utc'], $tz, 'D j M, H:i') : Event::local($c['ends_utc'], $tz, 'H:i'));
            $dur = Event::duration($c['starts_utc'], $c['ends_utc']);
            if ($dur !== '' && $endDay === $startDay) {
                $line .= ' · ' . $dur;
            }
        }
        return $line;
    }
}
