<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * Events in page builders: the "Sepo event" and "Sepo events list" blocks,
 * and matching Elementor widgets when Elementor is active.
 */
final class Embeds
{
    public static function hook(): void
    {
        add_action('init', [self::class, 'blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'editorAssets']);
        add_action('elementor/elements/categories_registered', static function ($manager): void {
            $manager->add_category('sepo-events', ['title' => __('Sepo events', 'sepo-events'), 'icon' => 'eicon-calendar']);
        });
        add_action('elementor/widgets/register', static function ($manager): void {
            $manager->register(new Elementor\EventWidget());
            $manager->register(new Elementor\EventsListWidget());
        });
        add_action('elementor/frontend/after_register_styles', [Frontend::class, 'register']);
        add_action('elementor/frontend/after_register_scripts', [Frontend::class, 'register']);
    }

    public static function blocks(): void
    {
        register_block_type('sepo/event', [
            'api_version'     => 2,
            'title'           => __('Sepo event', 'sepo-events'),
            'category'        => 'widgets',
            'attributes'      => [
                'eventId'   => ['type' => 'number', 'default' => 0],
                'showTitle' => ['type' => 'boolean', 'default' => true],
            ],
            'render_callback' => static fn(array $a): string => (int) ($a['eventId'] ?? 0) > 0
                ? Frontend::eventShortcode(['id' => (int) $a['eventId'], 'title' => !empty($a['showTitle']) ? 'yes' : 'no'])
                : '',
        ]);
        register_block_type('sepo/events-list', [
            'api_version'     => 2,
            'title'           => __('Sepo events list', 'sepo-events'),
            'category'        => 'widgets',
            'attributes'      => [
                'show'    => ['type' => 'string', 'default' => 'all'],
                'limit'   => ['type' => 'number', 'default' => 0],
                'past'    => ['type' => 'number', 'default' => 0],
                'title'   => ['type' => 'string', 'default' => ''],
                'perPage' => ['type' => 'number', 'default' => 9],
                'view'    => ['type' => 'string', 'default' => 'grid'],
                'toolbar' => ['type' => 'boolean', 'default' => true],
            ],
            'render_callback' => static fn(array $a): string => Frontend::listShortcode([
                'show'     => (string) ($a['show'] ?? 'all'),
                'limit'    => (int) ($a['limit'] ?? 0),
                'past'     => (int) ($a['past'] ?? 0),
                'title'    => (string) ($a['title'] ?? ''),
                'per_page' => (int) ($a['perPage'] ?? 9),
                'view'     => (string) ($a['view'] ?? 'grid'),
                'toolbar'  => !empty($a['toolbar']) ? 'yes' : 'no',
            ]),
        ]);
    }

    public static function editorAssets(): void
    {
        Frontend::register();
        wp_enqueue_style('sepo-events');
        wp_enqueue_script('sepo-events-blocks', SPE_URL . 'assets/js/blocks.js', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'], Plugin::assetVersion('assets/js/blocks.js'), true);
        wp_add_inline_script('sepo-events-blocks', 'window.SepoEventsBlocks=' . wp_json_encode(['events' => self::choices()]) . ';', 'before');
        wp_set_script_translations('sepo-events-blocks', 'sepo-events');
    }

    /** Events for pickers, soonest first. @return list<array{value:int,label:string}> */
    public static function choices(): array
    {
        $out = [];
        foreach (array_merge(Event::list(['when' => 'upcoming', 'include_private' => true]), Event::list(['when' => 'past', 'include_private' => true, 'limit' => 50])) as $c) {
            $out[] = ['value' => (int) $c['id'], 'label' => $c['title'] . ($c['starts_utc'] !== null ? ' — ' . Event::when($c) : '')];
        }
        return $out;
    }
}
