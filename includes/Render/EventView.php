<?php

declare(strict_types=1);

namespace SepoEvents\Render;

use SepoEvents\Admin\Tools;
use SepoEvents\Event;
use SepoEvents\Plugin;
use SepoEvents\Proofs;
use SepoEvents\Rsvps;
use SepoEvents\Tools\Qr;

defined('ABSPATH') || exit;

/**
 * One event: tabs for Details, Host and RSVPs beside a "When and where" box.
 *
 * RECORDS AND SEATS
 *   Where the page answers "how many are coming" it shows seats (the sum of
 *   each registration's seats). The waitlist is a count of registrations,
 *   since it is a queue of people.
 *
 * PAYMENT IS RECORDED, NOT TAKEN
 *   A paid event asks how much each person paid and stores it with the
 *   RSVP. Nothing is charged here.
 */
final class EventView
{
    /** @param array{showTitle?:bool,body?:string} $o */
    public static function render(int $id, array $o = []): string
    {
        $c = Event::config($id);
        $canManage = Plugin::canManage();

        if ($c['private'] && !$canManage) {
            return self::notice('lock', __('This event is private.', 'sepo-events'));
        }
        if ($c['require_login'] && !is_user_logged_in()) {
            $login = wp_login_url((string) ($c['url'] ?: home_url(add_query_arg([]))));
            return self::notice('lock', __('Please sign in to see this event.', 'sepo-events'), '<a class="ev-btn ev-btn--primary" href="' . esc_url($login) . '">' . esc_html__('Sign in', 'sepo-events') . '</a>');
        }

        $n = Rsvps::counts($id, $c['capacity']);
        $userId = get_current_user_id();
        $mine = $userId > 0 ? Rsvps::findFor($id, $userId) : Rsvps::fromCookie($id);
        if ($mine && $mine['status'] === 'cancelled') {
            $mine = null;
        }

        $seats = (int) $n['seats_taken'];
        $records = (int) $n['going'];
        $isFull = $c['capacity'] > 0 && (int) $n['seats_left'] <= 0;
        $isOver = Event::isOver($c);
        $isLive = Event::isLive($c);
        $tz = $c['tz'];
        $day = Event::local($c['starts_utc'], $tz, 'l, j F Y');
        $startAt = $c['all_day'] ? '' : Event::local($c['starts_utc'], $tz, 'H:i');
        $endAt = $c['all_day'] ? '' : Event::local($c['ends_utc'], $tz, 'H:i');
        $endDay = $c['ends_utc'] !== null ? Event::local($c['ends_utc'], $tz, 'l, j F Y') : '';
        $duration = Event::duration($c['starts_utc'], $c['ends_utc']);

        $details = $c['details'];
        if ($details === []) {
            if ($c['starts_utc'] !== null) {
                $when = $day . ($startAt !== '' ? ' · ' . $startAt . ($endAt !== '' ? '–' . $endAt : '') : '');
                if ($endDay !== '' && $endDay !== $day) {
                    $when = $day . ($startAt !== '' ? ', ' . $startAt : '') . ' – ' . $endDay . ($endAt !== '' ? ', ' . $endAt : '');
                }
                $details[] = ['label' => __('When', 'sepo-events'), 'note' => $when . ' (' . $tz . ')'];
            }
            if ($c['location'] !== '') {
                $details[] = ['label' => __('Where', 'sepo-events'), 'note' => $c['location']];
            }
            if ($c['capacity'] > 0) {
                /* translators: %d: seats */
                $details[] = ['label' => __('Capacity', 'sepo-events'), 'note' => sprintf(_n('%d seat', '%d seats', $c['capacity'], 'sepo-events'), $c['capacity'])];
            }
            $details[] = ['label' => __('Price', 'sepo-events'), 'note' => $c['is_paid']
                /* translators: %s: price */
                ? sprintf(__('%s per seat', 'sepo-events'), Event::money($c['price'], $c['currency']))
                : __('Free', 'sepo-events')];
            if ($c['host'] !== '') {
                $details[] = ['label' => __('Host', 'sepo-events'), 'note' => $c['host']];
            }
        }

        $hasHost = $c['host'] !== '' || $c['host_bio'] !== '';
        $initials = '';
        if ($c['host'] !== '') {
            $parts = preg_split('/\s+/u', trim($c['host'])) ?: [];
            $initials = mb_strtoupper(mb_substr((string) ($parts[0] ?? ''), 0, 1) . mb_substr((string) ($parts[1] ?? ''), 0, 1));
        }

        $wantsName = in_array('name', $c['fields'], true);
        $wantsPhone = in_array('phone', $c['fields'], true);
        $collectGuests = $c['allow_guests'] && ($wantsName || $wantsPhone);
        $user = $userId > 0 ? wp_get_current_user() : null;
        $body = (string) ($o['body'] ?? '');

        ob_start();
        ?>
<div class="ev-scope<?php echo esc_attr(\SepoEvents\Frontend::wideClass()); ?>">
<div class="ev-wrap" data-ev-root
     data-ev-id="<?php echo (int) $id; ?>"
     data-ev-paid="<?php echo $c['is_paid'] ? '1' : '0'; ?>"
     data-ev-price="<?php echo esc_attr((string) $c['price']); ?>"
     data-ev-currency="<?php echo esc_attr($c['currency']); ?>"
     data-ev-allow-guests="<?php echo $collectGuests ? '1' : '0'; ?>"
     data-ev-wants-name="<?php echo $wantsName ? '1' : '0'; ?>"
     data-ev-wants-phone="<?php echo $wantsPhone ? '1' : '0'; ?>"
     data-ev-full="<?php echo $isFull ? '1' : '0'; ?>">

    <?php if (!empty($o['showTitle']) || $c['description'] !== '') : ?>
    <div class="ev-head">
        <?php if (!empty($o['showTitle'])) : ?><h2><?php echo esc_html($c['title']); ?></h2><?php endif; ?>
        <?php if ($c['description'] !== '') : ?><p class="ev-muted ev-lede"><?php echo esc_html($c['description']); ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="ev-layout">
        <div class="ev-main">
            <div class="ev-tabs" role="tablist">
                <button type="button" class="ev-tab active" data-ev-tab="details" role="tab" aria-selected="true"><?php esc_html_e('Details', 'sepo-events'); ?></button>
                <?php if ($hasHost) : ?>
                    <button type="button" class="ev-tab" data-ev-tab="host" role="tab" aria-selected="false"><?php echo esc_html($c['host'] !== '' ? __('Host', 'sepo-events') : __('About', 'sepo-events')); ?></button>
                <?php endif; ?>
                <button type="button" class="ev-tab" data-ev-tab="rsvps" role="tab" aria-selected="false"><?php esc_html_e('RSVPs', 'sepo-events'); ?><?php echo $seats > 0 ? ' (' . (int) $seats . ')' : ''; ?></button>
            </div>

            <div class="ev-panel active" data-ev-panel="details" role="tabpanel">
                <?php if (trim(wp_strip_all_tags($body)) !== '' || str_contains($body, '<img')) : ?>
                    <div class="ev-body"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the post content, already filtered by the_content ?></div>
                <?php elseif ($c['description'] === '') : ?>
                    <p class="ev-muted"><?php esc_html_e('No further details yet.', 'sepo-events'); ?></p>
                <?php endif; ?>
                <?php if ($details !== []) : ?>
                    <table class="ev-spec"><tbody>
                        <?php foreach ($details as $row) : ?>
                            <tr><th scope="row"><?php echo esc_html((string) $row['label']); ?></th><td><?php echo esc_html((string) $row['note']); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>

            <?php if ($hasHost) : ?>
            <div class="ev-panel" data-ev-panel="host" role="tabpanel">
                <div class="ev-host">
                    <?php if ($initials !== '') : ?><div class="ev-host-avatar" aria-hidden="true"><?php echo esc_html($initials); ?></div><?php endif; ?>
                    <div>
                        <p class="ev-host-name"><?php echo esc_html($c['host'] !== '' ? $c['host'] : __('About this event', 'sepo-events')); ?></p>
                        <?php if ($c['host_bio'] !== '') : ?><p class="ev-host-bio"><?php echo nl2br(esc_html($c['host_bio'])); ?></p><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="ev-panel" data-ev-panel="rsvps" role="tabpanel" id="rsvp">
                <div class="ev-rsvp-summary">
                    <?php if ($c['capacity'] > 0) : ?>
                        <div class="ev-rsvp-stat"><b><?php echo (int) $n['seats_left']; ?></b><span><?php echo esc_html(_n('seat left', 'seats left', (int) $n['seats_left'], 'sepo-events')); ?></span></div>
                    <?php endif; ?>
                    <div class="ev-rsvp-stat"><b><?php echo (int) $seats; ?></b><span><?php echo esc_html(_n('seat taken', 'seats taken', $seats, 'sepo-events')); ?></span></div>
                    <?php if ($n['waitlist'] > 0) : ?>
                        <div class="ev-rsvp-stat"><b><?php echo (int) $n['waitlist']; ?></b><span><?php esc_html_e('on the waitlist', 'sepo-events'); ?></span></div>
                    <?php endif; ?>
                    <div class="ev-rsvp-stat"><b><?php echo $c['capacity'] > 0 ? (int) $c['capacity'] : '∞'; ?></b><span><?php esc_html_e('capacity', 'sepo-events'); ?></span></div>
                    <?php if ($records > 0 && $records !== $seats) : ?>
                        <div class="ev-rsvp-stat"><b><?php echo (int) $records; ?></b><span><?php echo esc_html(_n('registration', 'registrations', $records, 'sepo-events')); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="ev-msg" data-ev-msg role="status" hidden></div>

                <?php if ($mine) : ?>
                    <?php echo self::mine($mine, $c, $isOver); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>
                <?php elseif ($isOver) : ?>
                    <p class="ev-muted"><?php esc_html_e('This event has finished. RSVPs are closed.', 'sepo-events'); ?></p>
                <?php elseif ($c['closed']) : ?>
                    <p class="ev-muted"><?php esc_html_e('Registration for this event is closed.', 'sepo-events'); ?></p>
                <?php elseif ($c['private']) : ?>
                    <p class="ev-muted"><?php esc_html_e('This event is private. Publish it to open RSVPs.', 'sepo-events'); ?></p>
                <?php else : ?>
                    <?php if ($c['is_paid']) : ?>
                        <p class="ev-form-note">
                            <strong><?php esc_html_e('Paid event.', 'sepo-events'); ?></strong>
                            <?php esc_html_e('Record the amount you have paid for each attendee. Payment is handled outside this page; the form is a record of it.', 'sepo-events'); ?>
                            <?php
                            /* translators: %s: price */
                            echo esc_html(sprintf(__('Unit price: %s.', 'sepo-events'), Event::money($c['price'], $c['currency'])));
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($isFull) : ?>
                        <p class="ev-form-note"><strong><?php esc_html_e('The event is full.', 'sepo-events'); ?></strong> <?php esc_html_e('Join the waitlist and you will be emailed if a seat opens up.', 'sepo-events'); ?></p>
                    <?php endif; ?>

                    <form class="ev-rsvp-form" data-ev-form novalidate>
                        <fieldset class="ev-fieldset">
                            <legend><?php esc_html_e('Your details', 'sepo-events'); ?></legend>
                            <?php if ($wantsName) : ?>
                                <label class="ev-field"><span><?php esc_html_e('Full name', 'sepo-events'); ?></span>
                                    <input type="text" name="name" required autocomplete="name" value="<?php echo esc_attr($user ? (string) $user->display_name : ''); ?>" placeholder="<?php esc_attr_e('e.g. Chanda Mwale', 'sepo-events'); ?>"></label>
                            <?php endif; ?>
                            <label class="ev-field"><span><?php esc_html_e('Email', 'sepo-events'); ?></span>
                                <input type="email" name="email" required autocomplete="email" value="<?php echo esc_attr($user ? (string) $user->user_email : ''); ?>" placeholder="<?php esc_attr_e('you@example.com', 'sepo-events'); ?>"></label>
                            <?php if ($wantsPhone) : ?>
                                <label class="ev-field"><span><?php esc_html_e('Phone', 'sepo-events'); ?></span>
                                    <input type="tel" name="phone" required autocomplete="tel" placeholder="<?php esc_attr_e('e.g. 097 123 4567', 'sepo-events'); ?>"></label>
                            <?php endif; ?>
                            <input type="text" name="sf_website" class="ev-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
                        </fieldset>

                        <?php if ($c['allow_guests']) : ?>
                            <fieldset class="ev-fieldset">
                                <legend><?php esc_html_e('Seats', 'sepo-events'); ?></legend>
                                <label class="ev-field"><span><?php esc_html_e('How many seats', 'sepo-events'); ?></span>
                                    <select name="guests">
                                        <?php for ($i = 1; $i <= $c['max_seats']; $i++) : ?><option value="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></option><?php endfor; ?>
                                    </select>
                                    <small><?php echo esc_html($collectGuests ? __('Includes yourself.', 'sepo-events') : __('Includes yourself. Only your own details are recorded.', 'sepo-events')); ?></small>
                                </label>
                                <div data-ev-attendees></div>
                            </fieldset>
                        <?php endif; ?>

                        <?php if ($c['is_paid']) : ?>
                            <fieldset class="ev-fieldset">
                                <legend><?php esc_html_e('Payment', 'sepo-events'); ?></legend>
                                <label class="ev-field"><span><?php
                                    /* translators: %s: currency code */
                                    echo esc_html(sprintf(__('Amount paid for yourself (%s)', 'sepo-events'), $c['currency']));
                                ?></span>
                                    <input type="number" name="amount" min="0" step="0.01" inputmode="decimal" value="<?php echo esc_attr(number_format($c['price'], 2, '.', '')); ?>">
                                    <small><?php
                                    /* translators: %s: currency code */
                                    echo esc_html(sprintf(__('Enter what you actually paid, in %s.', 'sepo-events'), $c['currency']));
                                    ?></small>
                                </label>
                                <div class="ev-total-row">
                                    <span data-ev-total-label><?php
                                    /* translators: %d: number of seats */
                                    echo esc_html(sprintf(_n('Expected total for %d seat', 'Expected total for %d seats', 1, 'sepo-events'), 1));
                                    ?></span>
                                    <b data-ev-total-expected><?php echo esc_html(Event::money($c['price'], $c['currency'])); ?></b>
                                </div>
                                <?php if ($c['proof'] !== 'off') : ?>
                                    <?php echo self::proofField($c['proof'] === 'required'); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>
                                <?php endif; ?>
                            </fieldset>
                        <?php endif; ?>

                        <div class="ev-actions ev-actions--form">
                            <button type="submit" class="ev-btn ev-btn--primary" data-ev-submit><?php echo esc_html($isFull ? __('Join the waitlist', 'sepo-events') : __('RSVP', 'sepo-events')); ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <aside class="ev-aside">
            <div class="ev-panel-box">
                <h3><?php echo esc_html($isLive ? __('Happening now', 'sepo-events') : __('When and where', 'sepo-events')); ?></h3>
                <?php if ($c['starts_utc'] !== null) : ?>
                    <p class="ev-aside-when"><?php echo esc_html($day); ?></p>
                    <p class="ev-aside-sub">
                        <?php
                        $sub = [];
                        if ($c['all_day']) {
                            $sub[] = $endDay !== '' && $endDay !== $day ? __('Until', 'sepo-events') . ' ' . $endDay : __('All day', 'sepo-events');
                        } else {
                            $sub[] = $startAt . ($endAt !== '' ? ' – ' . $endAt : '');
                            if ($duration !== '') {
                                $sub[] = $duration;
                            }
                        }
                        $sub[] = $tz;
                        echo esc_html(implode(' · ', array_filter($sub)));
                        ?>
                    </p>
                <?php else : ?>
                    <p class="ev-aside-sub"><?php esc_html_e('Date to be announced.', 'sepo-events'); ?></p>
                <?php endif; ?>
                <?php if ($c['location'] !== '') : ?>
                    <p class="ev-aside-loc"><?php echo Icons::get('pin'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html($c['location']); ?></span></p>
                <?php endif; ?>
                <p class="ev-aside-price"><?php echo Icons::get($c['is_paid'] ? 'card' : 'ticket'); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php
                    /* translators: %s: price */
                    echo esc_html($c['is_paid'] ? sprintf(__('%s per seat', 'sepo-events'), Event::money($c['price'], $c['currency'])) : __('Free entry', 'sepo-events'));
                ?></span></p>
                <div class="ev-aside-divider"></div>
                <p class="ev-aside-sub ev-aside-seats">
                    <?php
                    if ($c['capacity'] > 0) {
                        /* translators: 1: seats left, 2: capacity */
                        echo esc_html(sprintf(_n('%1$d of %2$d seat left.', '%1$d of %2$d seats left.', $c['capacity'], 'sepo-events'), (int) $n['seats_left'], $c['capacity']));
                    } else {
                        /* translators: %d: seats */
                        echo esc_html(sprintf(_n('%d seat taken.', '%d seats taken.', $seats, 'sepo-events'), $seats));
                    }
                    if ($n['waitlist'] > 0) {
                        /* translators: %d: people waiting */
                        echo ' ' . esc_html(sprintf(__('%d on the waitlist.', 'sepo-events'), (int) $n['waitlist']));
                    }
                    ?>
                </p>
                <?php if ($c['starts_utc'] !== null && !$c['private']) : ?>
                    <a class="ev-btn ev-btn--block" href="<?php echo esc_url(Event::icsUrl($id)); ?>"><?php echo Icons::get('calendar'); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('Add to calendar', 'sepo-events'); ?></a>
                <?php endif; ?>
                <?php if ($canManage) : ?>
                    <a class="ev-btn ev-btn--block ev-btn--quiet" href="<?php echo esc_url(\SepoEvents\Admin\Screens::dashboardUrl(['event' => $id])); ?>"><?php esc_html_e('Manage RSVPs', 'sepo-events'); ?></a>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <div class="ev-modal" data-ev-modal hidden role="dialog" aria-modal="true" aria-labelledby="ev-modal-title-<?php echo (int) $id; ?>">
        <div class="ev-modal__panel">
            <div class="ev-modal__head" id="ev-modal-title-<?php echo (int) $id; ?>" data-ev-modal-title></div>
            <div class="ev-modal__body" data-ev-modal-body></div>
            <div class="ev-modal__foot">
                <button type="button" class="ev-btn" data-ev-modal-no></button>
                <button type="button" class="ev-btn ev-btn--danger" data-ev-modal-yes></button>
            </div>
        </div>
    </div>
</div>
</div>
        <?php
        return (string) ob_get_clean();
    }

    /** Your own RSVP: status, ticket and a way to withdraw. @param array<string,mixed> $r @param array<string,mixed> $c */
    private static function mine(array $r, array $c, bool $isOver): string
    {
        $going = $r['status'] === 'going';
        $out = '<div class="ev-mine">';
        $out .= '<p class="ev-mine-status ' . ($going ? 'is-going' : 'is-waiting') . '">' . Icons::get($going ? 'check' : 'clock', 16)
            . '<span>' . esc_html($going ? __('You are confirmed.', 'sepo-events') : __('You are on the waitlist.', 'sepo-events')) . '</span></p>';
        /* translators: %d: seats */
        $out .= '<p class="ev-muted">' . esc_html(sprintf(_n('%d seat', '%d seats', (int) $r['guests'], 'sepo-events'), (int) $r['guests']));
        if ($r['name'] !== '') {
            $out .= ' · ' . esc_html($r['name']);
        }
        if ($r['guest_email'] !== '') {
            $out .= ' · ' . esc_html($r['guest_email']);
        }
        $out .= '</p>';

        if ($going && $r['checkin_code'] !== '' && !$isOver) {
            $url = Tools::url('checkin', ['event' => (int) $c['id'], 'code' => $r['checkin_code']]);
            $out .= '<div class="ev-ticket"><div class="ev-ticket-qr">' . Qr::svg($url, 132, 'M', __('Your check-in code', 'sepo-events')) . '</div>'
                . '<div class="ev-ticket-text"><span class="ev-ticket-label">' . esc_html__('Your check-in code', 'sepo-events') . '</span>'
                . '<code class="ev-ticket-code">' . esc_html($r['checkin_code']) . '</code>'
                . '<span class="ev-muted">' . esc_html__('Show this at the door.', 'sepo-events') . '</span>';
            foreach ($r['attendees'] as $a) {
                if (!empty($a['name']) && !empty($a['code'])) {
                    $out .= '<span class="ev-ticket-guest">' . esc_html($a['name']) . ' · <code>' . esc_html($a['code']) . '</code></span>';
                }
            }
            $out .= '</div></div>';
        }
        if ($c['proof'] !== 'off') {
            $out .= self::proofStatus($r, $c, $isOver);
        }
        if (!$isOver) {
            $out .= '<div class="ev-actions"><button type="button" class="ev-btn ev-btn--danger" data-ev-cancel="' . ($going ? 'going' : 'waitlist') . '">'
                . esc_html($going ? __('Cancel my RSVP', 'sepo-events') : __('Leave the waitlist', 'sepo-events')) . '</button></div>';
        }
        return $out . '</div>';
    }

    /** The file picker for proof of payment: drop zone, chosen-file list and the accepted types. */
    private static function proofField(bool $required, bool $standalone = false): string
    {
        $exts = Proofs::extensions();
        $accept = implode(',', array_map(static fn($e) => '.' . $e, $exts));
        $label = $standalone ? __('Attach a receipt, slip or screenshot', 'sepo-events') : ($required ? __('Proof of payment', 'sepo-events') : __('Proof of payment (optional)', 'sepo-events'));
        /* translators: 1: list of file types, 2: size in MB, 3: number of files */
        $help = sprintf(__('%1$s, up to %2$d MB each, at most %3$d files. A photo or screenshot of the receipt or mobile-money message works.', 'sepo-events'), Proofs::label(), Proofs::maxMb(), Proofs::MAX_FILES);
        return '<div class="ev-field ev-proof' . ($standalone ? ' ev-proof--standalone' : '') . '" data-ev-proof data-required="' . ($required ? '1' : '0') . '" data-max-mb="' . Proofs::maxMb() . '" data-max-files="' . Proofs::MAX_FILES . '" data-exts="' . esc_attr(implode(',', $exts)) . '">'
            . '<span>' . esc_html($label) . '</span>'
            . '<label class="ev-drop" data-ev-drop>'
            . '<input type="file" multiple accept="' . esc_attr($accept) . '" data-ev-proof-input>'
            . Icons::get('upload', 20)
            . '<span class="ev-drop-text"><b>' . esc_html__('Choose files', 'sepo-events') . '</b> ' . esc_html__('or drop them here', 'sepo-events') . '</span>'
            . '</label>'
            . '<ul class="ev-proof-list" data-ev-proof-list></ul>'
            . '<small>' . esc_html($help) . '</small>'
            . '</div>';
    }

    /** What you sent as proof of payment, whether it has been checked, and a way to add more. @param array<string,mixed> $r @param array<string,mixed> $c */
    private static function proofStatus(array $r, array $c, bool $isOver): string
    {
        $files = (array) $r['proofs'];
        $out = '<div class="ev-proof-box">';
        $out .= '<p class="ev-proof-head">' . esc_html__('Proof of payment', 'sepo-events');
        if ($files === []) {
            $out .= ' <span class="ev-pill ' . ($c['proof'] === 'required' ? 'ev-pill--warn' : '') . '">' . esc_html($c['proof'] === 'required' ? __('Needed', 'sepo-events') : __('None attached', 'sepo-events')) . '</span>';
        } elseif ((int) $r['verified_at'] > 0) {
            $out .= ' <span class="ev-pill ev-pill--good">' . Icons::get('check', 12) . esc_html__('Checked', 'sepo-events') . '</span>';
        } else {
            $out .= ' <span class="ev-pill ev-pill--wait">' . esc_html__('Waiting to be checked', 'sepo-events') . '</span>';
        }
        $out .= '</p>';
        if ($files !== []) {
            $out .= '<ul class="ev-proof-sent">';
            foreach ($files as $f) {
                $out .= '<li>' . Icons::get(str_starts_with((string) ($f['type'] ?? ''), 'image/') ? 'image' : 'file', 14) . '<span>' . esc_html((string) $f['name']) . '</span><small>' . esc_html(size_format((int) ($f['size'] ?? 0))) . '</small></li>';
            }
            $out .= '</ul>';
        }
        if (!$isOver && count($files) < Proofs::MAX_FILES) {
            $out .= '<form class="ev-proof-add" data-ev-proof-form novalidate>' . self::proofField(false, true)
                . '<div class="ev-actions"><button type="submit" class="ev-btn ev-btn--primary" data-ev-proof-send>' . esc_html($files === [] ? __('Send proof of payment', 'sepo-events') : __('Add more proof', 'sepo-events')) . '</button></div></form>';
        }
        return $out . '</div>';
    }

    private static function notice(string $icon, string $text, string $action = ''): string
    {
        return '<div class="ev-scope"><div class="ev-wrap"><div class="ev-empty ev-gate">' . Icons::get($icon, 20) . '<p>' . esc_html($text) . '</p>' . $action . '</div></div></div>';
    }
}
