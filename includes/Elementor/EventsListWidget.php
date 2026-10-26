<?php

declare(strict_types=1);

namespace SepoEvents\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use SepoEvents\Frontend;

defined('ABSPATH') || exit;

/** Elementor widget: the list of events. */
final class EventsListWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'sepo-events-list';
    }

    public function get_title(): string
    {
        return __('Sepo events list', 'sepo-events');
    }

    public function get_icon(): string
    {
        return 'eicon-post-list';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return ['sepo-events'];
    }

    /** @return list<string> */
    public function get_keywords(): array
    {
        return ['events', 'calendar', 'upcoming'];
    }

    /** @return list<string> */
    public function get_style_depends(): array
    {
        return ['sepo-events'];
    }

    /** @return list<string> */
    public function get_script_depends(): array
    {
        return ['sepo-events'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('content', ['label' => __('List', 'sepo-events')]);
        $this->add_control('show', ['label' => __('Show', 'sepo-events'), 'type' => Controls_Manager::SELECT, 'default' => 'all', 'options' => [
            'all'      => __('Upcoming and past', 'sepo-events'),
            'upcoming' => __('Upcoming only', 'sepo-events'),
            'past'     => __('Past only', 'sepo-events'),
        ]]);
        $this->add_control('limit', ['label' => __('Most events to show (0 for all)', 'sepo-events'), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'max' => 100, 'default' => 0]);
        $this->add_control('per_page', ['label' => __('Events per page', 'sepo-events'), 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 60, 'default' => 9]);
        $this->add_control('view', ['label' => __('Layout', 'sepo-events'), 'type' => Controls_Manager::SELECT, 'default' => 'grid', 'options' => ['grid' => __('Grid', 'sepo-events'), 'list' => __('List', 'sepo-events')]]);
        $this->add_control('toolbar', ['label' => __('Show search and filters', 'sepo-events'), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
        $this->add_control('category', ['label' => __('Only these categories (slugs, comma-separated)', 'sepo-events'), 'type' => Controls_Manager::TEXT, 'default' => '']);
        $this->add_control('title', ['label' => __('Heading', 'sepo-events'), 'type' => Controls_Manager::TEXT, 'default' => '']);
        $this->add_control('accent', [
            'label'     => __('Accent colour', 'sepo-events'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .ev-scope' => '--ev-accent: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();
        echo Frontend::listShortcode([ // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
            'show'  => (string) ($s['show'] ?? 'all'),
            'limit' => (int) ($s['limit'] ?? 0),
            'title'    => (string) ($s['title'] ?? ''),
            'per_page' => (int) ($s['per_page'] ?? 9),
            'view'     => (string) ($s['view'] ?? 'grid'),
            'toolbar'  => ($s['toolbar'] ?? 'yes') === 'yes' ? 'yes' : 'no',
            'category' => (string) ($s['category'] ?? ''),
        ]);
    }
}
