<?php

/**
 * Plugin Name:       Sepo Events
 * Description:       Events with RSVPs, capacity and a waitlist, guest or signed-in registration, paid-event records, an RSVP dashboard with CSV and print exports, printable attendee cards with QR codes, and a door check-in scanner. Runs on WordPress alone.
 * Version:           1.3.2
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * Plugin URI:        https://edu.sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-events
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo Events needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-events')
            . '</p></div>';
    });
    return;
}

define('SPE_VERSION', '1.3.2');
define('SPE_FILE', __FILE__);
define('SPE_DIR', plugin_dir_path(__FILE__));
define('SPE_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoEvents\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = SPE_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['SepoEvents\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SepoEvents\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['SepoEvents\\Plugin', 'boot']);
