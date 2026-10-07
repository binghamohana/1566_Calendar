<?php
declare(strict_types=1);

namespace GPC;

/**
 * Brings an existing database up to date. Called by bin/install.php after schema.sql
 * (which only creates missing tables). Every step checks first, so re-running is safe.
 */
final class Migrations
{
    /** @return string[] descriptions of the steps that were applied */
    public static function run(): array
    {
        $done = [];
        $db = (string) App::config('db.name');

        // 1. Bookings can be 'pending' (waiting for approval of a new email address).
        $type = (string) Db::value(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'status'",
            [$db]
        );
        if ($type !== '' && !str_contains($type, "'pending'")) {
            Db::pdo()->exec("ALTER TABLE bookings MODIFY status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed'");
            $done[] = 'bookings can now be pending approval';
        }

        // 2. The old "allowed email domains" setting becomes entries in the approved list.
        $old = Db::value("SELECT value FROM settings WHERE name = 'allowed_email_domains'");
        if ($old !== null) {
            foreach (preg_split('/[\s,;]+/', strtolower((string) $old)) as $domain) {
                $domain = ltrim(trim($domain), '@');
                if ($domain !== '') {
                    EmailAccess::add(['@' . $domain], 'migration');
                }
            }
            Db::run("DELETE FROM settings WHERE name = 'allowed_email_domains'");
            $done[] = 'moved allowed email domains into the approved list';
        }

        return $done;
    }
}
