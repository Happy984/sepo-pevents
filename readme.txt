=== Sepo Events ===
Contributors: euneolink
Tags: events, rsvp, registration, check-in, calendar
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events with RSVPs, a waitlist, attendee cards with QR codes and a door check-in scanner. Runs on WordPress alone, with no outside services.

== Description ==

Sepo Events gives each event its own page with Details, Host and RSVPs tabs beside a "When and where" box, keeps every RSVP in your own database, and gives the people running the event a dashboard, printable attendee cards and a check-in page for the door.

= Events =

* Add events under **Events → Add event**. The title is the event name, the excerpt its one-line description, the main text its details, and the featured image its banner.
* The Event details box sets the start and end (with time zone, or all day), location, capacity, what the RSVP form asks for, extra seats, sign-in only, closing registration, price and currency, the host and a details table.
* Sort events into **categories** (Events → Categories, or the Categories panel while editing) — Workshops, Sports, Services, whatever suits you. Visitors filter the events list by them.
* A private event uses WordPress's own Private visibility: only editors see it, and nobody can RSVP to it.
* Each event has its own page at `/events/<event-slug>/` and a calendar file at `/events/<event-slug>.ics` (the "Add to calendar" button).

= RSVPs =

* Guests or signed-in members RSVP with their email, and name and phone when the event asks for them.
* With extra seats on, people book up to the number you allow and give each extra person's details; each named person gets their own check-in code.
* Capacity counts seats. When the seats run out, new RSVPs join a waitlist. When someone cancels or is removed, the oldest waiting registrations that now fit move up automatically and are emailed.
* Seats are checked under a database lock, so two people can never take the last seat.
* People see their own RSVP on the event page with a QR ticket and their check-in code, and can cancel it there. A guest's RSVP is remembered in their browser, and the link in their confirmation email opens it on any device.
* Paid events record what each person paid; nothing is charged. The dashboard adds it up.

= Proof of payment =

* On a paid event, set **Proof of payment** to Optional or Required in the Event details box.
* People attach a receipt, bank slip or a screenshot of the mobile-money message when they RSVP — choose files or drop them in — or add them later from their own RSVP on the event page. Accepted: JPG, PNG, GIF, WebP, PDF, Word (DOC, DOCX), OpenDocument text and RTF, up to 5 files.
* Each file is checked by its content, not just its name, and kept in a private folder (`wp-content/uploads/sepo-events-private/`) with a random name. Only event managers can open the files, through the dashboard.
* On **Events → RSVPs**, each registration with proof shows "proof to check" or "payment checked". Open it to see the files (images as previews, PDFs open in the browser, Word documents download) and mark the payment as checked. People see "Waiting to be checked" or "Checked" on their RSVP. New files or a changed amount send a payment back to "to check".
* Set the largest file under **Events → Settings** (never above what your server accepts). The host alert address is told when proof arrives later.
* Deleting an RSVP, purging an event, erasing a person's data or deleting the event removes their files too.

= Emails =

Sent through wp_mail, so any SMTP or mail-service plugin carries them. People get an email when they RSVP, join the waitlist, move up from it or cancel, with their check-in code and a calendar link. You can also have every new RSVP copied to an address of your choice. Both are set under **Events → Settings**.

= The RSVP dashboard =

**Events → RSVPs** shows:

