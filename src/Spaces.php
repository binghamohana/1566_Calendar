<?php
declare(strict_types=1);

namespace GPC;

final class Spaces
{
    public const UPLOAD_DIR = '/public/uploads/spaces';

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM spaces' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return array_map([self::class, 'decode'], Db::all($sql));
    }

    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM spaces WHERE id = ?', [$id]);
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['capacity'] = $row['capacity'] !== null ? (int) $row['capacity'] : null;
        $row['is_active'] = (bool) $row['is_active'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['max_duration_minutes'] = $row['max_duration_minutes'] !== null ? (int) $row['max_duration_minutes'] : null;
        $row['max_days_ahead'] = $row['max_days_ahead'] !== null ? (int) $row['max_days_ahead'] : null;
        $row['hours'] = $row['hours'] ? json_decode($row['hours'], true) : null;
        return $row;
    }

    /** What visitors to the public page are allowed to know about a space. */
    public static function publicView(array $s): array
    {
        return [
            'id'          => $s['id'],
            'slug'        => $s['slug'],
            'name'        => $s['name'],
            'short_name'  => $s['short_name'] ?: $s['name'],
            'location'    => $s['location'],
            'description' => $s['description'],
            'capacity'    => $s['capacity'],
            'amenities'   => self::amenityList($s['amenities']),
            'photo'       => $s['photo'] ? 'uploads/spaces/' . $s['photo'] : null,
            'color'       => $s['color'],
            'hours'       => $s['hours'],
            'max_duration_minutes' => self::maxDuration($s),
            'max_days_ahead'       => self::maxDaysAhead($s),
        ];
    }

    public static function adminView(array $s): array
    {
        $view = $s;
        $view['photo_url'] = $s['photo'] ? 'uploads/spaces/' . $s['photo'] : null;
        $view['upcoming_count'] = (int) Db::value(
            "SELECT COUNT(*) FROM bookings WHERE space_id = ? AND status = 'confirmed' AND end_utc > ?",
            [$s['id'], Time::nowDb()]
        );
        return $view;
    }

    public static function amenityList(?string $amenities): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $amenities))));
    }

    public static function maxDuration(array $space): int
    {
        return $space['max_duration_minutes'] ?? (int) Settings::get('max_duration_minutes');
    }

    public static function maxDaysAhead(array $space): int
    {
        return $space['max_days_ahead'] ?? (int) Settings::get('max_days_ahead');
    }

    /**
     * Opening hours for a date as [startMinute, endMinute], or null when closed all day.
     * Spaces without configured hours are always available.
     */
    public static function hoursOn(array $space, string $date): ?array
    {
        if (!$space['hours']) {
            return [0, 1440];
        }
        $day = $space['hours'][(string) Time::weekday($date)] ?? null;
        if (!$day) {
            return null;
        }
        return [Time::toMinutes($day[0]) ?? 0, Time::toMinutes($day[1]) ?? 1440];
    }

    public static function save(array $in, ?int $id = null): array
    {
        $existing = $id ? self::find($id) : null;
        if ($id && !$existing) {
            throw new AppError('Space not found.', 404);
        }

        $name = Util::cleanText($in['name'] ?? '', 120);
        if ($name === '') {
            throw new AppError('Please give the space a name.', 400, 'name');
        }
        $slug = self::slugify(($in['slug'] ?? '') !== '' ? $in['slug'] : $name);
        $clash = Db::value('SELECT id FROM spaces WHERE slug = ? AND id <> ?', [$slug, $id ?? 0]);
        if ($clash) {
            throw new AppError('Another space already uses that web address (slug).', 400, 'slug');
        }
        $color = (string) ($in['color'] ?? '#3E5C4A');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new AppError('Color must look like #3E5C4A.', 400, 'color');
        }

        $data = [
            'slug'                 => $slug,
            'name'                 => $name,
            'short_name'           => Util::nullable(Util::cleanText($in['short_name'] ?? '', 60)),
            'location'             => Util::nullable(Util::cleanText($in['location'] ?? '', 120)),
            'description'          => Util::nullable(Util::cleanText($in['description'] ?? '', 2000, true)),
            'capacity'             => self::optionalInt($in['capacity'] ?? null, 1, 5000, 'capacity'),
            'amenities'            => Util::nullable(Util::cleanText($in['amenities'] ?? '', 500)),
            'color'                => strtoupper($color),
            'instructions'         => Util::nullable(Util::cleanText($in['instructions'] ?? '', 3000, true)),
            'cleanup_message'      => Util::nullable(Util::cleanText($in['cleanup_message'] ?? '', 3000, true)),
            'hours'                => self::validateHours($in['hours'] ?? null),
            'max_duration_minutes' => self::optionalInt($in['max_duration_minutes'] ?? null, 15, 1440, 'max_duration_minutes'),
            'max_days_ahead'       => self::optionalInt($in['max_days_ahead'] ?? null, 1, 730, 'max_days_ahead'),
            'is_active'            => filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'updated_at'           => Time::nowDb(),
        ];

        if ($existing) {
            Db::update('spaces', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['sort_order'] = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM spaces');
            $data['created_at'] = Time::nowDb();
            $id = Db::insert('spaces', $data);
        }
        return self::find($id);
    }

    public static function delete(int $id): void
    {
        if (Db::value('SELECT COUNT(*) FROM bookings WHERE space_id = ?', [$id])) {
            throw new AppError('This space has reservation history, so it can’t be deleted. Turn off “Available for booking” to hide it instead.', 400);
        }
        $space = self::find($id);
        if ($space && $space['photo']) {
            @unlink(GPC_ROOT . self::UPLOAD_DIR . '/' . $space['photo']);
        }
        Db::run('DELETE FROM spaces WHERE id = ?', [$id]);
    }

    public static function reorder(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            Db::run('UPDATE spaces SET sort_order = ? WHERE id = ?', [$i + 1, (int) $id]);
        }
    }

    /** Store an uploaded photo. The image is re-encoded, which strips anything that isn't a picture. */
    public static function savePhoto(int $id, array $file): array
    {
        $space = self::find($id);
        if (!$space) {
            throw new AppError('Space not found.', 404);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new AppError('The upload didn’t work. Please try a JPG or PNG under 8 MB.', 400, 'photo');
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            throw new AppError('Please use an image smaller than 8 MB.', 400, 'photo');
        }
        $info = @getimagesize($file['tmp_name']);
        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!$info || !isset($loaders[$info[2]]) || !function_exists($loaders[$info[2]])) {
            throw new AppError('Please upload a JPG, PNG or WebP image.', 400, 'photo');
        }
        $src = @$loaders[$info[2]]($file['tmp_name']);
        if (!$src) {
            throw new AppError('That image could not be read.', 400, 'photo');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $src = imagerotate($src, $angle, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 1800 / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $dir = GPC_ROOT . self::UPLOAD_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new AppError('The uploads folder is not writable on the server.', 500);
        }
        $filename = $space['slug'] . '-' . Util::token(4) . '.jpg';
        if (!imagejpeg($dst, $dir . '/' . $filename, 82)) {
            throw new AppError('Could not save the image. Check that public/uploads/spaces is writable.', 500);
        }
        if ($space['photo']) {
            @unlink($dir . '/' . $space['photo']);
        }
        Db::update('spaces', ['photo' => $filename, 'updated_at' => Time::nowDb()], 'id = :id', ['id' => $id]);
        return self::find($id);
    }

    public static function removePhoto(int $id): void
    {
        $space = self::find($id);
        if ($space && $space['photo']) {
            @unlink(GPC_ROOT . self::UPLOAD_DIR . '/' . $space['photo']);
            Db::update('spaces', ['photo' => null], 'id = :id', ['id' => $id]);
        }
    }

    public static function slugify(string $value): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));
        return substr($slug, 0, 80) ?: 'space';
    }

    private static function optionalInt($value, int $min, int $max, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw new AppError("Please enter a number between $min and $max.", 400, $field);
        }
        return (int) $value;
    }

    /** Hours look like {"0": null, "1": ["07:00", "22:00"], ...}; null/empty = always available. */
    private static function validateHours($hours): ?string
    {
        if ($hours === null || $hours === '' || $hours === []) {
            return null;
        }
        if (is_string($hours)) {
            $hours = json_decode($hours, true);
        }
        if (!is_array($hours)) {
            throw new AppError('Opening hours are not in the expected format.', 400, 'hours');
        }
        $clean = [];
        for ($d = 0; $d <= 6; $d++) {
            $day = $hours[(string) $d] ?? $hours[$d] ?? null;
            if (!$day) {
                $clean[(string) $d] = null;
                continue;
            }
            $start = Time::toMinutes((string) ($day[0] ?? ''));
            $end = Time::toMinutes((string) ($day[1] ?? ''));
            if ($start === null || $end === null || $end <= $start) {
                throw new AppError('Each open day needs a closing time after its opening time.', 400, 'hours');
            }
            $clean[(string) $d] = [Time::fromMinutes($start), Time::fromMinutes($end)];
        }
        return json_encode($clean);
    }
}
