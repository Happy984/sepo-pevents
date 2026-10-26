<?php

declare(strict_types=1);

namespace SepoEvents\Admin;

use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\PostType;
use SepoEvents\Render\Dashboard;
use SepoEvents\Settings;

defined('ABSPATH') || exit;

/** Events → RSVPs (the dashboard) and Events → Settings. */
final class Screens
{
    public const DASHBOARD = 'sepo-events-rsvps';
    public const SETTINGS = 'sepo-events-settings';

    public static function hook(): void
    {
        add_action('admin_menu', static function (): void {
            $parent = 'edit.php?post_type=' . PostType::TYPE;
            $cap = (string) apply_filters('sepo_events_manage_capability', 'edit_others_posts');
            add_submenu_page($parent, __('Event RSVPs', 'sepo-events'), __('RSVPs', 'sepo-events'), $cap, self::DASHBOARD, [self::class, 'dashboard']);
            add_submenu_page($parent, __('Events settings', 'sepo-events'), __('Settings', 'sepo-events'), 'manage_options', self::SETTINGS, [self::class, 'settings']);
        });
        add_action('admin_post_sepo_events_settings', [self::class, 'save']);
        add_filter('admin_body_class', static function (string $c): string {
            $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            return $page === self::DASHBOARD ? $c . ' spe-dashboard-page' : $c;
        });
        add_filter('plugin_action_links_' . plugin_basename(SPE_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('edit.php?post_type=' . PostType::TYPE . '&page=' . self::SETTINGS)) . '">' . esc_html__('Settings', 'sepo-events') . '</a>');
            return $links;
        });
    }

    /** @param array<string,int|string> $args */
    public static function dashboardUrl(array $args = []): string
    {
        return add_query_arg(array_merge(['post_type' => PostType::TYPE, 'page' => self::DASHBOARD], $args), admin_url('edit.php'));
    }

    public static function dashboard(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        $event = isset($_GET['event']) ? absint($_GET['event']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__('Event RSVPs', 'sepo-events') . '</h1>';
        echo Dashboard::render($event); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
        echo '</div>';
    }

    public static function settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::all();
        ?>
        <div class="wrap spe-settings">
          <h1><?php esc_html_e('Events settings', 'sepo-events'); ?></h1>
          <?php if (isset($_GET['updated'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sepo-events'); ?></p></div>
          <?php endif; ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sepo_events_settings">
            <?php wp_nonce_field('sepo_events_settings'); ?>

            <h2><?php esc_html_e('Look and pages', 'sepo-events'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><label for="spe-accent"><?php esc_html_e('Accent colour', 'sepo-events'); ?></label></th>
                <td><input type="text" id="spe-accent" name="accent" value="<?php echo esc_attr((string) $s['accent']); ?>" placeholder="#0b3d33" class="regular-text" pattern="#?[0-9a-fA-F]{3,6}">
                  <p class="description"><?php esc_html_e('Buttons, the selected tab and highlights. Leave empty for the default deep green.', 'sepo-events'); ?></p></td></tr>
              <tr><th scope="row"><label for="spe-index"><?php esc_html_e('Events list page', 'sepo-events'); ?></label></th>
                <td><?php wp_dropdown_pages(['name' => 'index_page', 'id' => 'spe-index', 'selected' => (int) $s['index_page'], 'show_option_none' => esc_html__('— None —', 'sepo-events'), 'option_none_value' => '0']); ?>
                  <p class="description"><?php esc_html_e('The page that lists your events. Any page with the [sepo_events] shortcode or the "Sepo events list" block works.', 'sepo-events'); ?></p></td></tr>
              <tr><th scope="row"><label for="spe-currency"><?php esc_html_e('Default currency', 'sepo-events'); ?></label></th>
                <td><input type="text" id="spe-currency" name="currency" value="<?php echo esc_attr((string) $s['currency']); ?>" class="small-text" maxlength="6" style="text-transform:uppercase">
                  <p class="description"><?php esc_html_e('A code such as ZMW, USD or EUR, used by paid events that name none of their own.', 'sepo-events'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Proof of payment', 'sepo-events'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><label for="spe-proof-mb"><?php esc_html_e('Largest file', 'sepo-events'); ?></label></th>
                <td><input type="number" id="spe-proof-mb" name="proof_max_mb" min="1" max="<?php echo (int) max(1, floor(wp_max_upload_size() / 1048576)); ?>" value="<?php echo esc_attr((string) $s['proof_max_mb']); ?>" class="small-text"> MB
                  <p class="description"><?php
                  /* translators: 1: list of file types, 2: size in MB */
                  echo esc_html(sprintf(__('Accepted: %1$s. This server accepts uploads up to %2$d MB. Files are kept in a private folder and only event managers can open them.', 'sepo-events'), \SepoEvents\Proofs::label(), (int) floor(wp_max_upload_size() / 1048576)));
                  ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Email', 'sepo-events'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('Confirmations', 'sepo-events'); ?></th>
                <td><label><input type="checkbox" name="confirm_emails" value="1" <?php checked(!empty($s['confirm_emails'])); ?>> <?php esc_html_e('Email people when they RSVP, join the waitlist, move up from it, or cancel', 'sepo-events'); ?></label>
                  <p class="description"><?php esc_html_e('The email carries their check-in code and a link that opens their RSVP on any device.', 'sepo-events'); ?></p></td></tr>
              <tr><th scope="row"><label for="spe-notify"><?php esc_html_e('New RSVP alerts', 'sepo-events'); ?></label></th>
                <td><input type="email" id="spe-notify" name="notify_email" value="<?php echo esc_attr((string) $s['notify_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                  <p class="description"><?php esc_html_e('Send a copy of every new RSVP to this address. Leave empty for none.', 'sepo-events'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Uninstall', 'sepo-events'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('Delete data', 'sepo-events'); ?></th>
                <td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked(!empty($s['delete_on_uninstall'])); ?>> <?php esc_html_e('When the plugin is deleted, also delete every event and RSVP', 'sepo-events'); ?></label></td></tr>
            </table>
            <?php submit_button(); ?>
          </form>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'sepo-events'), '', ['response' => 403]);
        }
        check_admin_referer('sepo_events_settings');
        $accent = sanitize_text_field(wp_unslash((string) ($_POST['accent'] ?? '')));
        if ($accent !== '') {
            $accent = '#' . ltrim($accent, '#');
            $accent = sanitize_hex_color($accent) ?: '';
        }
        $notify = sanitize_email(wp_unslash((string) ($_POST['notify_email'] ?? '')));
        Settings::update([
            'accent'              => $accent,
            'index_page'          => absint($_POST['index_page'] ?? 0),
            'currency'            => Event::currency(sanitize_text_field(wp_unslash((string) ($_POST['currency'] ?? ''))), 'ZMW'),
            'confirm_emails'      => empty($_POST['confirm_emails']) ? 0 : 1,
            'notify_email'        => is_email($notify) ? $notify : '',
            'proof_max_mb'        => max(1, min(100, absint($_POST['proof_max_mb'] ?? 5))),
            'delete_on_uninstall' => empty($_POST['delete_on_uninstall']) ? 0 : 1,
        ]);
        wp_safe_redirect(add_query_arg(['post_type' => PostType::TYPE, 'page' => self::SETTINGS, 'updated' => 1], admin_url('edit.php')));
        exit;
    }
}
