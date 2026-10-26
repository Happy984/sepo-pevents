# Sepo Events

Events for WordPress, with RSVPs, a waitlist, attendee cards with QR codes and a check-in scanner for the door.
Each event gets its own page, and every RSVP is kept in your own database. The people running the event get a dashboard, printable attendee cards and a check-in page. It runs on WordPress alone, with no outside services.

<p align="center">
  <a href="https://edu.sepodesk.com/sponsor/projects-maintenance/">
    <img src="https://img.shields.io/badge/%E2%9D%A4%20SUPPORT%20OUR%20OPEN--SOURCE%20PROJECTS-0b6b45?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Support Our Open-Source Projects" />
  </a>
</p>

<p align="center">
  Your support helps us maintain, improve and build open-source software for everyone.
  <br />
  <strong>Every contribution makes a difference. Thank you for supporting our work!</strong>
</p>


![Version](https://img.shields.io/badge/version-1.3.2-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)



## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Shortcodes, blocks and widgets](#shortcodes-blocks-and-widgets)
- [Privacy](#privacy)
- [Uninstalling](#uninstalling)
- [FAQ](#faq)
- [For developers](#for-developers)
- [Changelog](#changelog)
- [License](#license)

## Features

### Events

- Each event page has **Details**, **Host** and **RSVPs** tabs, next to a **When and where** box.
- The **Event details** box sets:
  - start and end, with a time zone, or all day;
  - location and capacity;
  - what the RSVP form asks for, and extra seats;
  - sign-in only, and when registration closes;
  - price and currency;
  - the host and a details table.
- Sort events into **categories**, such as Workshops, Sports or Services. Visitors can filter the events list by them.
- A **Private** event (WordPress's own Private visibility) is seen only by editors, and nobody can RSVP to it.
- Each event has its own page at `/events/<event-slug>/`, and a calendar file at `/events/<event-slug>.ics` for **Add to calendar**.

### RSVPs

- Guests or signed-in members RSVP with their email. They also give a name and phone number if the event asks for them.
- **Extra seats.** People can book up to the number you allow and give each extra person's details. Each named person gets their own check-in code.
- **Capacity and waitlist.** Capacity counts seats. When the seats run out, new RSVPs join a waitlist.
  - When someone cancels or is removed, the oldest waiting registrations that now fit move up automatically and are emailed.
  - Seats are checked under a database lock, so two people can never take the last seat.
- **Their own RSVP.** People see their RSVP on the event page with a QR ticket and their check-in code, and can cancel it there.
  - A guest's RSVP is remembered in their browser.
  - The link in their confirmation email opens it on any device.
- **Paid events.** The plugin records what each person paid. Nothing is charged. The dashboard adds it up.

### Proof of payment

- On a paid event, set **Proof of payment** to **Optional** or **Required**.
- People attach a receipt, bank slip or mobile-money screenshot when they RSVP, or add it later from their RSVP.
  - Accepted types: JPG, PNG, GIF, WebP, PDF, DOC, DOCX, ODT and RTF.
  - Up to 5 files.
- Files are checked by their content, not just their name. They are stored with random names in a private folder, and only event managers can open them.
- In the dashboard, each RSVP with proof shows **proof to check** or **payment checked**. Managers open the files and mark the payment as checked.
- People see **Waiting to be checked** or **Checked** on their RSVP.

### Emails

- People get an email when they RSVP, join the waitlist, move up or cancel. It includes their check-in code and a calendar link.
- Every new RSVP can also be copied to an address you choose.
- Emails are sent through `wp_mail`, so any SMTP or mail-service plugin delivers them.

### RSVP dashboard

**Events → RSVPs** shows:

- **Totals:** events, registrations, seats taken and left, waitlist, cancellations, check-ins, full events and money collected per currency.
- **Events tab:** one row per event, with links to its cards, check-in, RSVPs and edit screen.
  - Delete, restore or permanently delete events.
  - Permanently deleting an event also removes its RSVPs and proof files.
- **RSVPs tab:**
  - filters by event and status;
  - search by name, email, phone or code;
  - a detail view of each registration;
  - **Cancel**, **Delete** and **Purge event**, all confirmed in the page.
- **Export CSV** and **Export PDF** for both tabs. Exports follow the current filter.

### Attendee cards and check-in

- **Cards** prints one 4 × 6 inch badge per named person, with a QR code and their code written out.
- **Check-in** is a page for the door.
  - **Scan** mode reads cards with the camera.
  - **Manual** mode takes a code, an email or a phone number.
  - Someone already checked in is reported as such. A cancelled RSVP is refused.
- QR codes are made by the plugin itself.
- The in-page scanner uses the browser's built-in QR reader, which is in Chrome and Edge on Android, Windows and macOS.
  - On other browsers, such as Safari on iPhone, point the phone's camera app at the card instead.
  - The QR code is a link that opens the check-in page and checks that person in.

### Events list

- On activation, an **Events** page is created at `/events/`. It lists events as cards with a cover image, a large date, the time, place, price and seats left.
- A toolbar offers:
  - search;
  - Upcoming, Past or All;
  - a category filter;
  - Free or Paid;
  - a grid or list layout.
- Events come a page at a time, and everything filters without reloading the page.
- Without JavaScript, every event is still listed.

### Design

- Flat design: tinted panels and outlines, no shadows.
- Set the accent colour under **Events → Settings**.
- Dark mode follows the page, with any theme.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |

## Installation

### Upload the zip (recommended)

1. Download `sepo-events.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**. An **Events** page is created for the list.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-events` folder into `wp-content/plugins/` on your server.
3. Go to **Plugins** and activate **Sepo Events**.

> [!NOTE]
> GitHub's **Code → Download ZIP** names the folder after the branch, for example `sepo-events-main`. Rename it to `sepo-events` before uploading.

## Getting started

1. Go to **Events → Settings**. Set the accent colour, the emails and the largest file size for proof of payment.
2. Go to **Events → Add event**. Here is what each part of the editor becomes:

   | Editor field | On the event page |
   |---|---|
   | Title | The event name |
   | Excerpt | The one-line description |
   | Main text | The details |
   | Featured image | The banner |

3. Fill in the **Event details** box and publish.
4. On the day, open **Events → RSVPs**, choose the event, and use **Cards** and **Check-in**.

## Shortcodes, blocks and widgets

| Shortcode | What it shows |
|---|---|
| `[sepo_events]` | The events list. |
| `[sepo_event id="123"]` | One event with its RSVP form. Add `title="no"` to hide its heading. |
| `[sepo_event_rsvps]` | The RSVP dashboard. Only event managers see it. |

`[sepo_events]` options:

| Option | Values |
|---|---|
| `show` | `all`, `upcoming` or `past` |
| `per_page` | Events per page, default `9` |
| `view` | `grid` or `list` |
| `category` | Category slugs, such as `workshops,sports` |
| `toolbar` | `no` hides search and filters |
| `limit` | Most events to show |
| `past` | Most past events to show |
| `title` | A heading, such as `Coming up` |

The plugin also includes:

- the **Sepo event** and **Sepo events list** blocks;
- the matching Elementor widgets, in their own "Sepo events" category.

## Privacy

- RSVPs are stored in the `{prefix}sepo_event_rsvps` table.
- They are included in **Tools → Export Personal Data** and **Tools → Erase Personal Data**.
- A suggested paragraph is added to the privacy policy guide.

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps everything.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files.

- **By default your events and RSVPs are kept.** Only short-lived cached data is cleared.
- **To remove everything**, first tick **When the plugin is deleted, also delete every event and RSVP** under **Events → Settings** and save, then delete the plugin. This removes:
  - every event;
  - the RSVP table;
  - all proof of payment files;
  - the Events page the plugin created, as long as it still holds the events list;
  - the settings.

## FAQ

**Who can see RSVPs, print cards and check people in?**
Anyone who can edit other people's posts. By default that is editors and administrators.

**Can someone change another guest's RSVP by typing their email?**
No. A guest RSVP can be changed or cancelled only from the browser that made it, or through the link in that guest's confirmation email.

**Are proof of payment files private on nginx?**
On Apache and IIS, the private folder is closed by the `.htaccess` and `web.config` files the plugin writes. nginx ignores those files, so there the protection comes from long random file names. For full protection, ask your host to add this to the server block:

```nginx
location ^~ /wp-content/uploads/sepo-events-private/ { deny all; }
```

**Does the check-in page work offline?**
No. It needs a connection to the site to record each check-in.

## For developers

### Actions

| Action | Arguments |
|---|---|
| `sepo_events_rsvp` | `( array $record, array $event, bool $created )` after an RSVP is saved |
| `sepo_events_rsvp_cancelled` | `( array $record, array $event )` |
| `sepo_events_rsvp_promoted` | `( array $record, array $event )` when a waiting registration moves up |
| `sepo_events_rsvp_deleted` | `( string $id )` |
| `sepo_events_rsvps_purged` | `( int $eventId, int $removed )` |
| `sepo_events_checked_in` | `( array $result, int $eventId )` |
| `sepo_events_proof_added` | `( array $record, array $event, array $files )` |
| `sepo_events_payment_verified` | `( string $id, bool $verified )` |

### Filters

| Filter | Purpose |
|---|---|
| `sepo_events_proof_mimes` | Sets the accepted proof file types. |
| `sepo_events_manage_capability` | Sets the capability for the dashboard and door tools. Default `edit_others_posts`. |
| `sepo_events_ip` | Gives the visitor's IP for the guest rate limit, behind a proxy. |
| `sepo_events_wide` | Return `true` to use the theme's wide width (block themes). |
| `sepo_events_show_title_on_event_page` | Return `true` to show the event heading inside the content too. |

### REST API

The REST API is under `/wp-json/sepo-events/v1/`:

- `rsvp`
- `cancel`
- `proof`
- `upcoming`
- the `admin/*` routes, for managers

## Changelog

### 1.3.2
- The **When and where** box has a solid background taken from the page behind it, or the theme's base colour.
- The box stays in view only when it has its own column, and clears fixed theme headers and the admin bar.

### 1.3.1
- The events list and event pages sit centred in the theme's content column.
- In a narrow column, the **When and where** box comes first.

### 1.3.0
- Events list toolbar: search, Upcoming / Past / All, category and price filters, grid or list layout, and pages.
- Event categories.

### 1.2.0
- Delete, restore and permanently delete events from the RSVP dashboard.
- Event cards show the cover image and a large date, with larger text throughout.

### 1.1.0
- Proof of payment: people attach images, PDFs or Word documents when they RSVP or later. Managers open them and mark payments as checked.

### 1.0.0
- First release.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
