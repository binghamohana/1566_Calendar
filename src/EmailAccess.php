<?php
declare(strict_types=1);

namespace GPC;

/**
 * The approved-email list. Entries are exact addresses ("jane@acme.com") or whole domains
 * ("@acme.com", which also covers subdomains). Administrators are always approved.
 *
 * When approval is required and an unknown address books, its reservation is saved as
 * 'pending' and a 'pending' row here holds the secret link the building manager uses to
 * approve or decline. Approving adds the address to the list and confirms its pending bookings.
 */
final class EmailAccess
{
    /** Domains anyone can sign up for — never offer to approve these wholesale. */
    public const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        'yandex.com', 'zoho.com', 'comcast.net', 'att.net', 'bellsouth.net', 'verizon.net', 'sbcglobal.net',
    ];

    /** "Jane@Acme.com" → "jane@acme.com"; "acme.com" or "@acme.com" → "@acme.com"; invalid → null. */
    public static function normalize(string $entry): ?string
    {
        $e = strtolower(trim($entry, " \t\r\n,;<>\"'"));
        if ($e === '') {
            return null;
        }
        if ($e[0] === '@' || !str_contains($e, '@')) {
            $domain = ltrim($e, '@');
            return preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain) ? '@' . $domain : null;
        }
        return Util::isValidEmail($e) ? $e : null;
    }

    public static function domainOf(string $email): string
    {
        return strtolower(substr(strrchr($email, '@') ?: '', 1));
    }

    public static function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower(ltrim($domain, '@')), self::PUBLIC_DOMAINS, true);
    }

    public static function isApproved(string $email): bool
    {
        $email = strtolower(trim($email));
        if (Db::value('SELECT 1 FROM admins WHERE email = ? AND is_active = 1', [$email])) {
            return true;
        }
        $candidates = [$email];
        $parts = explode('.', self::domainOf($email));
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $candidates[] = '@' . implode('.', array_slice($parts, $i)); // @mail.acme.com, @acme.com
        }
        return (bool) Db::value(
            "SELECT 1 FROM email_access WHERE status = 'approved' AND pattern IN (" . Db::in($candidates) . ')',
            $candidates
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM email_access WHERE id = ?', [$id]);
    }

    public static function findByPattern(string $pattern): ?array
    {
        return Db::one('SELECT * FROM email_access WHERE pattern = ?', [strtolower($pattern)]);
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return Db::one("SELECT * FROM email_access WHERE token = ? AND status = 'pending'", [$token]);
    }

    /** Every entry, newest first, with the pending reservations of each pending address. */
    public static function listAll(): array
    {
        $rows = Db::all('SELECT * FROM email_access ORDER BY status = \'pending\' DESC, COALESCE(decided_at, created_at) DESC');
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['is_domain'] = str_starts_with($row['pattern'], '@');
            $row['bookings'] = $row['status'] === 'pending' ? self::pendingBookings($row['pattern']) : [];
            $row['domain'] = $row['is_domain'] ? substr($row['pattern'], 1) : self::domainOf($row['pattern']);
            $row['domain_ok'] = !$row['is_domain'] && !self::isPublicDomain($row['domain']);
            unset($row['token']);
        }
        return $rows;
    }

    public static function pendingCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM email_access WHERE status = 'pending'");
    }

    public static function pendingBookings(string $email): array
    {
        return array_map(
            static fn ($r) => Bookings::find((int) $r['id']),
            Db::all("SELECT id FROM bookings WHERE email = ? AND status = 'pending' ORDER BY start_utc", [$email])
        );
    }

    /**
     * Add entries to the approved list. Approving an address that has a pending request
     * also confirms its pending reservations.
     * Returns ['added' => [...patterns], 'invalid' => [...entries], 'confirmed' => [...bookings]].
     */
    public static function add(array $entries, string $by): array
    {
        $added = $invalid = $confirmed = [];
        foreach ($entries as $entry) {
            $pattern = self::normalize((string) $entry);
            if ($pattern === null) {
                if (trim((string) $entry) !== '') {
                    $invalid[] = trim((string) $entry);
                }
                continue;
            }
            $existing = self::findByPattern($pattern);
            if ($existing && $existing['status'] === 'approved') {
                continue;
            }
            if ($existing && $existing['status'] === 'pending') {
                $confirmed = array_merge($confirmed, self::approve($existing, $by)['confirmed']);
            } elseif ($existing) {
                Db::update('email_access', [
                    'status' => 'approved', 'token' => null, 'decided_at' => Time::nowDb(), 'decided_by' => $by, 'updated_at' => Time::nowDb(),
                ], 'id = :id', ['id' => $existing['id']]);
            } else {
                Db::insert('email_access', [
                    'pattern' => $pattern, 'status' => 'approved', 'decided_at' => Time::nowDb(), 'decided_by' => $by,
                    'created_at' => Time::nowDb(), 'updated_at' => Time::nowDb(),
                ]);
            }
            if (str_starts_with($pattern, '@')) {
                $confirmed = array_merge($confirmed, self::approvePendingInDomain(substr($pattern, 1), $by));
            }
            $added[] = $pattern;
        }
        if ($added) {
            Audit::log(null, $by, 'emails_approved', implode(', ', $added));
        }
        return ['added' => $added, 'invalid' => $invalid, 'confirmed' => $confirmed];
    }

    public static function remove(int $id, string $by): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new AppError('Entry not found.', 404);
        }
        if ($row['status'] === 'pending') {
            throw new AppError('This address is waiting for a decision — approve or decline it instead.');
        }
        Db::run('DELETE FROM email_access WHERE id = ?', [$id]);
        Audit::log(null, $by, 'email_removed', $row['pattern']);
    }

    /**
     * Record that an unapproved address asked to book. Returns [row, isNewRequest]:
     * only a new request emails the building manager; later bookings join the same request.
     */
    public static function request(string $email, ?string $name, ?string $company): array
    {
        $email = strtolower($email);
        $existing = self::findByPattern($email);
        if ($existing && in_array($existing['status'], ['pending', 'approved'], true)) {
            return [$existing, false];
        }
        $data = [
            'pattern' => $email, 'status' => 'pending', 'name' => $name, 'company' => $company, 'note' => null,
            'token' => Util::token(24), 'requested_at' => Time::nowDb(), 'decided_at' => null, 'decided_by' => null,
            'updated_at' => Time::nowDb(),
        ];
        if ($existing) {
            Db::update('email_access', $data, 'id = :id', ['id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = Db::insert('email_access', $data + ['created_at' => Time::nowDb()]);
        }
        return [self::find($id), true];
    }

    /**
     * Approve a pending address (optionally its whole domain). Its pending reservations are
     * confirmed; any that already ended are released instead.
     * Returns ['confirmed' => [...bookings], 'pattern' => approved pattern].
     */
    public static function approve(array $row, string $by, bool $wholeDomain = false): array
    {
        $email = $row['pattern'];
        $confirmed = Db::transaction(function () use ($row, $by, $email) {
            Db::update('email_access', [
                'status' => 'approved', 'token' => null, 'decided_at' => Time::nowDb(), 'decided_by' => $by, 'updated_at' => Time::nowDb(),
            ], 'id = :id', ['id' => $row['id']]);
            $ids = [];
            foreach (Db::all("SELECT id, end_utc FROM bookings WHERE email = ? AND status = 'pending' FOR UPDATE", [$email]) as $b) {
                if ($b['end_utc'] > Time::nowDb()) {
                    Db::run("UPDATE bookings SET status = 'confirmed', updated_at = ? WHERE id = ?", [Time::nowDb(), $b['id']]);
                    $ids[] = (int) $b['id'];
                } else {
                    Db::run(
                        "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = 'Not approved before it ended', updated_at = ? WHERE id = ?",
                        [Time::nowDb(), $by, Time::nowDb(), $b['id']]
                    );
                }
            }
            return $ids;
        });
        foreach ($confirmed as $id) {
            Audit::log($id, $by, 'approved', "Email $email approved");
        }
        Audit::log(null, $by, 'email_approved', $email);

        $confirmedBookings = array_map([Bookings::class, 'find'], $confirmed);
        $pattern = $email;
        if ($wholeDomain && str_contains($email, '@')) {
            $domain = self::domainOf($email);
            if (!self::isPublicDomain($domain)) {
                // Also confirms colleagues from the same company who are already waiting.
                $confirmedBookings = array_merge($confirmedBookings, self::add(['@' . $domain], $by)['confirmed']);
                $pattern = '@' . $domain;
            }
        }
        return ['confirmed' => $confirmedBookings, 'pattern' => $pattern];
    }

    /** Approve every pending address at a domain (or its subdomains). Returns the bookings confirmed. */
    private static function approvePendingInDomain(string $domain, string $by): array
    {
        $confirmed = [];
        $rows = Db::all(
            "SELECT * FROM email_access WHERE status = 'pending' AND (pattern LIKE ? OR pattern LIKE ?)",
            ['%@' . addcslashes($domain, '%_\\'), '%.' . addcslashes($domain, '%_\\')]
        );
        foreach ($rows as $row) {
            $confirmed = array_merge($confirmed, self::approve($row, $by)['confirmed']);
        }
        return $confirmed;
    }

    /** Decline a pending address: its pending reservations are cancelled and released. Returns them. */
    public static function decline(array $row, string $by, string $reason = ''): array
    {
        $email = $row['pattern'];
        $reason = Util::cleanText($reason, 255);
        $ids = Db::transaction(function () use ($row, $by, $email, $reason) {
            Db::update('email_access', [
                'status' => 'declined', 'token' => null, 'note' => Util::nullable($reason),
                'decided_at' => Time::nowDb(), 'decided_by' => $by, 'updated_at' => Time::nowDb(),
            ], 'id = :id', ['id' => $row['id']]);
            $ids = array_map('intval', array_column(Db::all("SELECT id FROM bookings WHERE email = ? AND status = 'pending' FOR UPDATE", [$email]), 'id'));
            if ($ids) {
                Db::run(
                    "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?, updated_at = ?
                     WHERE id IN (" . Db::in($ids) . ')',
                    array_merge([Time::nowDb(), $by, $reason !== '' ? $reason : 'Not approved', Time::nowDb()], $ids)
                );
            }
            return $ids;
        });
        foreach ($ids as $id) {
            Audit::log($id, $by, 'declined', $reason !== '' ? $reason : null);
        }
        Audit::log(null, $by, 'email_declined', $email);
        return array_map([Bookings::class, 'find'], $ids);
    }

    /** Who receives approval requests: the configured list, else the management email, else every admin. */
    public static function approverEmails(): array
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Settings::get('approval_emails'))), [Util::class, 'isValidEmail']);
        if (!$list && Util::isValidEmail((string) Settings::get('contact_email'))) {
            $list = [(string) Settings::get('contact_email')];
        }
        if (!$list) {
            $list = array_column(Db::all('SELECT email FROM admins WHERE is_active = 1'), 'email');
        }
        return array_values(array_unique($list));
    }
}
