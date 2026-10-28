<?php
declare(strict_types=1);

namespace GPC;

use DateTimeImmutable;

/**
 * Reservation rules and storage. Every create/change goes through lockSpaces() +
 * findConflict() inside one transaction, so the database — not the browser — guarantees
 * that a space can never hold two overlapping bookings. Pending bookings (a new email address
 * waiting for approval) hold their time just like confirmed ones.
 */
final class Bookings
{
    /** Walk-ups: a reservation may start up to this many minutes in the past. */
    public const GRACE_MINUTES = 15;
    private const MAX_OCCURRENCES = 500;

    // ------------------------------------------------------------------ queries

    public static function find(int $id): ?array
    {
        return self::cast(Db::one('SELECT * FROM bookings WHERE id = ?', [$id]));
    }

    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        return self::cast(Db::one('SELECT * FROM bookings WHERE manage_token = ?', [$token]));
    }

    public static function findByRequestId(string $requestId): ?array
    {
        $requestId = self::cleanRequestId($requestId);
        return $requestId ? self::cast(Db::one('SELECT * FROM bookings WHERE request_id = ?', [$requestId])) : null;
    }

    /** Statuses that occupy a time slot. */
    public const ACTIVE = "('confirmed','pending')";

    /** Confirmed and pending bookings overlapping the given local dates (inclusive). */
    public static function inRange(string $fromDate, string $toDate, ?int $spaceId = null): array
    {
        [$from, $to] = Time::dayBoundsUtc($fromDate, $toDate);
        $sql = 'SELECT * FROM bookings WHERE status IN ' . self::ACTIVE . ' AND start_utc < ? AND end_utc > ?';
        $params = [$to, $from];
        if ($spaceId) {
            $sql .= ' AND space_id = ?';
            $params[] = $spaceId;
        }
        return array_map([self::class, 'cast'], Db::all($sql . ' ORDER BY start_utc', $params));
    }

    private static function cast(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['id', 'space_id', 'is_private', 'notify', 'created_by_admin'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row + Time::localParts($row['start_utc'], $row['end_utc']);
    }

    // ------------------------------------------------------------------ views

    /** Safe for anyone: no email, no notes, name only as allowed by the privacy settings. */
    public static function publicView(array $b): array
    {
        return [
            'ref'       => $b['ref'],
            'space_id'  => $b['space_id'],
            'kind'      => $b['kind'],
            'date'      => $b['date'],
            'start'     => $b['start'],
            'end'       => $b['end'],
            'start_min' => $b['start_min'],
            'end_min'   => $b['end_min'],
            'label'     => self::publicLabel($b),
            'pending'   => $b['status'] === 'pending',
        ];
    }

    public static function publicLabel(array $b): string
    {
        if ($b['kind'] === 'block') {
            return $b['title'] ? 'Unavailable · ' . $b['title'] : 'Unavailable';
        }
        if ($b['is_private']) {
            return 'Reserved';
        }
        $show = Settings::get('public_show_name');
        $who = $show === 'full' ? (string) $b['name'] : ($show === 'first' ? Util::firstName($b['name']) : '');
        if (Settings::get('public_show_company') && $b['company']) {
            $who = $who !== '' ? "$who ({$b['company']})" : $b['company'];
        }
        $base = (Settings::get('public_show_title') && $b['title']) ? $b['title'] : 'Reserved';
        return $who !== '' ? "$base — $who" : $base;
    }

    /**
     * What the person holding the manage link sees: the date and time of this booking with every
     * room booked for it, plus (for a repeating or multi-room reservation) all of its dates.
     */
    public static function ownerView(array $b): array
    {
        $group = self::groupOf($b);
        $occasion = array_values(array_filter($group, static fn ($r) => $r['start_utc'] === $b['start_utc']));
        $active = array_values(array_filter($occasion, [self::class, 'isActive']));
        $shown = $active ?: $occasion;
        $spaces = array_map(static fn ($s) => ['id' => $s['id'], 'name' => $s['name']], self::spacesOf($shown));
        $now = Time::now();
        $start = new DateTimeImmutable($b['start_utc'], Time::utc());
        $end = new DateTimeImmutable($b['end_utc'], Time::utc());
        $live = $active && $b['kind'] === 'reservation';

        $mine = [];
        foreach ($group as $r) {
            if (self::isActive($r) && $r['end_utc'] > Time::nowDb()) {
                $mine[$r['ref']] = ['t' => $r['manage_token'], 'd' => $r['date']];
            }
        }
        return [
            'ref'          => $b['ref'],
            'refs'         => array_column($shown, 'ref'),
            'status'       => self::statusOf($shown),
            'space_id'     => $spaces[0]['id'] ?? $b['space_id'],
            'space_ids'    => array_column($spaces, 'id'),
            'space_name'   => implode(' + ', array_column($spaces, 'name')),
            'spaces'       => $spaces,
            'date'         => $b['date'],
            'start'        => $b['start'],
            'end'          => $b['end'],
            'start_min'    => $b['start_min'],
            'end_min'      => $b['end_min'],
            'name'         => $b['name'],
            'email'        => $b['email'],
            'title'        => $b['title'],
            'company'      => $b['company'],
            'notes'        => $b['notes'],
            'is_private'   => (bool) $b['is_private'],
            'public_label' => self::publicLabel($b),
            'can_modify'   => $live && $start > $now->modify('-' . self::GRACE_MINUTES . ' minutes'),
            'can_cancel'   => $live && $end > $now,
            'is_past'      => $end <= $now,
            'manage_token' => $b['manage_token'],
            'occasions'    => self::occasions($group, $b['start_utc']),
            'mine'         => $mine,
        ];
    }

    public static function adminView(array $b): array
    {
        $view = $b;
        unset($view['request_id']);
        $view['is_private'] = (bool) $b['is_private'];
        $view['notify'] = (bool) $b['notify'];
        $view['public_label'] = self::publicLabel($b);
        return $view;
    }

    // ------------------------------------------------------------------ groups

    /**
     * Everything booked together with $b, whatever its status: one row per room per date, linked
     * by series_id. A plain reservation is a group of one. Only rows with the same email belong,
     * so a manage link can never show someone else's booking.
     */
    public static function groupOf(array $b): array
    {
        if (!$b['series_id']) {
            return [$b];
        }
        return array_map([self::class, 'cast'], Db::all(
            'SELECT * FROM bookings WHERE series_id = ? AND email <=> ? ORDER BY start_utc, id',
            [$b['series_id'], $b['email']]
        ));
    }

    /** The rows of $b's group at the same date and time (one per room), whatever their status. */
    public static function occasionOf(array $b): array
    {
        return array_values(array_filter(self::groupOf($b), static fn ($r) => $r['start_utc'] === $b['start_utc']));
    }

    public static function isActive(array $b): bool
    {
        return $b['status'] === 'confirmed' || $b['status'] === 'pending';
    }

    /** Rows by date and time, earliest first: [start_utc => rows]. */
    public static function byOccasion(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['start_utc']][] = $r;
        }
        ksort($out);
        return $out;
    }

    /** The spaces these rows use, in booking-page order. */
    public static function spacesOf(array $rows): array
    {
        $spaces = [];
        foreach ($rows as $r) {
            $spaces[$r['space_id']] ??= Spaces::find($r['space_id']) ?? ['id' => $r['space_id'], 'name' => 'Space', 'sort_order' => 999];
        }
        usort($spaces, static fn ($a, $b) => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]);
        return array_values($spaces);
    }

    /** One status for rows booked together: pending wins over confirmed; cancelled only if all are. */
    public static function statusOf(array $rows): string
    {
        $statuses = array_column($rows, 'status');
        return in_array('pending', $statuses, true) ? 'pending' : (in_array('confirmed', $statuses, true) ? 'confirmed' : 'cancelled');
    }

    /** Each date of a group for the manage page, with the link to manage that date. */
    private static function occasions(array $group, string $currentStartUtc): array
    {
        $byOccasion = self::byOccasion($group);
        $bookedDates = [];
        foreach ($byOccasion as $rows) {
            if (array_filter($rows, [self::class, 'isActive'])) {
                $bookedDates[$rows[0]['date']] = true;
            }
        }
        $out = [];
        foreach ($byOccasion as $startUtc => $rows) {
            $active = array_values(array_filter($rows, [self::class, 'isActive']));
            if (!$active && isset($bookedDates[$rows[0]['date']]) && $startUtc !== $currentStartUtc) {
                continue; // leftovers of a change on a date that is still booked
            }
            $shown = $active ?: $rows;
            $out[] = [
                'date'       => $rows[0]['date'],
                'start_min'  => $rows[0]['start_min'],
                'end_min'    => $rows[0]['end_min'],
                'status'     => self::statusOf($shown),
                'space_name' => implode(' + ', array_column(self::spacesOf($shown), 'name')),
                'token'      => $shown[0]['manage_token'],
                'is_past'    => $rows[0]['end_utc'] <= Time::nowDb(),
                'current'    => $startUtc === $currentStartUtc,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ public (tenant) actions

    /**
     * Create a reservation from the public booking page: one or more rooms, on one date or
     * weekly until a chosen date. It is all or nothing for each date: a date is only booked if
     * every chosen room is free. Bookings are 'pending' while the email address needs the
     * building manager's approval.
     * Returns [first booking, wasDuplicateSubmit, newApprovalRequest|null].
     */
    public static function createPublic(array $in, string $ip): array
    {
        $requestId = self::cleanRequestId((string) ($in['request_id'] ?? ''));
        if ($requestId && ($existing = self::findByRequestId($requestId))) {
            return [$existing, true, null];
        }

        if (!RateLimit::hit("attempt:$ip", 120, 3600)) {
            throw new AppError('Too many attempts from this network. Please wait a little while and try again.', 429, null, 'rate_limited');
        }

        $spaces = self::parseSpaces($in);
        $date = self::parseDate($in);
        [$start, $end] = self::parseTimes($in);
        $person = self::parsePerson($in, true);
        $dates = self::publicDates($date, $in);
        foreach ($dates as $d) {
            foreach ($spaces as $space) {
                self::checkRules($space, $d, $start, $end);
            }
        }

        // New email addresses wait for the building manager's approval (if turned on).
        $needsApproval = Settings::get('require_approval') && !EmailAccess::isApproved($person['email']);
        if ($needsApproval) {
            // A reservation booked in one go (several rooms or dates) is one request.
            $waiting = (int) Db::value(
                "SELECT COUNT(DISTINCT COALESCE(series_id, CONCAT('#', id))) FROM bookings WHERE email = ? AND status = 'pending' AND end_utc > ?",
                [$person['email'], Time::nowDb()]
            );
            if ($waiting >= 3) {
                throw new AppError('You already have 3 requests waiting for approval. Once building management approves your email address you can book as much as you need.', 400, 'email');
            }
            if (RateLimit::count("pending:$ip", 86400) >= 6) {
                throw new AppError('Too many new email addresses from this network today. Please contact building management.', 429, null, 'rate_limited');
            }
        }
        $maxUpcoming = (int) Settings::get('max_upcoming_per_email');
        if ($maxUpcoming > 0) {
            // Each date counts once, however many rooms it uses.
            $upcoming = (int) Db::value(
                "SELECT COUNT(DISTINCT COALESCE(series_id, CONCAT('#', id)), start_utc) FROM bookings
                  WHERE email = ? AND status IN " . self::ACTIVE . " AND kind = 'reservation' AND end_utc > ?",
                [$person['email'], Time::nowDb()]
            );
            if ($upcoming >= $maxUpcoming) {
                throw new AppError("You already have $upcoming upcoming reservations, which is the current limit. Please cancel one you no longer need, or contact management.", 400, 'email');
            }
            if ($upcoming + count($dates) > $maxUpcoming) {
                throw new AppError("You have $upcoming upcoming reservations, and the limit is $maxUpcoming. Please choose an earlier end date so this repeat adds no more than " . ($maxUpcoming - $upcoming) . ' dates.', 400, 'repeat_until');
            }
        }
        $perHour = (int) Settings::get('rate_limit_per_hour');
        if (RateLimit::count("book:$ip", 3600) >= $perHour) {
            throw new AppError('Too many reservations from this network in the last hour. Please try again later or contact management.', 429, null, 'rate_limited');
        }
        $skipConflicts = filter_var($in['skip_conflicts'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $result = Db::transaction(function () use ($spaces, $dates, $start, $end, $person, $requestId, $ip, $needsApproval, $skipConflicts) {
            self::lockSpaces(array_column($spaces, 'id'));
            if ($requestId && ($existing = self::findByRequestId($requestId))) {
                return [$existing, true, []]; // the same form was submitted twice at once
            }
            [$free, $conflicts] = self::freeDates($spaces, $dates, $start, $end);
            if ($conflicts) {
                if (count($dates) === 1) {
                    $c = $conflicts[0];
                    $what = $c['kind'] === 'block' ? 'is unavailable' : 'is already reserved';
                    throw AppError::conflict(
                        "Sorry — {$c['space']} $what from " . Time::fmtRange($c['start_min'], $c['end_min']) . '. Please choose another time.',
                        ['conflict' => ['date' => $c['date'], 'start' => $c['start'], 'end' => $c['end'], 'space_id' => $c['space_id']]]
                    );
                }
                $taken = count($dates) - count($free);
                if (!$free) {
                    throw AppError::conflict('All of these dates are taken, so nothing was booked. Please choose another time.', ['conflicts' => $conflicts, 'creatable' => 0, 'total' => count($dates)]);
                }
                if (!$skipConflicts) {
                    throw AppError::conflict(
                        $taken === 1 ? '1 of these dates is taken.' : "$taken of these dates are taken.",
                        ['conflicts' => $conflicts, 'creatable' => count($free), 'total' => count($dates)]
                    );
                }
            }
            $seriesId = count($free) * count($spaces) > 1 ? Util::token(8) : null;
            $ids = [];
            foreach ($free as [$startUtc, $endUtc]) {
                foreach ($spaces as $space) {
                    $ids[] = self::insert($person + [
                        'space_id'   => $space['id'],
                        'kind'       => 'reservation',
                        'status'     => $needsApproval ? 'pending' : 'confirmed',
                        'start_utc'  => $startUtc,
                        'end_utc'    => $endUtc,
                        'series_id'  => $seriesId,
                        'request_id' => $ids ? null : $requestId,
                        'source'     => 'public',
                        'created_ip' => $ip,
                    ]);
                }
            }
            return [self::find($ids[0]), false, $ids];
        });

        $newRequest = null;
        if (!$result[1]) {
            RateLimit::hit("book:$ip", PHP_INT_MAX, 3600);
            foreach ($result[2] as $id) {
                Audit::log($id, $person['email'], $needsApproval ? 'requested' : 'created', self::summary(self::find($id)));
            }
            if ($needsApproval) {
                [$access, $isNew] = EmailAccess::request($person['email'], $person['name'], $person['company']);
                if ($isNew) {
                    RateLimit::hit("pending:$ip", PHP_INT_MAX, 86400);
                    $newRequest = $access;
                }
            }
        }
        // [first booking, duplicate submit?, new approval request (email_access row) or null]
        return [$result[0], $result[1], $newRequest];
    }

    /**
     * Change one date of a reservation via its manage link: the date and time, and which rooms it
     * uses (all of its rooms move together). Name, title, company, notes and privacy apply to
     * every date booked together. Returns [booking, timeOrRoomsChanged].
     */
    public static function updateByOwner(array $booking, array $in): array
    {
        $view = self::ownerView($booking);
        if (!$view['can_modify']) {
            throw new AppError(
                $view['status'] === 'cancelled'
                    ? 'This reservation was cancelled, so it can’t be changed. You’re welcome to make a new one.'
                    : 'This reservation has already started, so it can’t be changed online. Please contact management for help.',
                400
            );
        }
        $group = self::groupOf($booking);
        $occasion = array_values(array_filter($group, static fn ($r) => $r['start_utc'] === $booking['start_utc']));
        $active = array_values(array_filter($occasion, [self::class, 'isActive']));
        $spaces = self::parseSpaces($in);
        $date = self::parseDate($in);
        [$start, $end] = self::parseTimes($in);
        $startUtc = Time::localToUtc($date, $start);
        $endUtc = Time::localToUtc($date, $end);
        $person = self::parsePerson($in + ['email' => $booking['email']], true);
        unset($person['email']); // the email address stays tied to the booking

        $oldIds = array_column($active, 'space_id');
        $newIds = array_column($spaces, 'id');
        sort($oldIds);
        sort($newIds);
        $timeMoved = $startUtc !== $active[0]['start_utc'] || $endUtc !== $active[0]['end_utc'];
        $moved = $timeMoved || $oldIds !== $newIds;
        if ($moved) {
            foreach ($spaces as $space) {
                if ($timeMoved || !in_array($space['id'], $oldIds, true)) {
                    self::checkRules($space, $date, $start, $end);
                }
            }
        }
        $status = self::statusOf($active);

        $added = Db::transaction(function () use ($booking, $group, $occasion, $spaces, $newIds, $oldIds, $startUtc, $endUtc, $timeMoved, $person, $status) {
            self::lockSpaces(array_merge($oldIds, $newIds));
            $now = Time::nowDb();
            $seriesId = $booking['series_id'];
            if (!$seriesId && count($newIds) > 1) {
                $seriesId = Util::token(8);
                Db::update('bookings', ['series_id' => $seriesId], 'id = :id', ['id' => $booking['id']]);
            }
            foreach ($group as $r) {
                Db::update('bookings', $person + ['updated_at' => $now], 'id = :id', ['id' => $r['id']]);
            }

            $added = [];
            foreach ($spaces as $space) {
                // Reuse this room's row on this date if there is one (even a removed one), so links keep working.
                $rows = array_values(array_filter($occasion, static fn ($r) => $r['space_id'] === $space['id']));
                usort($rows, static fn ($a, $b) => (int) self::isActive($b) <=> (int) self::isActive($a));
                $row = $rows[0] ?? null;
                self::assertNoConflict($space, $startUtc, $endUtc, $row['id'] ?? null);
                if (!$row) {
                    $added[] = self::insert($person + [
                        'email'     => $booking['email'],
                        'notify'    => $booking['notify'],
                        'space_id'  => $space['id'],
                        'kind'      => 'reservation',
                        'status'    => $status,
                        'start_utc' => $startUtc,
                        'end_utc'   => $endUtc,
                        'series_id' => $seriesId,
                        'source'    => 'public',
                    ]);
                    continue;
                }
                $data = ['start_utc' => $startUtc, 'end_utc' => $endUtc, 'series_id' => $seriesId, 'updated_at' => $now];
                if (!self::isActive($row)) {
                    $data += ['status' => $status, 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null];
                    $added[] = $row['id'];
                }
                if ($timeMoved || !self::isActive($row)) {
                    $data += ['reminder_sent_at' => null, 'followup_sent_at' => null];
                }
                Db::update('bookings', $data, 'id = :id', ['id' => $row['id']]);
            }
            // Rooms no longer wanted are released; they stay with this date so the reservation is one piece.
            foreach ($occasion as $r) {
                if (in_array($r['space_id'], $newIds, true)) {
                    continue;
                }
                if (self::isActive($r)) {
                    self::markCancelled([$r['id']], (string) $booking['email'], 'Room removed from the reservation');
                }
                if ($timeMoved) {
                    Db::update('bookings', ['start_utc' => $startUtc, 'end_utc' => $endUtc, 'series_id' => $seriesId], 'id = :id', ['id' => $r['id']]);
                }
            }
            return $added;
        });

        $updated = self::find($booking['id']);
        Audit::log($booking['id'], (string) $booking['email'], 'updated', self::changes($booking, $updated)
            . ($oldIds !== $newIds ? '; rooms: ' . self::roomList($oldIds) . ' → ' . self::roomList($newIds) : ''));
        foreach ($added as $id) {
            if ($id !== $booking['id']) {
                Audit::log($id, (string) $booking['email'], 'created', 'Room added to reservation ' . $booking['ref']);
            }
        }
        return [$updated, $moved];
    }

    /**
     * Cancel via the manage link: this date (every room on it), or with $scope 'all' every date
     * of the reservation that hasn't ended. Returns [booking, cancelled bookings].
     */
    public static function cancelByOwner(array $booking, string $scope = 'date'): array
    {
        $rows = $scope === 'all' ? self::groupOf($booking) : self::occasionOf($booking);
        $ids = [];
        foreach ($rows as $r) {
            if (self::isActive($r) && $r['kind'] === 'reservation' && $r['end_utc'] > Time::nowDb()) {
                $ids[] = $r['id'];
            }
        }
        if (!$ids) {
            $ended = array_filter($rows, static fn ($r) => self::isActive($r) && $r['end_utc'] <= Time::nowDb());
            throw new AppError($ended ? 'This reservation has already ended.' : 'This reservation is already cancelled.', 400);
        }
        self::markCancelled($ids, (string) $booking['email'], null);
        foreach ($ids as $id) {
            Audit::log($id, (string) $booking['email'], 'cancelled', $scope === 'all' ? 'Cancelled all remaining dates' : null);
        }
        return [self::find($booking['id']), array_map([self::class, 'find'], $ids)];
    }

    // ------------------------------------------------------------------ admin actions

    /**
     * Create reservations or blocks, optionally across several spaces and repeating.
     * Admins skip the public booking rules but can never double-book.
     * Returns ['bookings' => [...], 'skipped' => [conflicts...], 'series_id' => ?string].
     */
    public static function adminCreate(array $in, array $admin): array
    {
        $kind = ($in['kind'] ?? 'reservation') === 'block' ? 'block' : 'reservation';
        $spaceIds = array_values(array_unique(array_map('intval', (array) ($in['space_ids'] ?? [$in['space_id'] ?? 0]))));
        $spaces = [];
        foreach ($spaceIds as $id) {
            $space = $id ? Spaces::find($id) : null;
            if (!$space) {
                throw new AppError('Please choose at least one space.', 400, 'space_ids');
            }
            $spaces[] = $space;
        }
        if (!$spaces) {
            throw new AppError('Please choose at least one space.', 400, 'space_ids');
        }

        $date = (string) ($in['date'] ?? '');
        if (!Time::isDate($date)) {
            throw new AppError('Please choose a valid date.', 400, 'date');
        }
        [$start, $end] = self::parseTimes($in);
        $dates = self::occurrences($date, (string) ($in['repeat'] ?? 'none'), (string) ($in['repeat_until'] ?? ''));
        $person = $kind === 'reservation' ? self::parsePerson($in, false) : [
            'title'      => Util::nullable(Util::cleanText($in['title'] ?? '', 150)),
            'notes'      => Util::nullable(Util::cleanText($in['notes'] ?? '', 2000, true)),
            'name'       => null, 'email' => null, 'company' => null, 'is_private' => 0,
        ];
        if ($kind === 'reservation' && !$person['name']) {
            throw new AppError('Please enter who the reservation is for.', 400, 'name');
        }
        $notify = $kind === 'reservation' && $person['email'] && filter_var($in['notify'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $skipConflicts = filter_var($in['skip_conflicts'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $seriesId = (count($dates) > 1 || count($spaces) > 1) ? Util::token(8) : null;

        $result = Db::transaction(function () use ($spaces, $dates, $start, $end, $kind, $person, $notify, $seriesId, $skipConflicts, $admin) {
            self::lockSpaces(array_column($spaces, 'id'));
            $toCreate = [];
            $conflicts = [];
            foreach ($dates as $d) {
                $startUtc = Time::localToUtc($d, $start);
                $endUtc = Time::localToUtc($d, $end);
                $free = [];
                $clash = false;
                foreach ($spaces as $space) {
                    $conflict = self::findConflict($space['id'], $startUtc, $endUtc);
                    if ($conflict) {
                        $clash = true;
                        $conflicts[] = [
                            'space'  => $space['name'],
                            'date'   => $d,
                            'start'  => $conflict['start'],
                            'end'    => $conflict['end'],
                            'label'  => $conflict['kind'] === 'block' ? ($conflict['title'] ?: 'Block') : trim(($conflict['name'] ?? '') . ' ' . ($conflict['title'] ? '— ' . $conflict['title'] : '')),
                        ];
                    } else {
                        $free[] = [$space, $startUtc, $endUtc];
                    }
                }
                // A block closes whatever is free; a reservation needs all of its rooms, or that date is skipped.
                if ($kind === 'block' || !$clash) {
                    array_push($toCreate, ...$free);
                }
            }
            if ($conflicts && !$skipConflicts) {
                $n = count($conflicts);
                throw new AppError(
                    $n === 1 ? 'That time overlaps an existing booking.' : "$n of these times overlap existing bookings.",
                    409, null, 'conflict', ['conflicts' => $conflicts, 'creatable' => count($toCreate)]
                );
            }
            if (!$toCreate) {
                throw new AppError('Every requested time overlaps an existing booking, so nothing was created.', 409, null, 'conflict', ['conflicts' => $conflicts, 'creatable' => 0]);
            }
            $ids = [];
            foreach ($toCreate as [$space, $startUtc, $endUtc]) {
                $ids[] = self::insert($person + [
                    'space_id'         => $space['id'],
                    'kind'             => $kind,
                    'start_utc'        => $startUtc,
                    'end_utc'          => $endUtc,
                    'notify'           => $notify ? 1 : 0,
                    'series_id'        => $seriesId,
                    'source'           => 'admin',
                    'created_by_admin' => $admin['id'],
                    'created_ip'       => PHP_SAPI === 'cli' ? null : Http::ip(),
                ]);
            }
            return ['ids' => $ids, 'skipped' => $conflicts];
        });

        $bookings = array_map([self::class, 'find'], $result['ids']);
        foreach ($bookings as $b) {
            Audit::log($b['id'], 'admin:' . $admin['email'], 'created', self::summary($b));
        }
        return ['bookings' => $bookings, 'skipped' => $result['skipped'], 'series_id' => $seriesId];
    }

    /** Edit a single booking (one occurrence of a series). Returns [booking, timeOrSpaceChanged]. */
    public static function adminUpdate(int $id, array $in, array $admin): array
    {
        $booking = self::find($id);
        if (!$booking) {
            throw new AppError('Booking not found.', 404);
        }
        $space = Spaces::find((int) ($in['space_id'] ?? 0));
        if (!$space) {
            throw new AppError('Please choose a space.', 400, 'space_id');
        }
        $date = (string) ($in['date'] ?? '');
        if (!Time::isDate($date)) {
            throw new AppError('Please choose a valid date.', 400, 'date');
        }
        [$start, $end] = self::parseTimes($in);
        $startUtc = Time::localToUtc($date, $start);
        $endUtc = Time::localToUtc($date, $end);
        $person = $booking['kind'] === 'reservation' ? self::parsePerson($in, false) : [
            'title' => Util::nullable(Util::cleanText($in['title'] ?? '', 150)),
            'notes' => Util::nullable(Util::cleanText($in['notes'] ?? '', 2000, true)),
        ];
        if ($booking['kind'] === 'reservation' && !$person['name']) {
            throw new AppError('Please enter who the reservation is for.', 400, 'name');
        }
        $moved = $space['id'] !== $booking['space_id'] || $startUtc !== $booking['start_utc'] || $endUtc !== $booking['end_utc'];

        $updated = Db::transaction(function () use ($booking, $space, $startUtc, $endUtc, $person, $moved, $in) {
            $data = $person + ['updated_at' => Time::nowDb()];
            if ($booking['kind'] === 'reservation') {
                $data['notify'] = filter_var($in['notify'] ?? $booking['notify'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            if ($moved) {
                self::lockSpaces([$space['id']]);
                if ($booking['status'] !== 'cancelled') {
                    self::assertNoConflict($space, $startUtc, $endUtc, $booking['id']);
                }
                $data += [
                    'space_id' => $space['id'], 'start_utc' => $startUtc, 'end_utc' => $endUtc,
                    'reminder_sent_at' => null, 'followup_sent_at' => null,
                ];
            }
            Db::update('bookings', $data, 'id = :id', ['id' => $booking['id']]);
            return self::find($booking['id']);
        });

        Audit::log($id, 'admin:' . $admin['email'], 'updated', self::changes($booking, $updated));
        return [$updated, $moved];
    }

    /** Cancel one booking, or it and every later occurrence of its series. Returns cancelled bookings. */
    public static function adminCancel(int $id, string $scope, string $reason, array $admin): array
    {
        $ids = self::scopeIds($id, $scope, true);
        $reason = Util::nullable(Util::cleanText($reason, 255));
        self::markCancelled($ids, 'admin:' . $admin['email'], $reason);
        foreach ($ids as $bid) {
            Audit::log($bid, 'admin:' . $admin['email'], 'cancelled', $reason);
        }
        return array_map([self::class, 'find'], $ids);
    }

    /** Permanently remove bookings (for mistakes and blocks). Returns the number deleted. */
    public static function adminDelete(int $id, string $scope, array $admin): int
    {
        $ids = self::scopeIds($id, $scope, false);
        foreach ($ids as $bid) {
            $b = self::find($bid);
            Audit::log(null, 'admin:' . $admin['email'], 'deleted', self::summary($b));
        }
        Db::run('UPDATE emails SET booking_id = NULL WHERE booking_id IN (' . Db::in($ids) . ')', $ids);
        Db::run('DELETE FROM audit_log WHERE booking_id IN (' . Db::in($ids) . ')', $ids);
        Db::run('DELETE FROM bookings WHERE id IN (' . Db::in($ids) . ')', $ids);
        return count($ids);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Serialize all writers for these spaces: the row lock is held until commit, so a second
     * request for the same space waits here and then sees the first request's booking.
     */
    private static function lockSpaces(array $spaceIds): void
    {
        $spaceIds = array_values(array_unique(array_map('intval', $spaceIds)));
        sort($spaceIds); // consistent order avoids deadlocks
        Db::all('SELECT id FROM spaces WHERE id IN (' . Db::in($spaceIds) . ') ORDER BY id FOR UPDATE', $spaceIds);
    }

    public static function findConflict(int $spaceId, string $startUtc, string $endUtc, ?int $ignoreId = null): ?array
    {
        return self::cast(Db::one(
            'SELECT * FROM bookings WHERE space_id = ? AND status IN ' . self::ACTIVE . " AND start_utc < ? AND end_utc > ? AND id <> ?
             ORDER BY start_utc LIMIT 1",
            [$spaceId, $endUtc, $startUtc, $ignoreId ?? 0]
        ));
    }

    private static function assertNoConflict(array $space, string $startUtc, string $endUtc, ?int $ignoreId = null): void
    {
        $conflict = self::findConflict($space['id'], $startUtc, $endUtc, $ignoreId);
        if ($conflict) {
            $when = Time::fmtRange($conflict['start_min'], $conflict['end_min']);
            $what = $conflict['kind'] === 'block' ? 'is unavailable' : 'is already reserved';
            throw AppError::conflict(
                "Sorry — {$space['name']} $what from $when. Please choose another time.",
                ['conflict' => ['date' => $conflict['date'], 'start' => $conflict['start'], 'end' => $conflict['end']]]
            );
        }
    }

    private static function insert(array $data): int
    {
        $now = Time::nowDb();
        $data += ['is_private' => 0, 'notify' => 1, 'status' => 'confirmed'];
        $data['manage_token'] = $data['kind'] === 'reservation' ? Util::token(24) : null;
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        do {
            $data['ref'] = Util::ref();
        } while (Db::value('SELECT 1 FROM bookings WHERE ref = ?', [$data['ref']]));
        return Db::insert('bookings', $data);
    }

    private static function markCancelled(array $ids, string $by, ?string $reason): void
    {
        if (!$ids) {
            return;
        }
        Db::run(
            "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?, updated_at = ?
             WHERE status IN " . self::ACTIVE . ' AND id IN (' . Db::in($ids) . ')',
            array_merge([Time::nowDb(), $by, $reason, Time::nowDb()], $ids)
        );
    }

    private static function scopeIds(int $id, string $scope, bool $confirmedOnly): array
    {
        $booking = self::find($id);
        if (!$booking) {
            throw new AppError('Booking not found.', 404);
        }
        if (!in_array($scope, ['date', 'following'], true) || !$booking['series_id']) {
            return [$booking['id']];
        }
        // 'date': every space booked with it for the same time; 'following': that and every later date.
        $sql = 'SELECT id FROM bookings WHERE series_id = ? AND start_utc ' . ($scope === 'date' ? '=' : '>=') . ' ?' . ($confirmedOnly ? ' AND status IN ' . self::ACTIVE : '');
        return array_map('intval', array_column(Db::all($sql, [$booking['series_id'], $booking['start_utc']]), 'id'));
    }

    /** The chosen spaces ('space_ids', or a single 'space_id'), in booking-page order. */
    private static function parseSpaces(array $in): array
    {
        $ids = isset($in['space_ids']) ? (array) $in['space_ids'] : [$in['space_id'] ?? 0];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            throw new AppError('Please choose a space.', 400, 'space_ids');
        }
        if (count($ids) > 10) {
            throw new AppError('Please choose up to 10 spaces at once.', 400, 'space_ids');
        }
        $spaces = [];
        foreach ($ids as $id) {
            $space = Spaces::find($id);
            if (!$space) {
                throw new AppError('Please choose a space.', 400, 'space_ids');
            }
            $spaces[] = $space;
        }
        usort($spaces, static fn ($a, $b) => [$a['sort_order'], $a['name']] <=> [$b['sort_order'], $b['name']]);
        return $spaces;
    }

    private static function parseDate(array $in): string
    {
        $date = (string) ($in['date'] ?? '');
        if (!Time::isDate($date)) {
            throw new AppError('Please choose a valid date.', 400, 'date');
        }
        return $date;
    }

    /** The dates of a tenant's reservation: just $date, or weekly until 'repeat_until'. */
    private static function publicDates(string $date, array $in): array
    {
        $repeat = (string) ($in['repeat'] ?? '');
        if ($repeat === '' || $repeat === 'none') {
            return [$date];
        }
        if ($repeat !== 'weekly') {
            throw new AppError('Reservations can repeat every week.', 400, 'repeat');
        }
        $weeks = (int) Settings::get('repeat_max_weeks');
        if ($weeks <= 0) {
            throw new AppError('Repeating reservations are turned off. Please reserve each date separately, or contact management.', 400, 'repeat');
        }
        $until = (string) ($in['repeat_until'] ?? '');
        if (!Time::isDate($until) || $until < Time::addDays($date, 7)) {
            throw new AppError('Please choose an end date at least a week after the first date.', 400, 'repeat_until');
        }
        $last = Time::addDays(Time::today(), $weeks * 7);
        if ($until > $last) {
            throw new AppError("Repeating reservations can be made up to $weeks weeks ahead, through " . Time::fmtDate($last) . '.', 400, 'repeat_until');
        }
        return self::occurrences($date, 'weekly', $until);
    }

    /**
     * Which dates have every space free at this time. Call inside the lockSpaces() transaction.
     * Returns [[[startUtc, endUtc], ...], conflicts]. Conflicts carry no names, so tenants can see them.
     */
    private static function freeDates(array $spaces, array $dates, int $start, int $end): array
    {
        $free = [];
        $conflicts = [];
        foreach ($dates as $d) {
            $startUtc = Time::localToUtc($d, $start);
            $endUtc = Time::localToUtc($d, $end);
            $clear = true;
            foreach ($spaces as $space) {
                $c = self::findConflict($space['id'], $startUtc, $endUtc);
                if ($c) {
                    $clear = false;
                    $conflicts[] = [
                        'date' => $d, 'space_id' => $space['id'], 'space' => $space['name'], 'kind' => $c['kind'],
                        'start' => $c['start'], 'end' => $c['end'], 'start_min' => $c['start_min'], 'end_min' => $c['end_min'],
                    ];
                }
            }
            if ($clear) {
                $free[] = [$startUtc, $endUtc];
            }
        }
        return [$free, $conflicts];
    }

    private static function roomList(array $spaceIds): string
    {
        return implode(' + ', array_map(static fn ($id) => Spaces::find((int) $id)['name'] ?? '?', $spaceIds));
    }

    private static function parseTimes(array $in): array
    {
        if (filter_var($in['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return [0, 1440];
        }
        $start = Time::toMinutes((string) ($in['start'] ?? ''));
        $end = Time::toMinutes((string) ($in['end'] ?? ''));
        if ($start === null || $start >= 1440) {
            throw new AppError('Please choose a start time.', 400, 'start');
        }
        if ($end === null) {
            throw new AppError('Please choose an end time.', 400, 'end');
        }
        if ($end <= $start) {
            throw new AppError('The end time needs to be after the start time.', 400, 'end');
        }
        if ($start % 5 || $end % 5) {
            throw new AppError('Please choose times in 5-minute steps.', 400, 'start');
        }
        return [$start, $end];
    }

    private static function parsePerson(array $in, bool $requireContact): array
    {
        $name = Util::cleanText($in['name'] ?? '', 120);
        $email = strtolower(Util::cleanText($in['email'] ?? '', 190));
        if ($requireContact && $name === '') {
            throw new AppError('Please enter your name.', 400, 'name');
        }
        if (($requireContact || $email !== '') && !Util::isValidEmail($email)) {
            throw new AppError('Please enter a valid email address, like name@company.com.', 400, 'email');
        }
        return [
            'name'       => Util::nullable($name),
            'email'      => Util::nullable($email),
            'title'      => Util::nullable(Util::cleanText($in['title'] ?? '', 150)),
            'company'    => Util::nullable(Util::cleanText($in['company'] ?? '', 150)),
            'notes'      => Util::nullable(Util::cleanText($in['notes'] ?? '', 2000, true)),
            'is_private' => filter_var($in['is_private'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    /** The public booking rules. Admin actions skip these. */
    private static function checkRules(array $space, string $date, int $start, int $end): void
    {
        if (!$space['is_active']) {
            throw new AppError("{$space['name']} isn’t open for reservations right now.", 400, 'space_id');
        }
        $duration = $end - $start;
        $min = (int) Settings::get('min_duration_minutes');
        $max = Spaces::maxDuration($space);
        if ($duration < $min) {
            throw new AppError('Reservations must be at least ' . Time::fmtDuration($min) . '.', 400, 'end');
        }
        if ($duration > $max) {
            throw new AppError("{$space['name']} can be reserved for up to " . Time::fmtDuration($max) . ' at a time. For longer events, please contact management.', 400, 'end');
        }
        $step = (int) Settings::get('time_increment');
        if ($start % $step || $end % $step) {
            throw new AppError("Please choose times in $step-minute steps.", 400, 'start');
        }

        $startAt = new DateTimeImmutable(Time::localToUtc($date, $start), Time::utc());
        $notice = (int) Settings::get('min_notice_minutes');
        if ($notice > 0 && $startAt < Time::now()->modify("+$notice minutes")) {
            throw new AppError('Reservations need to be made at least ' . Time::fmtDuration($notice) . ' in advance.', 400, 'start');
        }
        if ($startAt < Time::now()->modify('-' . self::GRACE_MINUTES . ' minutes')) {
            throw new AppError('That time has already passed. Please choose a later time.', 400, 'start');
        }
        $ahead = Spaces::maxDaysAhead($space);
        if ($date > Time::addDays(Time::today(), $ahead)) {
            throw new AppError("Reservations can be made up to $ahead days ahead.", 400, 'date');
        }

        $hours = Spaces::hoursOn($space, $date);
        $weekday = (new DateTimeImmutable($date))->format('l');
        if ($hours === null) {
            throw new AppError("{$space['name']} isn’t available on {$weekday}s.", 400, 'date');
        }
        if ($start < $hours[0] || $end > $hours[1]) {
            throw new AppError("{$space['name']} can be reserved from " . Time::fmtRange($hours[0], $hours[1]) . " on {$weekday}s.", 400, 'start');
        }
    }

    /** Dates for a repeating admin booking, starting with $date. */
    private static function occurrences(string $date, string $repeat, string $until): array
    {
        if ($repeat === 'none' || $repeat === '') {
            return [$date];
        }
        if (!in_array($repeat, ['daily', 'weekdays', 'weekly', 'biweekly', 'monthly'], true)) {
            throw new AppError('Unknown repeat option.', 400, 'repeat');
        }
        if (!Time::isDate($until) || $until < $date) {
            throw new AppError('Please choose when the repeat ends (on or after the first date).', 400, 'repeat_until');
        }
        if ($until > Time::addDays($date, 731)) {
            throw new AppError('Repeats can run for up to two years.', 400, 'repeat_until');
        }
        $dates = [];
        $first = new DateTimeImmutable($date);
        $last = new DateTimeImmutable($until);
        if ($repeat === 'monthly') {
            // Same day of the month; months without that day (e.g. the 31st) are skipped.
            $day = (int) $first->format('j');
            for ($i = 0; count($dates) < self::MAX_OCCURRENCES; $i++) {
                $month = $first->modify('first day of this month')->modify("+$i month");
                if ($month > $last) {
                    break;
                }
                if ($day <= (int) $month->format('t')) {
                    $candidate = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);
                    if ($candidate <= $last) {
                        $dates[] = $candidate->format('Y-m-d');
                    }
                }
            }
            return $dates;
        }
        $step = ['daily' => '+1 day', 'weekdays' => '+1 day', 'weekly' => '+1 week', 'biweekly' => '+2 weeks'][$repeat];
        for ($d = $first; $d <= $last && count($dates) < self::MAX_OCCURRENCES; $d = $d->modify($step)) {
            if ($repeat !== 'weekdays' || (int) $d->format('N') <= 5) {
                $dates[] = $d->format('Y-m-d');
            }
        }
        if (!$dates) {
            throw new AppError('No dates match that repeat pattern.', 400, 'repeat');
        }
        return $dates;
    }

    private static function cleanRequestId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $id) ? $id : null;
    }

    private static function summary(?array $b): string
    {
        if (!$b) {
            return '';
        }
        $space = Spaces::find($b['space_id']);
        return sprintf(
            '%s %s %s %s%s',
            $b['kind'] === 'block' ? 'Block' : 'Reservation',
            $space['name'] ?? ('space #' . $b['space_id']),
            $b['date'],
            Time::fmtRange($b['start_min'], $b['end_min']),
            $b['title'] ? ' — ' . $b['title'] : ''
        );
    }

    private static function changes(array $before, array $after): string
    {
        $out = [];
        if ($before['space_id'] !== $after['space_id']) {
            $out[] = 'space: ' . (Spaces::find($before['space_id'])['name'] ?? '?') . ' → ' . (Spaces::find($after['space_id'])['name'] ?? '?');
        }
        if ($before['start_utc'] !== $after['start_utc'] || $before['end_utc'] !== $after['end_utc']) {
            $out[] = sprintf(
                'time: %s %s → %s %s',
                $before['date'], Time::fmtRange($before['start_min'], $before['end_min']),
                $after['date'], Time::fmtRange($after['start_min'], $after['end_min'])
            );
        }
        foreach (['name', 'email', 'title', 'company', 'notes', 'is_private', 'notify'] as $field) {
            if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                $out[] = "$field changed";
            }
        }
        return $out ? implode('; ', $out) : 'no changes';
    }
}
