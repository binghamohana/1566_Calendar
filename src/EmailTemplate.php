<?php
declare(strict_types=1);

namespace GPC;

/** Branded HTML + plain-text email bodies. Inline styles only, table layout, for broad client support. */
final class EmailTemplate
{
    private const GREEN = '#2F4A3A';
    private const INK = '#1F2421';
    private const MUTED = '#6B6F6A';
    private const SAND = '#F4F1EB';
    private const LINE = '#E4DED3';

    /**
     * @param array{
     *   preheader?: string, heading: string, paragraphs?: string[], details?: array<string,string>,
     *   notes?: array<int, array{title: string, body: string}>, buttons?: array<string,string>, footer?: string
     * } $m
     * @return array{0: string, 1: string} [html, text]
     */
    public static function render(array $m): array
    {
        $org = (string) Settings::get('org_name');
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $nl = static fn ($s) => nl2br($e($s), false);

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($m['heading']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::SAND . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($m['preheader'] ?? '') . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::SAND . ';padding:32px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
            . '<tr><td style="padding:0 8px 18px;font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:3px;text-transform:uppercase;color:' . self::GREEN . ';font-weight:600;">'
            . $e($org) . '</td></tr>'
            . '<tr><td style="background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:14px;padding:36px 32px;">'
            . '<h1 style="margin:0 0 18px;font-family:Georgia,\'Times New Roman\',serif;font-weight:normal;font-size:28px;line-height:1.25;color:' . self::INK . ';">' . $e($m['heading']) . '</h1>';

        foreach ($m['paragraphs'] ?? [] as $p) {
            $html .= '<p style="margin:0 0 16px;font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:' . self::INK . ';">' . $nl($p) . '</p>';
        }

        if (!empty($m['details'])) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;border-top:1px solid ' . self::LINE . ';">';
            foreach ($m['details'] as $label => $value) {
                $html .= '<tr><td style="padding:12px 0;border-bottom:1px solid ' . self::LINE . ';font-family:Helvetica,Arial,sans-serif;font-size:13px;color:' . self::MUTED . ';width:34%;vertical-align:top;">' . $e($label) . '</td>'
                    . '<td style="padding:12px 0;border-bottom:1px solid ' . self::LINE . ';font-family:Helvetica,Arial,sans-serif;font-size:15px;color:' . self::INK . ';font-weight:600;vertical-align:top;">' . $nl($value) . '</td></tr>';
            }
            $html .= '</table>';
        }

        foreach ($m['notes'] ?? [] as $note) {
            $html .= '<div style="margin:0 0 18px;padding:16px 18px;background:' . self::SAND . ';border-radius:10px;">'
                . '<div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:' . self::GREEN . ';font-weight:600;margin-bottom:6px;">' . $e($note['title']) . '</div>'
                . '<div style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:' . self::INK . ';">' . $nl($note['body']) . '</div></div>';
        }

        if (!empty($m['buttons'])) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;"><tr>';
            $first = true;
            foreach ($m['buttons'] as $label => $url) {
                $style = $first
                    ? 'background:' . self::GREEN . ';color:#ffffff;border:1px solid ' . self::GREEN . ';'
                    : 'background:#ffffff;color:' . self::GREEN . ';border:1px solid ' . self::GREEN . ';';
                $html .= '<td style="padding:0 10px 10px 0;"><a href="' . $e($url) . '" style="' . $style
                    . 'display:inline-block;padding:12px 20px;border-radius:999px;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;text-decoration:none;">'
                    . $e($label) . '</a></td>';
                $first = false;
            }
            $html .= '</tr></table>';
        }

        $html .= '</td></tr>'
            . '<tr><td style="padding:18px 8px 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:' . self::MUTED . ';">'
            . $nl($m['footer'] ?? self::footer()) . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        // Plain-text version
        $text = strtoupper($org) . "\n\n" . $m['heading'] . "\n\n";
        foreach ($m['paragraphs'] ?? [] as $p) {
            $text .= $p . "\n\n";
        }
        foreach ($m['details'] ?? [] as $label => $value) {
            $text .= str_pad($label . ':', 12) . ' ' . $value . "\n";
        }
        if (!empty($m['details'])) {
            $text .= "\n";
        }
        foreach ($m['notes'] ?? [] as $note) {
            $text .= strtoupper($note['title']) . "\n" . $note['body'] . "\n\n";
        }
        foreach ($m['buttons'] ?? [] as $label => $url) {
            $text .= $label . ': ' . $url . "\n";
        }
        $text .= "\n--\n" . ($m['footer'] ?? self::footer()) . "\n";

        return [$html, $text];
    }

    public static function footer(): string
    {
        $lines = [(string) Settings::get('org_name') . ' · ' . App::baseUrl()];
        $contact = array_filter([(string) Settings::get('contact_email'), (string) Settings::get('contact_phone')]);
        if ($contact) {
            $lines[] = 'Questions? Contact management: ' . implode(' · ', $contact);
        }
        return implode("\n", $lines);
    }
}
