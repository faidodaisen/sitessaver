<?php

declare(strict_types=1);

namespace SitesSaver;

defined('ABSPATH') || exit;

/**
 * Branded HTML email for backup reports.
 *
 * Everything is table-based with inline styles. Gmail, Outlook.com, and the
 * Apple Mail stack all strip <style> blocks to varying degrees, and Outlook
 * on Windows renders through Word — flexbox, grid, and external CSS are not
 * options here, however ugly the markup looks in source.
 *
 * Every send carries a plain-text alternative built from the same data, so
 * text-only clients and spam filters both see real content rather than an
 * empty body.
 */
final class Mailer {

    /** Option key holding the branding fields. */
    public const BRAND_OPTION = 'sitessaver_email_brand';

    /**
     * Branding, with sensible fallbacks derived from the site itself so a
     * user who never opens the branding panel still gets a decent email.
     *
     * @return array<string, string>
     */
    public static function branding(): array {
        $saved = get_option(self::BRAND_OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        $defaults = [
            'enabled'     => '1',
            'logo_url'    => '',
            'accent'      => '#2271b1',
            'from_name'   => get_bloginfo('name'),
            'from_email'  => '',
            'footer_note' => '',
            'support_url' => '',
        ];

        $brand = array_merge($defaults, array_filter(
            $saved,
            static fn($v) => $v !== null && $v !== ''
        ));

        // A malformed colour would break every inline style that interpolates
        // it, so fall back rather than emit garbage.
        if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $brand['accent'])) {
            $brand['accent'] = '#2271b1';
        }

