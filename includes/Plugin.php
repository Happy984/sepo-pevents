<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * Wires the plugin together and keeps the database in step with the code.
 */
final class Plugin
{
    public const VERSION_OPTION = 'sepo_events_version';

    public static function activate(): void
    {
        Rsvps::install();
        Proofs::protectDir();
        PostType::register();
        Frontend::rewrites();
        Settings::ensureIndexPage();
        flush_rewrite_rules();
        update_option(self::VERSION_OPTION, SPE_VERSION);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('sepo-events', false, dirname(plugin_basename(SPE_FILE)) . '/languages');
        }, 1);

        PostType::hook();
        Frontend::hook();
        Rest::hook();
        Embeds::hook();
        Privacy::hook();
        Proofs::hook();
        Admin\Tools::hook();

        if (is_admin()) {
            Admin\MetaBox::hook();
            Admin\Screens::hook();
        }

        // A new version may add columns or rewrite rules: bring both up to date once.
        add_action('init', static function (): void {
            if (get_option(self::VERSION_OPTION) !== SPE_VERSION) {
                Rsvps::install();
                Proofs::protectDir();
                flush_rewrite_rules(false);
                update_option(self::VERSION_OPTION, SPE_VERSION);
            }
        }, 99);
    }

    /** The plugin version plus the file's change time, so browsers fetch an edited file at once. */
    public static function assetVersion(string $rel): string
    {
        $file = SPE_DIR . $rel;
        return SPE_VERSION . (is_file($file) ? '.' . filemtime($file) : '');
    }

    /** Who runs events: sees every RSVP, exports, prints cards and checks people in. */
    public static function canManage(): bool
    {
        return current_user_can((string) apply_filters('sepo_events_manage_capability', 'edit_others_posts'));
    }
}
