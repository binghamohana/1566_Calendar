# Deployment: Dwell's Ubuntu LAMP server

Target server, from its phpinfo: Ubuntu 22.04, **PHP 8.1 (PHP-FPM)**, Apache, MySQL, HTTPS,
web root `/var/www/verus/fiftyeight` for www.58dwell.com. All required PHP extensions are already
installed (pdo_mysql, gd with JPEG/WebP, exif, mbstring, openssl). Nothing new has to be installed.

**Public address:** `https://www.58dwell.com/book/` (works today, no DNS change).
A branded address like `reserve.groveparkcollective.com` can be added later; see the end of this file.

The plan keeps the app **outside** the web root at `/var/www/gpc-reserve` and makes only its
`public/` folder visible at `/book/`. Code, config, logs and backups can then never be downloaded.

---

## 1. Put the code on the server

```bash
sudo git clone https://github.com/binghamohana/1566_calendar.git /var/www/gpc-reserve
# or upload the project folder to /var/www/gpc-reserve
cd /var/www/gpc-reserve
sudo chown -R www-data:www-data storage public/uploads
```

## 2. Create the database

```bash
sudo mysql <<'SQL'
CREATE DATABASE gpc_reserve CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gpc_reserve'@'localhost' IDENTIFIED BY 'PASTE-A-LONG-RANDOM-PASSWORD';
GRANT ALL PRIVILEGES ON gpc_reserve.* TO 'gpc_reserve'@'localhost';
FLUSH PRIVILEGES;
SQL
```

The app's MySQL user can only reach its own database, so it never touches Nextcloud or other sites.

## 3. Configure

```bash
sudo cp config/config.sample.php config/config.php
sudo chown root:www-data config/config.php && sudo chmod 640 config/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"     # copy this for app_secret
sudo nano config/config.php
```

Set:

| Key | Value |
|---|---|
| `base_url` | `https://www.58dwell.com/book` |
| `app_secret` | the random string from above |
| `db.password` | the password from step 2 (`host` stays `localhost`) |
| `mail.transport` | `smtp` |
| `mail.host`, `port`, `encryption`, `username`, `password` | the SMTP account PHPMailer should use (see step 8) |
| `mail.from_email`, `from_name` | e.g. `reservations@groveparkcollective.com`, `Grove Park Collective` |

## 4. Create the tables, launch spaces and first admin

```bash
sudo -u www-data php bin/install.php
```

It asks for the first administrator's email, name and password. It's safe to re-run: it only
creates what's missing.

## 5. Publish at /book/

The `book` folder in the web root currently only holds the phpinfo page. Move it aside, then
point `/book` at the app's public folder:

```bash
sudo mv /var/www/verus/fiftyeight/book /root/book-old-phpinfo     # removes the public phpinfo page too
sudo ln -s /var/www/gpc-reserve/public /var/www/verus/fiftyeight/book
```

Apache follows symlinks under `/var/www` by default on Ubuntu. If `/book/` returns 403, either add
`Options +FollowSymLinks` for that site, or use an Alias in the site's Apache config instead of the
symlink:

```apache
Alias /book /var/www/gpc-reserve/public
<Directory /var/www/gpc-reserve/public>
    Require all granted
    AllowOverride All
</Directory>
```

(then `sudo systemctl reload apache2`).

> Don't copy the whole project into the web root. If you ever do, the project's `.htaccess`
> files block the private folders, but only when Apache allows `.htaccess` (`AllowOverride All`).

## 6. Check everything

```bash
sudo -u www-data php bin/check.php --send-test=you@example.com
```

Then open `https://www.58dwell.com/book/` and `https://www.58dwell.com/book/admin/`.

## 7. Background tasks and nightly backup

```bash
sudo tee /etc/cron.d/gpc-reserve >/dev/null <<'CRON'
# Grove Park Collective reservations
*/5 * * * * www-data php /var/www/gpc-reserve/bin/cron.php >> /var/www/gpc-reserve/storage/logs/cron.log 2>&1
15 2 * * *  www-data /var/www/gpc-reserve/bin/backup.sh >> /var/www/gpc-reserve/storage/logs/backup.log 2>&1
CRON
```

`cron.php` sends reminders and after-use notes, and retries failed emails. Admin → Dashboard
shows a red warning if it stops running. `backup.sh` keeps 30 days of compressed database dumps in
`storage/backups/`. Copy that folder off the server as well (NAS, Google Drive).

## 8. Email through PHPMailer

PHPMailer 6.12 is bundled in `lib/PHPMailer`, so there's nothing to install. Use an SMTP account
**owned by Dwell / Grove Park Collective**, not a personal mailbox:

- **Google Workspace:** SMTP relay (`smtp-relay.gmail.com:587`, configured in the Workspace admin
  console) or a dedicated mailbox with an app password (`smtp.gmail.com:587`).
- **The SMTP account 58dwell.com already uses with PHPMailer:** works the same. Put its host,
  port and login in `config.php`.

The from-address's domain must allow that SMTP server (SPF/DKIM), or messages may land in spam.
Send a test from **Admin → Settings → Send test email**.

## Updating later

```bash
cd /var/www/gpc-reserve
sudo git pull
sudo -u www-data php bin/install.php      # applies any new tables; safe to re-run
sudo -u www-data php bin/check.php
```

## Restoring a backup

```bash
gunzip -c storage/backups/gpc-reserve-YYYYMMDD-HHMM.sql.gz | sudo mysql gpc_reserve
```

## Optional: branded address (needs approval: DNS + Apache)

To serve `https://reserve.groveparkcollective.com`:
1. DNS: add an `A` record for `reserve` pointing at the same public IP as www.58dwell.com.
2. Apache: copy `docs/apache-vhost.conf` to `/etc/apache2/sites-available/gpc-reserve.conf` (its
   DocumentRoot is already `/var/www/gpc-reserve/public`), enable it, and run
   `sudo certbot --apache -d reserve.groveparkcollective.com` for a free HTTPS certificate.
3. Change `base_url` in `config.php`. Old `/book/` links keep working if you leave the symlink.

---

## Developer notes

- Tests: `GPC_DB_NAME=gpc_test php tests/run.php` (needs a database whose name contains `test`;
  it empties it). Covers rules, conflicts, a 12-way concurrency race, emails, privacy, DST.
- Sample data for a development copy: `php tests/seed-demo.php` (refuses unless `debug` is on).
- No build step: edit files in `public/assets` and reload. CSS/JS URLs are cache-busted automatically.