        return array_map('strval', $brand);
    }

    /**
     * Is HTML output switched on?
     */
    public static function html_enabled(): bool {
        return self::branding()['enabled'] !== '0';
    }

    /**
     * Send one email, HTML when enabled with a plain-text fallback.
     *
     * The content-type filter is added and removed around this one call so
     * SitesSaver never changes the format of mail sent by other plugins.
     */
    public static function send(string $to, string $subject, string $html, string $text): bool {
        $brand   = self::branding();
        $use_html = $brand['enabled'] !== '0';

        $headers = [];

        $from_name  = trim($brand['from_name']) !== '' ? $brand['from_name'] : get_bloginfo('name');
        $from_email = trim($brand['from_email']);
        if ($from_email !== '' && is_email($from_email)) {
            $headers[] = sprintf('From: %s <%s>', self::strip_header($from_name), $from_email);
        }

        if (!$use_html) {
            return (bool) wp_mail($to, $subject, $text, $headers);
        }

        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $filter = static fn() => 'text/html';
        add_filter('wp_mail_content_type', $filter);

        try {
            $sent = wp_mail($to, $subject, $html, $headers);
        } finally {
            // Must run even if wp_mail throws, otherwise every later mail on
            // this request silently becomes HTML.
            remove_filter('wp_mail_content_type', $filter);
        }

        return (bool) $sent;
    }

    /**
     * Header values must not carry line breaks — that is header injection.
     */
    private static function strip_header(string $value): string {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    // ------------------------------------------------------------------
    // Report rendering
    // ------------------------------------------------------------------

    /**
     * Build the branded HTML body for a backup report.
     *
     * @param array{
     *     success: bool,
     *     title: string,
     *     intro: string,
     *     rows: array<int, array{0: string, 1: string}>,
     *     storage: array<int, array{state: string, label: string, detail: string, url?: string}>,
     *     notes: array<int, string>,
     *     actions: array<int, array{label: string, url: string, primary?: bool}>
     * } $data
     */
    public static function render_html(array $data): string {
        $brand  = self::branding();
        $accent = $brand['accent'];
        $ok     = !empty($data['success']);

        $status_bg   = $ok ? '#e7f6ec' : '#fdecea';
        $status_fg   = $ok ? '#0a7c33' : '#b3261e';
        $status_text = $ok ? __('Completed', 'sitessaver') : __('Failed', 'sitessaver');
        $bar_color   = $ok ? $accent : '#b3261e';

        $site_name = get_bloginfo('name');

        // --- Masthead: logo when set, otherwise a wordmark on the accent bar.
        if (trim($brand['logo_url']) !== '') {
            $masthead = sprintf(
                '<img src="%s" alt="%s" width="150" style="display:block;border:0;outline:none;text-decoration:none;max-width:180px;height:auto;margin:0 auto;" />',
                esc_url($brand['logo_url']),
                esc_attr($site_name)
            );
        } else {
            $masthead = sprintf(
                '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:20px;line-height:26px;font-weight:700;color:#ffffff;letter-spacing:-0.2px;">%s</div>',
                esc_html($site_name)
            );
        }

        // --- Detail rows.
        $rows_html = '';
        foreach ($data['rows'] as $row) {
            $rows_html .= sprintf(
                '<tr>
                    <td style="padding:9px 0;border-bottom:1px solid #eceff2;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:19px;color:#6b7480;white-space:nowrap;vertical-align:top;width:130px;">%s</td>
                    <td style="padding:9px 0;border-bottom:1px solid #eceff2;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:19px;color:#1d2327;font-weight:600;word-break:break-word;vertical-align:top;">%s</td>
                </tr>',
                esc_html($row[0]),
                esc_html($row[1])
            );
        }

        // --- Storage cards.
        $storage_html = '';
        foreach ($data['storage'] as $item) {
            $state = $item['state'] ?? 'ok';

            if ($state === 'ok') {
                $dot = '#0a7c33';
                $bg  = '#f6faf7';
                $bd  = '#d8ebde';
            } elseif ($state === 'warn') {
                $dot = '#b3261e';
                $bg  = '#fdf6f5';
                $bd  = '#f3d5d2';
            } else {
                $dot = '#8a929c';
                $bg  = '#f8f9fa';
                $bd  = '#e6e9ec';
            }

            $link = '';
            if (!empty($item['url'])) {
                $link = sprintf(
                    '<div style="margin-top:6px;"><a href="%1$s" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:%2$s;text-decoration:underline;word-break:break-all;">%3$s</a></div>',
                    esc_url($item['url']),
                    esc_attr($accent),
                    esc_html__('Open in Google Drive', 'sitessaver')
                );
            }

            $storage_html .= sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="border-collapse:separate;margin-bottom:8px;">
                    <tr>
                        <td style="background:%1$s;border:1px solid %2$s;border-radius:8px;padding:12px 14px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%">
                                <tr>
                                    <td width="10" style="vertical-align:top;padding-top:5px;">
                                        <div style="width:8px;height:8px;border-radius:8px;background:%3$s;font-size:0;line-height:0;">&nbsp;</div>
                                    </td>
                                    <td style="padding-left:10px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
                                        <div style="font-size:13px;line-height:19px;font-weight:600;color:#1d2327;">%4$s</div>
                                        <div style="font-size:12px;line-height:18px;color:#6b7480;margin-top:2px;">%5$s</div>
                                        %6$s
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>',
                esc_attr($bg),
                esc_attr($bd),
                esc_attr($dot),
                esc_html($item['label']),
                esc_html($item['detail']),
                $link
            );
        }

        if ($storage_html !== '') {
            $storage_html = sprintf(
                '<tr><td style="padding:22px 28px 0 28px;">
                    <div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;line-height:16px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#8a929c;margin-bottom:10px;">%s</div>
                    %s
                </td></tr>',
                esc_html__('Where this backup is stored', 'sitessaver'),
                $storage_html
            );
        }

        // --- Notes.
        $notes_html = '';
        foreach ($data['notes'] as $note) {
            $notes_html .= sprintf(
                '<tr><td style="padding:0 0 6px 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#6b7480;">%s</td></tr>',
                esc_html($note)
            );
        }
        if ($notes_html !== '') {
            $notes_html = '<tr><td style="padding:18px 28px 0 28px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' . $notes_html . '</table></td></tr>';
        }

        // --- Buttons. Bulletproof enough: a padded anchor inside a rounded td.
        $actions_html = '';
        foreach ($data['actions'] as $action) {
            $primary = !empty($action['primary']);
            $actions_html .= sprintf(
                '<td style="padding:0 8px 8px 0;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="background:%1$s;border:1px solid %2$s;border-radius:6px;">
                                <a href="%3$s" style="display:inline-block;padding:10px 18px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:18px;font-weight:600;color:%4$s;text-decoration:none;">%5$s</a>
                            </td>
                        </tr>
                    </table>
                </td>',
                $primary ? esc_attr($accent) : '#ffffff',
                esc_attr($accent),
                esc_url($action['url']),
                $primary ? '#ffffff' : esc_attr($accent),
                esc_html($action['label'])
            );
        }
        if ($actions_html !== '') {
            $actions_html = '<tr><td style="padding:22px 28px 0 28px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $actions_html . '</tr></table></td></tr>';
        }

        // --- Footer note.
        $footer_note = trim($brand['footer_note']);
        $footer_html = $footer_note !== ''
            ? sprintf(
                '<div style="margin-top:8px;color:#8a929c;">%s</div>',
                nl2br(esc_html($footer_note))
            )
            : '';

        $support_html = '';
        if (trim($brand['support_url']) !== '') {
            $support_html = sprintf(
                '<div style="margin-top:8px;"><a href="%1$s" style="color:%2$s;text-decoration:underline;">%3$s</a></div>',
                esc_url($brand['support_url']),
                esc_attr($accent),
                esc_html__('Need help with this backup?', 'sitessaver')
            );
        }

        // Preheader: the grey preview line next to the subject in the inbox.
        // Hidden in the body itself by the zero-size/hidden style combo.
        $preheader = sprintf(
            '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">%s</div>',
            esc_html($data['intro'])
        );

        return sprintf(
            '<!DOCTYPE html>
<html lang="%1$s">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<title>%2$s</title>
</head>
<body style="margin:0;padding:0;background:#f2f4f6;">
%3$s
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="background:#f2f4f6;">
    <tr>
        <td align="center" style="padding:28px 14px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                <tr><td style="height:4px;background:%4$s;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td align="center" style="background:%5$s;padding:22px 28px;">%6$s</td>
                </tr>
                <tr>
                    <td style="padding:26px 28px 0 28px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
                        <span style="display:inline-block;background:%7$s;color:%8$s;font-size:11px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;padding:4px 10px;border-radius:20px;">%9$s</span>
                        <h1 style="margin:14px 0 6px 0;font-size:20px;line-height:27px;font-weight:700;color:#1d2327;letter-spacing:-0.2px;">%10$s</h1>
                        <p style="margin:0;font-size:14px;line-height:21px;color:#5c6570;">%11$s</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px 0 28px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="border-collapse:collapse;border-top:1px solid #eceff2;">%12$s</table>
                    </td>
                </tr>
                %13$s
                %14$s
                %15$s
                <tr>
                    <td style="padding:26px 28px 28px 28px;">
                        <div style="border-top:1px solid #eceff2;padding-top:16px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;color:#6b7480;">
                            <div><strong style="color:#1d2327;">%16$s</strong></div>
                            <div style="margin-top:2px;"><a href="%17$s" style="color:%4$s;text-decoration:none;">%18$s</a></div>
                            %19$s
                            %20$s
                        </div>
                    </td>
                </tr>
            </table>
            <div style="margin-top:14px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;line-height:17px;color:#98a0aa;">%21$s</div>
        </td>
    </tr>
</table>
</body>
</html>',
            esc_attr(str_replace('_', '-', get_locale())),
            esc_html($data['title']),
            $preheader,
            esc_attr($bar_color),
            esc_attr($accent),
            $masthead,
            esc_attr($status_bg),
            esc_attr($status_fg),
            esc_html($status_text),
            esc_html($data['title']),
            esc_html($data['intro']),
            $rows_html,
            $storage_html,
            $notes_html,
            $actions_html,
            esc_html($site_name),
            esc_url(home_url()),
            esc_html(preg_replace('#^https?://#', '', home_url()) ?? ''),
            $support_html,
            $footer_html,
            esc_html__('Sent automatically by SitesSaver because this site has scheduled backup notifications enabled.', 'sitessaver')
        );
    }

    /**
     * Plain-text twin of render_html(), from the same data.
     *
     * @param array<string, mixed> $data
     */
    public static function render_text(array $data): string {
        $brand = self::branding();
        $lines = [];

        $lines[] = (string) $data['title'];
        $lines[] = str_repeat('=', min(60, strlen((string) $data['title'])));
        $lines[] = '';
        $lines[] = (string) $data['intro'];
        $lines[] = '';

        foreach ($data['rows'] as $row) {
            $lines[] = $row[0] . ': ' . $row[1];
        }

        if (!empty($data['storage'])) {
            $lines[] = '';
            $lines[] = strtoupper(__('Where this backup is stored', 'sitessaver'));
            foreach ($data['storage'] as $item) {
                $lines[] = '- ' . $item['label'] . ': ' . $item['detail'];
                if (!empty($item['url'])) {
                    $lines[] = '  ' . $item['url'];
                }
            }
        }

        if (!empty($data['notes'])) {
            $lines[] = '';
            foreach ($data['notes'] as $note) {
                $lines[] = $note;
            }
        }

        if (!empty($data['actions'])) {
            $lines[] = '';
            foreach ($data['actions'] as $action) {
                $lines[] = $action['label'] . ': ' . $action['url'];
            }
        }

        $lines[] = '';
        $lines[] = '--';
        $lines[] = get_bloginfo('name') . ' — ' . home_url();

        if (trim($brand['support_url']) !== '') {
            $lines[] = __('Need help with this backup?', 'sitessaver') . ' ' . $brand['support_url'];
        }
        if (trim($brand['footer_note']) !== '') {
            $lines[] = $brand['footer_note'];
        }

        $lines[] = __('Sent automatically by SitesSaver because this site has scheduled backup notifications enabled.', 'sitessaver');

        return implode("\n", $lines);
    }
}
