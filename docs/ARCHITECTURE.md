# Architecture recommendation

## Decision

**Option B — a native booking database**, running on Dwell's existing Ubuntu LAMP server
(PHP 8.1 + MySQL + Apache, jQuery on the front end), with **read-only calendar feeds** so staff
can still see every booking in Google Calendar or Outlook.

| | A — Google Calendar backend | **B — Native database (chosen)** |
|---|---|---|
| Prevents double booking | No. The Calendar API accepts overlapping events, so we would still need our own locking layer, which means a database anyway | **Yes.** One database transaction checks for overlaps and saves the booking, so two confirmed bookings can't overlap (tested with 12 simultaneous requests) |
| Moving parts | App + Google Cloud project + service account + domain-wide delegation + resource calendars + API quotas | **App + MySQL** (both already on the server) |
| Ongoing cost | $0 (but needs Workspace admin setup) | **$0** |
| Maintenance | Credentials expire and APIs get deprecated; when Google has an outage, bookings stop | **Nothing external to break.** Back up one database |
| Privacy control | Event details are visible to anyone the calendars are shared with | **Field-level control** over what the public calendar shows |
| Custom emails (reminder, cleanup note) | Google can't send the after-use cleanup note; we'd build it anyway | **Built in**, sent through PHPMailer |
| Staff see bookings in Google Calendar | Natively | **Via subscription feeds** (Google refreshes them every few hours) |
| Ownership | Tied to a Workspace account and its permissions | **Self-contained**: code, data and admin accounts all on Dwell's server |

Option A looks simpler on paper, but it removes none of the hard parts: we would still need our
own rules, conflict locking, emails and admin screens, and we'd add a fragile external
dependency on top. Option B does everything the brief asks with fewer pieces.

The one thing Google does better is live syncing. If staff ever need that, one-way pushing to
Google resource calendars can be added later as a hook after each create, change or cancel,
without changing anything else.

## How it fits together

```
Tenant's browser ──► public/index.php  (booking page: jQuery app, no build step)
                     public/api.php    (JSON: availability, book, change, cancel)
                     public/manage.php (private "manage reservation" link)
                     public/ics.php    (Add-to-calendar files + staff feeds)
Admin's browser ───► public/admin/     (management app + JSON API, password login)
                          │
                          ▼
                     src/  (PHP classes: Bookings, Spaces, Settings, Mailer, Notify, Jobs…)
                          │
                          ├──► MySQL database  gpc_reserve
                          └──► PHPMailer ─► SMTP server ─► tenant inboxes
cron (every 5 min) ► bin/cron.php: reminders, after-use notes, email retries, cleanup
```

Only `public/` is reachable from the web. Code, configuration, logs and backups live beside it.

## Key design points

**Double booking cannot happen.** Every create or change runs inside one MySQL transaction:
1. Lock the space's row (`SELECT … FOR UPDATE`). A second request for the same space waits here.
2. Look for any confirmed booking that overlaps (`start < new_end AND end > new_start`).
3. Insert or update, then commit.

Other spaces are never blocked by this lock. The browser also checks availability as you type,
but that is only a convenience; the server decides. `tests/run.php` starts 12 processes that
all book the same slot at the same instant and confirms exactly one succeeds.

**Common booking problems are handled:**
- *Double-click / refresh while submitting:* each form carries a random request ID. A repeat
  submission returns the original booking instead of creating a second one, and a page that was
  refreshed mid-booking asks the server whether it went through.
- *End before start, invalid email, past times, outside opening hours:* all rejected on the server
  with a clear message next to the right field.
- *Moving a booking onto an occupied time:* rejected the same way as a new booking.
- *Email fails:* the booking is saved first. Every email goes into an outbox table, and
  failures are retried automatically with back-off (5 attempts) and shown in the admin
  email log.

**Times** are stored in UTC and shown in the building's time zone (America/New_York), so
daylight-saving changes never shift a booking or a reminder.

**Privacy.** The public availability feed contains only the space, the times and a label built
from the privacy settings ("Reserved", "Reserved — Sam", …). Email addresses, notes and
management links are never sent to other visitors. Tenants can also mark a booking private.

**No tenant accounts.** Each reservation gets an unguessable 48-character private link
(`manage.php?t=…`), which is emailed to the person who booked. That link can change or cancel
that one reservation and nothing else. Lost the email? "Find my reservations" emails fresh links
to the booking address.

**Spam and abuse protection, invisible to tenants:**
- a hidden honeypot field and a signed page token (scripts that post directly are refused)
- limits on new bookings per network per hour, upcoming bookings per email, maximum length and
  how far ahead
- an optional allow-list of tenant email domains

**Admin security:** bcrypt passwords, sign-in rate limiting, signed HttpOnly SameSite cookies,
CSRF tokens on every change, and strict security headers (CSP, frame denial) on every page.

## Data model

| Table | Holds |
|---|---|
| `spaces` | Rooms: name, description, capacity, amenities, photo, color, opening hours (JSON), per-space rule overrides, reminder instructions, on/off |
| `bookings` | Reservations **and** admin blocks (`kind`), times (UTC), who/what, private flag, series ID for repeats and multi-room blocks, manage token, status |
| `emails` | Outbox and log for every email: status, attempts, last error |
| `settings` | Admin-editable settings (defaults live in `src/Settings.php`, so new settings need no migration) |
| `admins` | Management accounts |
| `audit_log` | Who created, changed, cancelled or deleted what, and when |
| `rate_events` | Short-lived counters for rate limiting |

## Room to grow

None of these are built yet, but nothing in the design blocks them:

| Future need | Where it would go |
|---|---|
| More rooms | Already supported in Admin → Spaces |
| More buildings | Add a `buildings` table and `spaces.building_id`; filter by building on the page |
| Tenant accounts / organizations | Add `tenants` / `organizations` tables; bookings already store email and company |
| Booking limits, max lengths | Already in Settings; per-space overrides exist |
| Approval-required spaces | Add `status = 'pending'` and an approve button. The overlap check already only counts `confirmed` |
| Paid bookings / external rentals | Add a price per space and a payment step before confirming |
| Equipment, TV, catering, setup requests | Add a `booking_extras` table linked to bookings |
| Usage analytics | The dashboard already has a stats module (`src/Stats.php`), and CSV export exists |
| QR codes outside rooms / check-in | Link to `#/space/<slug>`; add a `checked_in_at` column |
| Google/Microsoft invitations | Confirmations already attach an `.ics` file; true sync = hook after create/change/cancel |
