<?php

declare(strict_types=1);

namespace SepoEvents\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use SepoEvents\Embeds;
use SepoEvents\Frontend;

defined('ABSPATH') || exit;

/** Elementor widget: one event with its RSVP form. */
final class EventWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'sepo-event';
    }

    public function get_title(): string
    {
        return __('Sepo event', 'sepo-events');
    }

    public function get_icon(): string
    {
        return 'eicon-calendar';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return ['sepo-events'];
    }

    /** @return list<string> */
    public function get_keywords(): array
    {
        return ['event', 'rsvp', 'registration', 'ticket'];
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
        $options = [0 => __('Choose an event…', 'sepo-events')];
        foreach (Embeds::choices() as $c) {
            $options[$c['value']] = $c['label'];
        }
        $this->start_controls_section('content', ['label' => __('Event', 'sepo-events')]);
        $this->add_control('event_id', ['label' => __('Event', 'sepo-events'), 'type' => Controls_Manager::SELECT, 'options' => $options, 'default' => 0]);
        $this->add_control('show_title', ['label' => __('Show the event title', 'sepo-events'), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
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
        $id = (int) ($s['event_id'] ?? 0);
        if ($id <= 0) {
            if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                echo '<p>' . esc_html__('Choose an event in the widget settings.', 'sepo-events') . '</p>';
            }
            return;
        }
        echo Frontend::eventShortcode(['id' => $id, 'title' => ($s['show_title'] ?? '') === 'yes' ? 'yes' : 'no']); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
