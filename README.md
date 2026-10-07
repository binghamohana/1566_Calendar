# Grove Park Collective — Room Reservations

A simple, branded room-booking platform for Grove Park Collective, built to replace Skedda
(~$100/month) at **$0/month** on Dwell's existing Ubuntu LAMP server.

Tenants open one link, pick a space, tap an open time, enter name and email, and they're booked.
No account, no app to install. They get a confirmation email right away, a reminder about an hour
before, and a friendly "please leave the room as you found it" note afterwards. Management gets a
simple admin area for reservations, blocks, rooms, rules and email.

## What's included

**For tenants** (`/book/`)
- Landing page with a card per space: photo or pattern, capacity, amenities, live status
  ("Free until 1:30 PM", "In use until 3:00 PM") and today's timeline.
- Calendar: all spaces side by side for a day, or one space by day/week. Click or drag on desktop,
  tap on phones (with a swipeable date strip and a floating **Reserve** button).
- Booking form in a few fields: remembers name/email/company on that device, live availability
  check, quick duration chips, an optional "keep my name off the shared calendar".
- Confirmation with Add-to-calendar (.ics and Google Calendar) and a private **Manage
  reservation** link: change time, change room or cancel, with no password.
- "Find my reservations": emails fresh manage links if the confirmation is lost.
- New email addresses are approved once by the building manager: the booking is held as
  "pending", the manager approves or declines from an email link, and approved people (or whole
  tenant companies, e.g. `@acme.com`) book instantly from then on.

**For management** (`/book/admin/`)
- Dashboard: today, this week, this month, booked hours, most-used space, upcoming, recent
  cancellations, system health.
- Calendar with full details. Create, edit, cancel or delete reservations; block a room, several
  rooms or a whole day; repeating bookings and blocks with conflict reporting.
- Reservation search, filters, CSV export, per-booking history and email log.
- Approved emails: the list of addresses and tenant domains that book instantly, plus pending
  requests with one-click Approve / Approve whole company / Decline.
- Spaces: add, rename, describe, photo, color, weekly hours, per-room rules and instructions,
  disable, reorder.
- Settings: privacy of the public calendar, booking rules, email timing and wording, staff
  calendar feeds, test email. Admin accounts.

**Behind the scenes**
- Double booking is impossible: the database checks for overlaps and saves in one locked
  transaction (tested with 12 simultaneous requests).
- Emails go through an outbox with automatic retries. A booking never fails because email did.
- Spam protection that tenants don't notice: honeypot, signed form token and rate limits, plus
  manager approval for email addresses not already on the approved list.
- Designed for phones first, then tablets and desktops. No build step and no third-party scripts, fonts or trackers.

## The documentation the brief asked for

| Question | Answer |
|---|---|
| Architecture & why | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): native MySQL database (Option B) plus calendar feeds; Google Calendar as the backend was evaluated and rejected |
| Where it's hosted | Dwell's Ubuntu 22.04 LAMP server: code in `/var/www/gpc-reserve`, served at `https://www.58dwell.com/book/` ([deployment](docs/DEPLOYMENT.md)) |
| Where reservation data lives | MySQL database `gpc_reserve` on that server; nightly backups in `storage/backups/` |
| How email is sent | PHPMailer (bundled) over the SMTP account set in `config/config.php`. Reminders and after-use notes are sent by a 5-minute cron job |
| How admins get in | `https://www.58dwell.com/book/admin/` with email + password ([admin guide](docs/ADMIN-GUIDE.md)) |
| How rooms are added/edited | Admin → **Spaces** |
| How booking rules change | Admin → **Settings** (building-wide) and Admin → **Spaces** (opening hours and per-room overrides) |
| Troubleshooting | [docs/ADMIN-GUIDE.md#troubleshooting](docs/ADMIN-GUIDE.md#troubleshooting) and `sudo -u www-data php bin/check.php` |
| Recurring costs | $0/month ([details](docs/ADMIN-GUIDE.md#running-costs)) |
| Ownership | All code, data, photos and admin accounts on Dwell's server and in this repository |

## Requirements

PHP 8.1+ (pdo_mysql, mbstring, gd, openssl; exif recommended), MySQL 5.7+/8 or MariaDB 10.3+,
Apache, and cron. Tested with PHP 8.1 and 8.3, MariaDB 10.11 and Apache 2.4.

## Project layout

```
public/            the only web-visible folder (point /book/ here)
  index.php        tenant booking page          manage.php   "manage my reservation"
  api.php          public JSON API              ics.php      calendar files & staff feeds
  admin/           admin page + admin JSON API
  assets/          CSS, JavaScript (jQuery), fonts, images
  uploads/spaces/  space photos (writable)
src/               PHP application classes
lib/PHPMailer/     PHPMailer 6.12 (bundled, LGPL)
config/            config.sample.php → copy to config.php (not in git)
sql/schema.sql     database tables
bin/               install.php, cron.php, check.php, admin.php, backup.sh
docs/              architecture, deployment, admin guide, Apache sample
storage/           logs and backups (writable, not web-visible)
tests/             integration tests (run.php) and demo data (seed-demo.php)
```

## Quick start (development)

```bash
cp config/config.sample.php config/config.php   # set db.* and app_secret, keep mail transport 'log'
php bin/install.php
php -S localhost:8080 -t public
GPC_DB_NAME=gpc_test php tests/run.php           # needs a separate *test* database
```

## Decisions that need management's approval

1. **Which SMTP account sends the emails.** It should be a Dwell-owned account (Google Workspace
   relay or the account 58dwell.com already uses with PHPMailer), and the from-domain needs
   SPF/DKIM for good deliverability. No new cost expected.
2. **Public address.** `www.58dwell.com/book/` needs no DNS changes. A branded
   `reserve.groveparkcollective.com` needs one DNS record plus an Apache site and certificate
   (free with certbot).
3. **Remove the public phpinfo page** at `/book/phpinfo.php`. It reveals server details to anyone,
   and deployment step 5 moves it out of the web root.