* Totals: events, registrations, seats taken and left, waitlist, cancellations, check-ins, full events and money collected (per currency).
* An Events tab with one row per event and links to its cards, check-in, RSVPs and edit screen. **Delete** moves an event to the bin (its page goes offline, its RSVPs are kept); events in the bin are listed with **Restore** and **Delete permanently**, which also removes their RSVPs and proof of payment files. Events in the bin count towards no total.
* An RSVPs tab with filters by event and status, a search by name, email, phone or code, a detail view of each registration, and Cancel, Delete and Purge event (all confirmed in the page, never in a browser pop-up).
* Export CSV and Export PDF (a print-ready page; use the browser's "Save as PDF") for both tabs. Exports follow the current filter.

The same dashboard can be placed on any page with `[sepo_event_rsvps]`; only event managers see it.

= Attendee cards and check-in =

* **Cards** prints one 4 × 6 inch badge per named person, with a QR code and their code written out.
* **Check-in** is a page for the door. In Scan mode the camera reads cards; in Manual mode you type a code, an email or a phone number (any spacing). Someone already checked in is reported as such, and a cancelled RSVP is refused.
* QR codes are generated by the plugin itself; nothing is loaded from another site. The in-page scanner uses the browser's built-in QR reader (Chrome and Edge on Android, Windows and macOS). On a browser without one, such as Safari on iPhone, point the phone's own camera app at the card: the QR code is a link that opens the check-in page with that person filled in and checks them in.

= Showing events on the site =

* On activation an "Events" page is created at `/events/` listing upcoming and past events as cards: the event's featured image as a cover, a large date block (month, day, weekday), then the title, time, place, price and seats left.
* The list has a toolbar: search (title, place, description, host), Upcoming / Past / All with counts, a category filter (when two or more categories are in use), Free / Paid (when both exist) and a grid or list layout, which each visitor's browser remembers. Events come a page at a time (9 by default) with page numbers. Everything filters in place without reloading; without JavaScript every event is still listed. Choose another page under **Events → Settings**.
* Shortcodes:
  * `[sepo_events]` lists events. Options: `show="all|upcoming|past"`, `per_page="9"`, `view="grid|list"`, `category="workshops,sports"` (category slugs), `toolbar="no"` to hide search and filters, `limit="6"`, `past="12"` (most past events), `title="Coming up"`.
  * `[sepo_event id="123"]` shows one event with its RSVP form on any page. Add `title="no"` to hide its heading.
  * `[sepo_event_rsvps]` shows the RSVP dashboard to event managers.
* Blocks: **Sepo event** and **Sepo events list**.
* Elementor: the **Sepo event** and **Sepo events list** widgets, in their own "Sepo events" category.

= Design =

Flat: tinted panels and outlines, no shadows. Set the accent colour under **Events → Settings**. Dark mode follows the page: the event pages switch to their dark palette when the theme marks the page as dark (a `dark`, `dark-mode` or `theme-dark` class, a `data-theme="dark"` or `data-bs-theme="dark"` attribute and similar), or when the page background itself is dark. This works with any theme.

= Privacy =

RSVPs are stored in the `{prefix}sepo_event_rsvps` table. They are included in WordPress's **Tools → Export Personal Data** and **Erase Personal Data**, and a suggested paragraph is added to the privacy policy guide.

== Installation ==

1. Upload the `sepo-events` folder to `/wp-content/plugins/`, or upload the zip under **Plugins → Add New → Upload Plugin**.
2. Activate the plugin. An "Events" page is created for the list.
3. Go to **Events → Add event**.

== Frequently Asked Questions ==

= Who can see RSVPs, print cards and check people in? =

Anyone who can edit other people's posts: by default editors and administrators. Change it with the `sepo_events_manage_capability` filter.

= Can someone change another guest's RSVP by typing their email? =

No. A guest email already on the list can only be changed or cancelled from the browser that made the RSVP, or through the link in that guest's confirmation email.

= Are proof of payment files private on nginx? =

On Apache and IIS the private folder is closed by the `.htaccess` and `web.config` files the plugin writes. nginx ignores those, so there the protection comes from long random file names and no folder listing. For full protection on nginx, add this to the server block:

`location ^~ /wp-content/uploads/sepo-events-private/ { deny all; }`

= Does the check-in page work offline? =

It needs a connection to the site to record each check-in.

== Developer hooks ==

* `sepo_events_rsvp` (action): `( array $record, array $event, bool $created )` after an RSVP is saved.
* `sepo_events_rsvp_cancelled` (action): `( array $record, array $event )`.
* `sepo_events_rsvp_promoted` (action): `( array $record, array $event )` when a waiting registration moves up.
* `sepo_events_rsvp_deleted` (action): `( string $id )`.
* `sepo_events_rsvps_purged` (action): `( int $eventId, int $removed )`.
* `sepo_events_checked_in` (action): `( array $result, int $eventId )`.
* `sepo_events_proof_added` (action): `( array $record, array $event, array $files )` when proof is added to an existing RSVP.
* `sepo_events_payment_verified` (action): `( string $id, bool $verified )`.
* `sepo_events_proof_mimes` (filter): the accepted proof file types, as extension pattern => MIME type.
* `sepo_events_manage_capability` (filter): the capability for the dashboard and door tools. Default `edit_others_posts`.
* `sepo_events_ip` (filter): the visitor IP used for the guest rate limit, for sites behind a proxy.
* `sepo_events_wide` (filter): return true to widen event pages and the events list to the theme's wide width (block themes). Off by default, so they sit centred in the content column like other page content.
* `sepo_events_show_title_on_event_page` (filter): return true to show the event heading inside the content as well as the theme's title.

The REST API lives under `/wp-json/sepo-events/v1/` (`rsvp`, `cancel`, `proof`, `upcoming`, and the `admin/*` routes for managers).

== Screenshots ==

1. Upcoming events.
2. An event page with details and RSVP.
3. Events in the admin with dates, seats and RSVPs.

== Changelog ==

= 1.3.6 =
* The plugin is by Euneolink Solutions. Its plugin page and author page are edu.sepodesk.com.

= 1.3.5 =
* Readme: screenshots and their captions for the plugin directory.

= 1.3.4 =
* Admin screens: settings shown as cards, lists in a rounded frame, rounded fields and buttons, pill-shaped tabs and filters, and notices on tinted backgrounds.
* Events list: wider title column, so each event's row is shorter.

= 1.3.3 =
* Proof-of-payment files are stored and served through the WordPress file-system API.
* The check-in page loads its script with the WordPress script helpers.
* Numbered placeholders in the file-size message, so translations can reorder them.
* Passes the WordPress Plugin Check: translator comments on every string with placeholders, escaped output, sanitised request data and documented database access to the plugin's own tables.
* Tested up to WordPress 7.1.

= 1.3.2 =
* The "When and where" box has a solid background taken from the page behind it (or the theme's base colour), stays in view only when it has its own column, and clears fixed theme headers and the admin bar.

= 1.3.1 =
* The events list and event pages sit centred in the theme's content column, like the forms list. In a narrow column the "When and where" box comes first.

= 1.3.0 =
* Events list toolbar: search, Upcoming / Past / All, category and price filters, grid or list layout, and pages.
* Event categories.

= 1.2.0 =
* Delete, restore and permanently delete events from the RSVP dashboard.
* Event cards show the cover image and a large date, with larger text throughout.

= 1.1.0 =
* Proof of payment: people attach images, PDFs or Word documents when they RSVP or later; managers open them and mark payments as checked.

= 1.0.0 =
* First release.
