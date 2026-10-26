<?php

declare(strict_types=1);

namespace SepoEvents\Admin;

use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\PostType;
use SepoEvents\Rsvps;
use SepoEvents\Settings;

defined('ABSPATH') || exit;

/**
 * The Event details box on the event edit screen: schedule, place,
 * registration, payment and host, plus a side box with the RSVP count and
 * shortcuts. Works in the block editor and the classic editor.
 */
final class MetaBox
{
    public static function hook(): void
    {
        add_action('add_meta_boxes_' . PostType::TYPE, [self::class, 'add']);
        add_action('save_post_' . PostType::TYPE, [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== PostType::TYPE) {
                return;
            }
            wp_enqueue_style('sepo-events-admin', SPE_URL . 'assets/css/admin.css', [], \SepoEvents\Plugin::assetVersion('assets/css/admin.css'));
        });
    }

    public static function add(): void
    {
        add_meta_box('spe-event', __('Event details', 'sepo-events'), [self::class, 'render'], PostType::TYPE, 'normal', 'high');
        add_meta_box('spe-event-rsvps', __('RSVPs', 'sepo-events'), [self::class, 'side'], PostType::TYPE, 'side', 'high');
    }

    public static function render(\WP_Post $post): void
    {
        $s = Event::stored((int) $post->ID);
        $tz = $s['timezone'] !== '' ? $s['timezone'] : wp_timezone_string();
        $isNew = $post->post_status === 'auto-draft';
        $fieldOn = static fn(string $f): bool => in_array($f, (array) $s['fields'], true);
        wp_nonce_field('spe_event_save', 'spe_event_nonce');
        $details = implode("\n", array_map(static fn($r) => trim($r['label'] . ($r['note'] !== '' ? ' | ' . $r['note'] : '')), (array) $s['details']));
        ?>
        <div class="spe-box">
          <section class="spe-sec">
            <h3><?php esc_html_e('When', 'sepo-events'); ?></h3>
            <div class="spe-grid3">
              <label><span><?php esc_html_e('Starts', 'sepo-events'); ?></span>
                <input type="<?php echo $s['all_day'] ? 'date' : 'datetime-local'; ?>" name="spe[starts]" value="<?php echo esc_attr($s['all_day'] ? substr((string) $s['starts'], 0, 10) : (string) $s['starts']); ?>" data-spe-dt></label>
              <label><span><?php esc_html_e('Ends', 'sepo-events'); ?></span>
                <input type="<?php echo $s['all_day'] ? 'date' : 'datetime-local'; ?>" name="spe[ends]" value="<?php echo esc_attr($s['all_day'] ? substr((string) $s['ends'], 0, 10) : (string) $s['ends']); ?>" data-spe-dt></label>
              <label><span><?php esc_html_e('Time zone', 'sepo-events'); ?></span>
                <select name="spe[timezone]"><?php echo wp_timezone_choice($tz, get_user_locale()); // phpcs:ignore WordPress.Security.EscapeOutput -- core markup ?></select></label>
            </div>
            <label class="spe-check"><input type="checkbox" name="spe[all_day]" value="1" <?php checked($s['all_day']); ?> data-spe-allday> <?php esc_html_e('All-day event', 'sepo-events'); ?></label>
            <p class="description"><?php esc_html_e('Leave the end empty for a one-hour event. Leave both empty while the date is to be announced.', 'sepo-events'); ?></p>
          </section>

          <section class="spe-sec">
            <h3><?php esc_html_e('Where and how many', 'sepo-events'); ?></h3>
            <div class="spe-grid2">
              <label><span><?php esc_html_e('Location', 'sepo-events'); ?></span>
                <input type="text" name="spe[location]" value="<?php echo esc_attr((string) $s['location']); ?>" placeholder="<?php esc_attr_e('e.g. Main hall, Cairo Road, Lusaka — or Online', 'sepo-events'); ?>"></label>
              <label><span><?php esc_html_e('Capacity (seats)', 'sepo-events'); ?></span>
                <input type="number" min="0" step="1" name="spe[capacity]" value="<?php echo esc_attr((string) $s['capacity']); ?>">
                <small><?php esc_html_e('0 means no limit. When seats run out, new RSVPs join a waitlist that moves up as people cancel.', 'sepo-events'); ?></small></label>
            </div>
          </section>

          <section class="spe-sec">
            <h3><?php esc_html_e('Registration', 'sepo-events'); ?></h3>
            <div class="spe-row">
              <span class="spe-label"><?php esc_html_e('Ask for', 'sepo-events'); ?></span>
              <label class="spe-check"><input type="checkbox" checked disabled> <?php esc_html_e('Email (always)', 'sepo-events'); ?></label>
              <label class="spe-check"><input type="checkbox" name="spe[fields][]" value="name" <?php checked($isNew || $fieldOn('name')); ?>> <?php esc_html_e('Full name', 'sepo-events'); ?></label>
              <label class="spe-check"><input type="checkbox" name="spe[fields][]" value="phone" <?php checked($fieldOn('phone')); ?>> <?php esc_html_e('Phone', 'sepo-events'); ?></label>
            </div>
            <div class="spe-row">
              <label class="spe-check"><input type="checkbox" name="spe[allow_guests]" value="1" <?php checked($s['allow_guests']); ?>> <?php esc_html_e('Let people book more than one seat', 'sepo-events'); ?></label>
              <label class="spe-inline"><?php esc_html_e('Up to', 'sepo-events'); ?> <input type="number" min="1" max="50" name="spe[max_seats]" value="<?php echo esc_attr((string) $s['max_seats']); ?>" class="small-text"> <?php esc_html_e('seats each', 'sepo-events'); ?></label>
            </div>
            <p class="description"><?php esc_html_e('With extra seats on, the form asks for each extra person\'s name, email and phone (as chosen above), and each named person gets their own attendee card and check-in code.', 'sepo-events'); ?></p>
            <div class="spe-row">
              <label class="spe-check"><input type="checkbox" name="spe[require_login]" value="1" <?php checked($s['require_login']); ?>> <?php esc_html_e('Only signed-in visitors can see and RSVP', 'sepo-events'); ?></label>
              <label class="spe-check"><input type="checkbox" name="spe[closed]" value="1" <?php checked($s['closed']); ?>> <?php esc_html_e('Close registration', 'sepo-events'); ?></label>
            </div>
            <p class="description"><?php esc_html_e('To hide an event from everyone except editors, set its Visibility to Private in the publish panel.', 'sepo-events'); ?></p>
          </section>

          <section class="spe-sec">
            <h3><?php esc_html_e('Payment', 'sepo-events'); ?></h3>
            <div class="spe-row">
              <label class="spe-check"><input type="checkbox" name="spe[paid]" value="1" <?php checked($s['paid']); ?>> <?php esc_html_e('Paid event', 'sepo-events'); ?></label>
              <label class="spe-inline"><?php esc_html_e('Price per seat', 'sepo-events'); ?> <input type="number" min="0" step="0.01" name="spe[price]" value="<?php echo esc_attr(number_format((float) $s['price'], 2, '.', '')); ?>" class="small-text spe-w-num"></label>
              <label class="spe-inline"><?php esc_html_e('Currency', 'sepo-events'); ?> <input type="text" name="spe[currency]" value="<?php echo esc_attr((string) $s['currency']); ?>" placeholder="<?php echo esc_attr(Settings::currency()); ?>" maxlength="6" class="small-text spe-w-cur"></label>
            </div>
            <div class="spe-row">
              <label class="spe-inline"><?php esc_html_e('Proof of payment', 'sepo-events'); ?>
                <select name="spe[proof]">
                  <option value="off" <?php selected($s['proof'], 'off'); ?>><?php esc_html_e('Don\'t ask', 'sepo-events'); ?></option>
                  <option value="optional" <?php selected($s['proof'], 'optional'); ?>><?php esc_html_e('Optional', 'sepo-events'); ?></option>
                  <option value="required" <?php selected($s['proof'], 'required'); ?>><?php esc_html_e('Required', 'sepo-events'); ?></option>
                </select></label>
            </div>
            <p class="description"><?php esc_html_e('Nothing is charged here. The RSVP form asks what each person paid and the dashboard adds it up. With proof of payment on, people attach a receipt, a screenshot of the mobile-money message or a bank slip (image, PDF or Word document) when they RSVP or later from their RSVP, and you mark each payment as checked on the RSVPs screen.', 'sepo-events'); ?></p>
          </section>

          <section class="spe-sec">
            <h3><?php esc_html_e('Host and details', 'sepo-events'); ?></h3>
            <div class="spe-grid2">
              <label><span><?php esc_html_e('Host', 'sepo-events'); ?></span>
                <input type="text" name="spe[host]" value="<?php echo esc_attr((string) $s['host']); ?>"></label>
              <label><span><?php esc_html_e('About the host', 'sepo-events'); ?></span>
                <textarea name="spe[host_bio]" rows="3"><?php echo esc_textarea((string) $s['host_bio']); ?></textarea></label>
            </div>
            <label class="spe-block"><span><?php esc_html_e('Details table', 'sepo-events'); ?></span>
              <textarea name="spe[details]" rows="4" placeholder="<?php esc_attr_e("Dress code | Smart casual\nParking | Free behind the hall", 'sepo-events'); ?>"><?php echo esc_textarea($details); ?></textarea>
              <small><?php esc_html_e('One row per line: label | note. Leave empty to show when, where, capacity, price and host.', 'sepo-events'); ?></small></label>
          </section>
          <p class="description spe-foot"><?php esc_html_e('The short description goes in the Excerpt box, the banner is the featured image, and the main text above is shown under Details.', 'sepo-events'); ?></p>
        </div>
        <script>
        (function () {
          var box = document.getElementById('spe-event');
          if (!box) { return; }
          var allDay = box.querySelector('[data-spe-allday]');
          allDay && allDay.addEventListener('change', function () {
            box.querySelectorAll('[data-spe-dt]').forEach(function (inp) {
              var v = inp.value;
              inp.type = allDay.checked ? 'date' : 'datetime-local';
              inp.value = allDay.checked ? v.slice(0, 10) : (v && v.length === 10 ? v + 'T09:00' : v);
            });
          });
        })();
        </script>
        <?php
    }

    public static function side(\WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('RSVPs appear here once the event is saved.', 'sepo-events') . '</p>';
            return;
        }
        $c = Event::config($post);
        $n = Rsvps::counts((int) $post->ID, $c['capacity']);
        echo '<ul class="spe-side-stats">';
        echo '<li><b>' . (int) $n['seats_taken'] . '</b><span>' . esc_html($c['capacity'] > 0 ? sprintf(/* translators: %d: capacity */ __('of %d seats', 'sepo-events'), $c['capacity']) : __('seats taken', 'sepo-events')) . '</span></li>';
        echo '<li><b>' . (int) $n['waitlist'] . '</b><span>' . esc_html__('waiting', 'sepo-events') . '</span></li>';
        echo '<li><b>' . (int) $n['checked_in'] . '</b><span>' . esc_html__('checked in', 'sepo-events') . '</span></li>';
        echo '</ul>';
        if (Plugin::canManage()) {
            echo '<p class="spe-side-links">'
                . '<a class="button" href="' . esc_url(Screens::dashboardUrl(['event' => $post->ID])) . '">' . esc_html__('See RSVPs', 'sepo-events') . '</a> '
                . '<a class="button" target="_blank" rel="noopener" href="' . esc_url(Tools::url('cards', ['event' => $post->ID])) . '">' . esc_html__('Cards', 'sepo-events') . '</a> '
                . '<a class="button" target="_blank" rel="noopener" href="' . esc_url(Tools::url('checkin', ['event' => $post->ID])) . '">' . esc_html__('Check-in', 'sepo-events') . '</a></p>';
        }
        echo '<p class="description">' . esc_html__('Shortcode for any page:', 'sepo-events') . ' <code>[sepo_event id="' . (int) $post->ID . '"]</code></p>';
    }

    public static function save(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['spe_event_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['spe_event_nonce'])), 'spe_event_save')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $in = isset($_POST['spe']) && is_array($_POST['spe']) ? wp_unslash($_POST['spe']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by Event::sanitize()
        Event::save($postId, (array) $in);
    }
}
