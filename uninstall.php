<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Events and RSVPs are removed only when "When the plugin is deleted, also
 * delete every event and RSVP, with their proof of payment files" is ticked under Events → Settings.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$settings = get_option('sepo_events_settings', []);
$delete = is_array($settings) && !empty($settings['delete_on_uninstall']);

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_spe\\_%' OR option_name LIKE '\\_transient\\_timeout\\_spe\\_%'"); // phpcs:ignore WordPress.DB

if (!$delete) {
    return;
}

$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'sepo_event')); // phpcs:ignore WordPress.DB
foreach ($ids as $id) {
    wp_delete_post((int) $id, true);
}
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sepo_event_rsvps"); // phpcs:ignore WordPress.DB

$uploads = wp_upload_dir(null, false);
$dir = trailingslashit($uploads['basedir']) . 'sepo-events-private';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

$page = is_array($settings) ? (int) ($settings['index_page'] ?? 0) : 0;
if ($page > 0 && str_contains((string) get_post_field('post_content', $page), '[sepo_events')) {
    wp_delete_post($page, true);
}

delete_option('sepo_events_settings');
delete_option('sepo_events_version');
