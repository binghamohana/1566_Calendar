<?php
declare(strict_types=1);

namespace GPC;

/**
 * Admin-editable settings, stored in the `settings` table.
 * Defaults live here, so adding a new setting needs no database migration.
 */
final class Settings
{
    public const CLEANUP_DEFAULT = "As a courtesy to the next person using the space, please leave the room the way you found it. "
        . "Please remove personal items and trash, straighten the room, and wipe down/erase the dry-erase boards if they were used.\n\n"
        . "Thank you for helping us keep Grove Park Collective beautiful and welcoming for everyone.";

    /** name => [type, default, extra] */
    private const SCHEMA = [
        // General
        'org_name'               => ['string', 'Grove Park Collective'],
        'tagline'                => ['string', 'Reserve a Space'],
        'intro'                  => ['text', 'Shared rooms for the Grove Park Collective community. Pick a space, choose a time, and you’re set — no account needed.'],
        'contact_email'          => ['email', ''],
        'contact_phone'          => ['string', ''],
        'timezone'               => ['timezone', 'America/New_York'],

        // Public calendar privacy
        'public_show_name'       => ['enum', 'first', ['none', 'first', 'full']],
        'public_show_company'    => ['bool', false],
        'public_show_title'      => ['bool', false],

        // Booking rules (public bookings; admins can override)
        'time_increment'         => ['enum', '15', ['5', '10', '15', '30', '60']],
        'min_duration_minutes'   => ['int', 15, [5, 1440]],
        'max_duration_minutes'   => ['int', 720, [15, 1440]],
        'max_days_ahead'         => ['int', 120, [1, 730]],
        'min_notice_minutes'     => ['int', 0, [0, 10080]],
        'max_upcoming_per_email' => ['int', 25, [0, 1000]],   // 0 = unlimited
        'allowed_email_domains'  => ['string', ''],            // e.g. "acme.com, example.org"; blank = anyone
        'rate_limit_per_hour'    => ['int', 30, [1, 1000]],    // new bookings per IP address per hour (tenants may share one office IP)

        // Emails
        'reminder_enabled'       => ['bool', true],
        'reminder_minutes'       => ['int', 60, [5, 1440]],
        'followup_enabled'       => ['bool', true],
        'followup_offset_minutes' => ['int', 0, [-120, 240]],  // relative to the reservation end time
        'building_instructions'  => ['text', ''],
        'cleanup_message'        => ['text', self::CLEANUP_DEFAULT],
        'admin_notify_emails'    => ['string', ''],
        'admin_notify_new'       => ['bool', false],
        'admin_notify_cancel'    => ['bool', false],

        // Internal
        'ics_feed_key'           => ['internal', ''],
        'cron_last_run'          => ['internal', ''],
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $values = [];
            foreach (self::SCHEMA as $name => $def) {
                $values[$name] = $def[1];
            }
            foreach (Db::all('SELECT name, value FROM settings') as $row) {
                if (isset(self::SCHEMA[$row['name']])) {
                    $values[$row['name']] = self::cast($row['name'], $row['value']);
                }
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function get(string $name)
    {
        return self::all()[$name] ?? null;
    }

    /** Settings an admin may edit (everything except internal values). */
    public static function editable(): array
    {
        return array_filter(
            self::all(),
            static fn ($name) => self::SCHEMA[$name][0] !== 'internal',
            ARRAY_FILTER_USE_KEY
        );
    }

    public static function set(string $name, $value): void
    {
        Db::run(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value]
        );
        self::$cache = null;
    }

    /** Validate and save a batch of admin edits. Unknown keys are ignored. */
    public static function saveMany(array $input): void
    {
        $clean = [];
        foreach ($input as $name => $raw) {
            if (!isset(self::SCHEMA[$name]) || self::SCHEMA[$name][0] === 'internal') {
                continue;
            }
            $clean[$name] = self::validate($name, $raw);
        }
        foreach ($clean as $name => $value) {
            self::set($name, $value);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function validate(string $name, $raw)
    {
        [$type, , $extra] = self::SCHEMA[$name] + [2 => null];
        $label = ucfirst(str_replace('_', ' ', $name));
        switch ($type) {
            case 'bool':
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                if (!is_numeric($raw)) {
                    throw new AppError("$label must be a number.", 400, $name);
                }
                $int = (int) $raw;
                if ($extra && ($int < $extra[0] || $int > $extra[1])) {
                    throw new AppError("$label must be between {$extra[0]} and {$extra[1]}.", 400, $name);
                }
                return $int;
            case 'enum':
                $raw = (string) $raw;
                if (!in_array($raw, $extra, true)) {
                    throw new AppError("$label has an invalid value.", 400, $name);
                }
                return $raw;
            case 'email':
                $raw = trim((string) $raw);
                if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    throw new AppError("$label must be a valid email address.", 400, $name);
                }
                return $raw;
            case 'timezone':
                if (!in_array($raw, \DateTimeZone::listIdentifiers(), true)) {
                    throw new AppError('Unknown time zone.', 400, $name);
                }
                return $raw;
            case 'text':
                return Util::cleanText((string) $raw, 5000, true);
            default:
                return Util::cleanText((string) $raw, 500);
        }
    }

    private static function cast(string $name, ?string $value)
    {
        $type = self::SCHEMA[$name][0];
        return match ($type) {
            'bool' => $value === '1',
            'int'  => (int) $value,
            default => (string) $value,
        };
    }
}
