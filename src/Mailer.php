<?php
declare(strict_types=1);

namespace GPC;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Outbox-based email: every message is written to the `emails` table first, then delivered.
 * If delivery fails (SMTP down, bad password) the message stays queued and the cron job
 * retries it with back-off, so a booking never fails because email had a bad moment.
 */
final class Mailer
{
    private const MAX_ATTEMPTS = 5;

    public static function queue(string $type, string $to, ?string $toName, string $subject, array $body, ?int $bookingId = null, ?string $ics = null): int
    {
        return Db::insert('emails', [
            'booking_id' => $bookingId,
            'type'       => $type,
            'to_email'   => $to,
            'to_name'    => $toName ? Util::cleanText($toName, 120) : null,
            'subject'    => Util::cleanText($subject, 255),
            'body_html'  => $body[0],
            'body_text'  => $body[1],
            'ics'        => $ics,
            'status'     => 'pending',
            'send_after' => Time::nowDb(),
            'created_at' => Time::nowDb(),
        ]);
    }

    /** Deliver specific queued messages right away (used straight after a booking). */
    public static function sendNow(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $rows = Db::all("SELECT * FROM emails WHERE status = 'pending' AND id IN (" . Db::in($ids) . ')', $ids);
        foreach ($rows as $row) {
            self::attempt($row);
        }
    }

    /** Deliver everything that is due. Returns [sent, failed]. */
    public static function sendDue(int $limit = 50): array
    {
        $rows = Db::all(
            "SELECT * FROM emails WHERE status = 'pending' AND send_after <= ? ORDER BY id LIMIT " . (int) $limit,
            [Time::nowDb()]
        );
        $sent = $failed = 0;
        foreach ($rows as $row) {
            self::attempt($row) ? $sent++ : $failed++;
        }
        return [$sent, $failed];
    }

    public static function retry(int $id): void
    {
        Db::run("UPDATE emails SET status = 'pending', attempts = 0, send_after = ? WHERE id = ?", [Time::nowDb(), $id]);
        self::sendNow([$id]);
    }

    private static function attempt(array $row): bool
    {
        // Claim the row so a concurrent cron run can't send it twice.
        $claimed = Db::run(
            "UPDATE emails SET attempts = attempts + 1, send_after = ? WHERE id = ? AND status = 'pending' AND attempts = ?",
            [Time::now()->modify('+10 minutes')->format('Y-m-d H:i:s'), $row['id'], $row['attempts']]
        )->rowCount();
        if (!$claimed) {
            return false;
        }
        $attempts = (int) $row['attempts'] + 1;
        try {
            self::deliver($row);
            Db::update('emails', ['status' => 'sent', 'sent_at' => Time::nowDb(), 'last_error' => null], 'id = :id', ['id' => $row['id']]);
            return true;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            App::log("Email #{$row['id']} to {$row['to_email']} failed (attempt $attempts): $error");
            $final = $attempts >= self::MAX_ATTEMPTS;
            Db::update('emails', [
                'status'     => $final ? 'failed' : 'pending',
                'last_error' => $error,
                'send_after' => Time::now()->modify('+' . (5 * $attempts * $attempts) . ' minutes')->format('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $row['id']]);
            return false;
        }
    }

    private static function deliver(array $row): void
    {
        $transport = (string) App::config('mail.transport', 'log');
        if ($transport === 'log') {
            $dir = GPC_ROOT . '/storage/logs';
            $entry = sprintf(
                "==== %s | to: %s | %s\n%s\n%s\n\n",
                Time::nowDb(), $row['to_email'], $row['subject'], $row['body_text'],
                $row['ics'] ? "[attachment: reservation.ics]\n" : ''
            );
            if (@file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Could not write storage/logs/mail.log');
            }
            return;
        }

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 20;
        if ($transport === 'smtp') {
            $mail->isSMTP();
            $mail->Host = (string) App::config('mail.host');
            $mail->Port = (int) App::config('mail.port', 587);
            $encryption = (string) App::config('mail.encryption', 'tls');
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($encryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $mail->SMTPAutoTLS = $encryption !== '';
            $username = (string) App::config('mail.username', '');
            if ($username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = (string) App::config('mail.password', '');
            }
        } else {
            $mail->isMail();
        }

        $mail->setFrom((string) App::config('mail.from_email'), (string) App::config('mail.from_name', Settings::get('org_name')));
        $replyTo = (string) Settings::get('contact_email');
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo, (string) Settings::get('org_name'));
        }
        $mail->addAddress($row['to_email'], (string) $row['to_name']);
        $mail->Subject = $row['subject'];
        $mail->isHTML(true);
        $mail->Body = $row['body_html'];
        $mail->AltBody = $row['body_text'];
        if ($row['ics']) {
            $mail->addStringAttachment($row['ics'], 'reservation.ics', PHPMailer::ENCODING_BASE64, 'text/calendar; charset=utf-8; method=PUBLISH');
        }
        $mail->send();
    }
}
