-- Grove Park Collective — Room Reservations
-- MySQL 5.7+/8.x or MariaDB 10.3+. All DATETIME columns are stored in UTC.
-- Safe to re-run: every statement is CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS spaces (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug                 VARCHAR(80)  NOT NULL,
  name                 VARCHAR(120) NOT NULL,
  short_name           VARCHAR(60)  NULL,
  location             VARCHAR(120) NULL,
  description          TEXT         NULL,
  capacity             SMALLINT UNSIGNED NULL,
  amenities            VARCHAR(500) NULL,
  photo                VARCHAR(255) NULL,
  color                VARCHAR(7)   NOT NULL DEFAULT '#3E5C4A',
  instructions         TEXT         NULL COMMENT 'Included in the reminder email',
  cleanup_message      TEXT         NULL COMMENT 'Overrides the global after-use message',
  hours                TEXT         NULL COMMENT 'JSON weekly hours; NULL = always available',
  max_duration_minutes INT UNSIGNED NULL COMMENT 'NULL = use global rule',
  max_days_ahead       INT UNSIGNED NULL COMMENT 'NULL = use global rule',
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order           INT          NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL,
  updated_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_spaces_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref               VARCHAR(10)  NOT NULL COMMENT 'Human-friendly reference code',
  space_id          INT UNSIGNED NOT NULL,
  kind              ENUM('reservation','block') NOT NULL DEFAULT 'reservation',
  status            ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed' COMMENT 'pending = waiting for approval of a new email address',
  start_utc         DATETIME     NOT NULL,
  end_utc           DATETIME     NOT NULL,
  title             VARCHAR(150) NULL,
  name              VARCHAR(120) NULL,
  email             VARCHAR(190) NULL,
  company           VARCHAR(150) NULL,
  notes             TEXT         NULL,
  is_private        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Public calendar shows "Reserved" only',
  notify            TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Send automated emails for this booking',
  series_id         VARCHAR(16)  NULL COMMENT 'Groups recurring / multi-space bookings',
  manage_token      VARCHAR(64)  NULL,
  request_id        VARCHAR(64)  NULL COMMENT 'Client idempotency key (prevents double submits)',
  source            ENUM('public','admin') NOT NULL DEFAULT 'public',
  created_by_admin  INT UNSIGNED NULL,
  created_ip        VARCHAR(45)  NULL,
  reminder_sent_at  DATETIME     NULL,
  followup_sent_at  DATETIME     NULL,
  cancelled_at      DATETIME     NULL,
  cancelled_by      VARCHAR(190) NULL,
  cancel_reason     VARCHAR(255) NULL,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_ref (ref),
  UNIQUE KEY uq_bookings_token (manage_token),
  UNIQUE KEY uq_bookings_request (request_id),
  KEY idx_bookings_space_time (space_id, status, start_utc, end_utc),
  KEY idx_bookings_start (status, start_utc),
  KEY idx_bookings_end (status, end_utc),
  KEY idx_bookings_email (email),
  KEY idx_bookings_series (series_id),
  CONSTRAINT fk_bookings_space FOREIGN KEY (space_id) REFERENCES spaces (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who may book without approval: exact addresses ("jane@acme.com") or whole domains ("@acme.com").
-- Unknown addresses that book become 'pending' rows the building manager approves or declines.
CREATE TABLE IF NOT EXISTS email_access (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pattern       VARCHAR(190) NOT NULL,
  status        ENUM('approved','pending','declined') NOT NULL DEFAULT 'approved',
  name          VARCHAR(120) NULL,
  company       VARCHAR(150) NULL,
  note          VARCHAR(255) NULL,
  token         VARCHAR(64)  NULL COMMENT 'Secret for the approve/decline link',
  requested_at  DATETIME     NULL,
  decided_at    DATETIME     NULL,
  decided_by    VARCHAR(190) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_access_pattern (pattern),
  UNIQUE KEY uq_email_access_token (token),
  KEY idx_email_access_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emails (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT UNSIGNED NULL,
  type        VARCHAR(30)  NOT NULL,
  to_email    VARCHAR(190) NOT NULL,
  to_name     VARCHAR(120) NULL,
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT   NOT NULL,
  body_text   MEDIUMTEXT   NOT NULL,
  ics         MEDIUMTEXT   NULL,
  status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NULL,
  send_after  DATETIME     NOT NULL,
  sent_at     DATETIME     NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_emails_queue (status, send_after),
  KEY idx_emails_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name   VARCHAR(80) NOT NULL,
  value  TEXT        NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email            VARCHAR(190) NOT NULL,
  name             VARCHAR(120) NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  session_version  INT UNSIGNED NOT NULL DEFAULT 1,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at    DATETIME     NULL,
  created_at       DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT UNSIGNED NULL,
  actor       VARCHAR(190) NOT NULL,
  action      VARCHAR(40)  NOT NULL,
  details     TEXT         NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_booking (booking_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket      VARCHAR(190) NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rate_bucket (bucket, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
