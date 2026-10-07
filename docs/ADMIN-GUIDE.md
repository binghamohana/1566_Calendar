# Admin guide

**Admin:** `https://www.58dwell.com/book/admin/`  ·  **Tenant page:** `https://www.58dwell.com/book/`

Sign in with your admin email and password. Sessions last 14 days per device.

## Everyday tasks

| I want to… | Do this |
|---|---|
| See today / this week | **Dashboard** (counts, hours, upcoming, recent cancellations) or **Calendar** |
| See who booked something | Click the booking in **Calendar**, or search in **Reservations**. Shows name, email, company, notes, when and how it was booked, every email sent, and a change history |
| Book for a tenant | **New reservation** (top right), or click an open time in **Calendar**. Admins can book outside opening hours and limits |
| Change or move a booking | Open it → **Edit**. Tick "email the updated details" to notify them |
| Cancel | Open it → **Cancel**. Optional reason (included in the email); option to cancel all later dates of a repeating series |
| Delete outright | Open it → **Delete** (for mistakes; no email, history removed). Prefer Cancel to keep a record |
| Block a room (maintenance, private event) | **Block time** → pick one or more spaces, date and time. The reason shows publicly as "Unavailable · reason" |
| Block an entire day (holiday) | **Block time** → tick every space → **All day** |
| Close for several days | **Block time** → **All day** → Repeat **Every day** until the last day |
| Repeating booking or block | Choose a Repeat (daily, weekdays, weekly, every 2 weeks, monthly) and an end date. If some dates are taken you'll see the list and can **skip those and create the rest**. Nothing is ever double-booked |
| Search | **Reservations**: search name, email, company, title or reference; filter by space, date range, status, type |
| Export | **Reservations → Export CSV** (follows the current filters) |
| Give a tenant their link again | Open the booking → **Manage link → Copy**. Tenants can also use "Find my reservations" on the public page |

## Spaces

**Spaces → Add space / Edit**
- Name, short name (calendar column), location, capacity, description, amenities, calendar color.
- **Photo:** upload a JPG/PNG/WebP (resized automatically, landscape works best). Without a photo
  the card shows an architectural pattern in the space's color.
- **When can tenants book it?** "Any time" (default) or weekly hours per day, e.g.
  Mon–Fri 7 AM–10 PM, Sat 8 AM–10 PM, Sun 10 AM–8 PM. Untick a day to close it.
- Per-space overrides for longest booking and how far ahead.
- **Room instructions** (TV remote, door code…): added to that room's reminder email.
- **After-use message**: replaces the building default for that room.
- **Available for booking** off = hidden from tenants, history kept. Spaces that have never been
  booked can be deleted.
- Use the arrows to change the order on the booking page.

## Settings

- **General:** name, headline, intro text, management email/phone (shown on the page; replies to
  automated emails go to the management email), time zone.
- **Public calendar privacy:** show just "Reserved", first name (default) or full name; optionally
  company or meeting title. Email addresses are never shown publicly.
- **Booking rules:** time steps (15 min), shortest/longest booking (default 12 h), how far ahead
  (default 120 days), minimum notice, max upcoming bookings per person (25), bookings per network
  per hour (30), optional allowed email domains (e.g. only tenant company domains).
- **Automated emails:** reminder on/off and lead time (default 60 min before), after-use note
  on/off and timing (default at the end time), the cleanup message, building info for reminders,
  and optional copies of new bookings/cancellations to management. **Send test email** checks
  delivery.
- **Calendar feeds for staff:** private links to subscribe in Google Calendar ("Other calendars → +
  → From URL") or Outlook. Google refreshes these every few hours, so for up-to-the-minute
  availability use the app itself. **Generate new links** if a link leaks.

## Emails tenants receive

1. **Confirmation**, right away: space, date, time, title, reference, a "Manage reservation"
   button (change time/room or cancel, no password), and an "Add to calendar" attachment.
2. **Reminder**, about 1 hour before, with room and building instructions. Skipped if they booked
   less than ~75 minutes ahead (the confirmation just arrived).
3. **After-use note**, at the end time: "Thanks for using the … please leave the room the way you
   found it…".
4. **Updated** / **Cancelled** notices when a booking changes.

All emails are listed in **Email log** with their status.

## Administrators

**Administrators** → add or disable accounts, reset passwords. **Change my password** signs out your
other devices. If everyone is locked out, on the server:

```bash
cd /var/www/gpc-reserve
sudo -u www-data php bin/admin.php list
sudo -u www-data php bin/admin.php password you@example.com
sudo -u www-data php bin/admin.php create someone@example.com "Their Name"
```

## Troubleshooting

| Symptom | Check |
|---|---|
| Dashboard says **Background tasks not running** | The cron job (DEPLOYMENT.md step 7). Run `sudo -u www-data php /var/www/gpc-reserve/bin/cron.php` by hand; look at `storage/logs/cron.log` |
| No confirmation emails | **Email log**: a failed row shows the SMTP error. Use **Retry** after fixing `config/config.php`. **Settings → Send test email**. Run `bin/check.php --send-test=you@…` |
| Emails land in spam | The from-address's domain needs SPF/DKIM that allow your SMTP server |
| Dashboard says **Email is in test mode** | `mail.transport` is `log` in config.php; set it to `smtp` |
| Page says "temporarily unavailable" | Database is down or config is wrong: `sudo -u www-data php bin/check.php`; details in `storage/logs/php-error.log` |
| A tenant says "too many reservations" | Raise **bookings per network per hour** (everyone in the building may share one internet address) or **max upcoming per person** |
| A tenant lost their link | Open their booking → copy the Manage link, or have them use **Find my reservations** |
| Times look an hour off | **Settings → Time zone** should be `America/New_York` |
| Photo upload fails | `public/uploads/spaces` must be writable by www-data: `sudo chown -R www-data /var/www/gpc-reserve/public/uploads` |
| Need yesterday's data back | `storage/backups/` (DEPLOYMENT.md → Restoring a backup) |

Logs: `storage/logs/php-error.log` (app errors), `storage/logs/cron.log`, Apache's site error log.

## Running costs

| Item | Cost |
|---|---|
| Hosting | $0, Dwell's existing server |
| Database, PHP, PHPMailer, jQuery, fonts | $0, open source and stored with the app (no third-party scripts or trackers) |
| Email | $0 using Dwell's existing SMTP / Google Workspace (a few hundred emails a month) |
| Domain | $0 for `www.58dwell.com/book/`; a branded subdomain is just a DNS record on a domain you already own |
| **Total** | **$0/month**, replacing Skedda's ~$100/month (~$1,200/year) |

## Ownership

Everything is on Dwell's server: code (also in the `binghamohana/1566_calendar` GitHub repo), the
MySQL database, uploaded photos and backups. Admin accounts live in the app's own database.
Nothing depends on a personal account: use a Dwell-owned SMTP login in `config/config.php`.
