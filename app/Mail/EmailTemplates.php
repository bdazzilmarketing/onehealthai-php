<?php

namespace App\Mail;

use App\Support\Js;
use RuntimeException;

/**
 * The application's two email templates (port of src/lib/email-templates):
 * the administrator account link and the sales report notification. The HTML
 * and plain-text bodies are the same documents the original rendered.
 */
final class EmailTemplates
{
    public const NAMES = ['admin-account-link', 'sales-report-notification'];

    private const NAVY = '#0b1f4d';

    private const ACCOUNT_COPY = [
        'invite' => [
            'preview' => 'Activate your One Health administrator account',
            'heading' => 'Activate your administrator account',
            'body' => 'You have been given access to the One Health AI Lab Calculator admin portal. Choose a password to activate your account. You will then set up an authenticator app for two-step sign-in.',
            'button' => 'Activate account',
        ],
        'reset' => [
            'preview' => 'Reset your One Health administrator password',
            'heading' => 'Reset your password',
            'body' => 'A Super Admin requested a password reset for your One Health AI Lab Calculator administrator account. Choose a new password with the link below.',
            'button' => 'Choose a new password',
        ],
    ];

    private const BRAND = 'ONE HEALTH · AI LAB CALCULATOR';

    private const ACCOUNT_FOOTER = 'If you did not expect this email, ignore it and tell your One Health administrator. Never forward this link: it gives access to your account.';

    private const SALES_INTRO = 'A new One Health AI Lab Calculator submission has been received.';

    private const SALES_BODY = 'The full internal laboratory configuration and calculation report is available securely in the One Health Admin portal.';

    private const SALES_FOOTER = 'Internal One Health sales notification. Sign-in with an authorised administrator account is required to open the report. Do not forward to the client.';

    /**
     * @param  array<string, mixed>  $data
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $name, array $data): array
    {
        return match ($name) {
            'admin-account-link' => self::accountLink($data),
            'sales-report-notification' => self::salesReport($data),
            default => throw new RuntimeException("Template '{$name}' not found. Available: ".implode(', ', self::NAMES)),
        };
    }

    /** A value as it appears in a text position. */
    private static function value(array $data, string $key): string
    {
        return isset($data[$key]) && ! is_bool($data[$key]) ? Js::string($data[$key]) : '';
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#x27;'], $text);
    }

    /** Collapses whitespace the way the plain-text rendering of a paragraph does. */
    private static function plain(string $text): string
    {
        return trim(preg_replace('/[ \t\r\n\f\x{200b}]+/u', ' ', $text) ?? $text, ' ');
    }

    private static function open(string $preview): string
    {
        $preview = Js::slice($preview, 0, 150);
        $length = Js::length($preview);
        $filler = $length >= 150 ? '' : '<div>'.str_repeat("\u{A0}\u{200C}\u{200B}\u{200D}\u{200E}\u{200F}\u{FEFF}", 150 - $length).'</div>';

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            .'<html dir="ltr" lang="en"><head><meta content="text/html; charset=UTF-8" http-equiv="Content-Type"/><meta name="x-apple-disable-message-reformatting"/></head>'
            .'<body style="background-color:#ffffff;margin:0"><!--$--><!--html--><!--head-->'
            .'<div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0" data-skip-in-text="true">'.self::escape($preview).$filler.'</div><!--body-->'
            .'<table border="0" width="100%" cellPadding="0" cellSpacing="0" role="presentation" align="center"><tbody><tr><td style="background-color:#ffffff;font-family:Helvetica, Arial, sans-serif;margin:0">'
            .'<table align="center" width="100%" border="0" cellPadding="0" cellSpacing="0" role="presentation" style="max-width:560px;padding:32px 24px"><tbody><tr style="width:100%"><td>'
            .'<p style="font-size:13px;line-height:24px;margin:0;font-weight:700;letter-spacing:0.12em;color:'.self::NAVY.';margin-top:0;margin-bottom:0;margin-left:0;margin-right:0">'.self::BRAND.'</p>';
    }

    private static function heading(string $text): string
    {
        return '<h1 style="font-size:20px;color:#1c2130;margin:16px 0 8px">'.self::escape($text).'</h1>';
    }

    private static function button(string $href, string $label): string
    {
        return '<a href="'.self::escape($href).'" style="line-height:100%;text-decoration:none;display:inline-block;max-width:100%;mso-padding-alt:0px;background-color:'.self::NAVY
            .';color:#ffffff;padding:12px 20px;font-size:14px;font-weight:600;padding-top:12px;padding-right:20px;padding-bottom:12px;padding-left:20px" target="_blank">'
            .'<span><!--[if mso]><i style="mso-font-width:500%;mso-text-raise:18" hidden>&#8202;&#8202;</i><![endif]--></span>'
            .'<span style="max-width:100%;display:inline-block;line-height:120%;mso-padding-alt:0px;mso-text-raise:9px">'.self::escape($label).'</span>'
            .'<span><!--[if mso]><i style="mso-font-width:500%" hidden>&#8202;&#8202;&#8203;</i><![endif]--></span></a>';
    }

    private static function close(string $footer): string
    {
        return '<hr style="width:100%;border:none;border-top:1px solid #eaeaea;border-color:#e5e7eb;margin:28px 0 12px"/>'
            .'<p style="font-size:11px;line-height:24px;color:#6b7280;margin:0;margin-top:0;margin-bottom:0;margin-left:0;margin-right:0">'.$footer.'</p>'
            .'</td></tr></tbody></table></td></tr></tbody></table><!--/$--></body></html>';
    }

    /** @param  list<string>  $paragraphs */
    private static function text(string $heading, array $before, string $buttonLabel, string $href, array $after, string $footer): string
    {
        $link = self::plain($buttonLabel.' '.$href);

        return implode("\n\n", [
            self::BRAND."\n\n\n".strtoupper($heading),
            ...$before,
            $link,
            ...$after,
            '----------------------------------------',
            $footer,
        ]);
    }

    private static function accountLink(array $data): array
    {
        $kind = ($data['kind'] ?? null) === 'reset' ? 'reset' : 'invite';
        $copy = self::ACCOUNT_COPY[$kind];
        $url = self::value($data, 'url');
        $validFor = self::value($data, 'validFor');

        $html = self::open($copy['preview'])
            .self::heading($copy['heading'])
            .'<p style="font-size:14px;line-height:24px;color:#374151;margin:0 0 20px;margin-top:0;margin-right:0;margin-bottom:20px;margin-left:0">'.$copy['body'].'</p>'
            .self::button($url, $copy['button'])
            .'<p style="font-size:13px;line-height:24px;color:#374151;margin:20px 0 0;margin-top:20px;margin-right:0;margin-bottom:0;margin-left:0">The link works once and expires after <!-- -->'
            .self::escape($validFor).'<!-- -->.</p>'
            .self::close(self::ACCOUNT_FOOTER);

        return [
            'subject' => $kind === 'reset' ? 'Reset your One Health administrator password' : 'Activate your One Health administrator account',
            'html' => $html,
            'text' => self::text($copy['heading'], [$copy['body']], $copy['button'], $url,
                [self::plain("The link works once and expires after {$validFor}.")], self::ACCOUNT_FOOTER),
        ];
    }

    private static function salesReport(array $data): array
    {
        $rows = [
            'Reference' => self::value($data, 'referenceId'),
            'Name' => self::value($data, 'fullName'),
            'Company' => self::value($data, 'company'),
            'Email' => self::value($data, 'email'),
            'Phone' => self::value($data, 'phone'),
            'Public estimate range' => self::value($data, 'publicRange'),
        ];
        $adminUrl = self::value($data, 'adminUrl');

        $html = self::open("New Lab Calculator submission {$rows['Reference']} — {$rows['Company']}")
            .self::heading('New calculator submission')
            .'<p style="font-size:14px;line-height:24px;color:#374151;margin:0 0 20px;margin-top:0;margin-right:0;margin-bottom:20px;margin-left:0">'.self::SALES_INTRO.'</p>'
            .'<table align="center" width="100%" border="0" cellPadding="0" cellSpacing="0" role="presentation" style="border-left:3px solid '.self::NAVY.';padding-left:16px"><tbody><tr><td>';
        $text = [self::SALES_INTRO];
        foreach ($rows as $label => $value) {
            $html .= '<p style="font-size:14px;line-height:24px;margin:0 0 10px;color:#1c2130;margin-top:0;margin-right:0;margin-bottom:10px;margin-left:0">'
                .'<span style="display:block;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em">'.$label.'</span>'.self::escape($value).'</p>';
            $text[] = self::plain($label.$value);
        }
        $html .= '</td></tr></tbody></table>'
            .'<p style="font-size:14px;line-height:24px;color:#374151;margin:20px 0;margin-top:20px;margin-right:0;margin-bottom:20px;margin-left:0">'.self::SALES_BODY.'</p>'
            .self::button($adminUrl, 'View Internal Report')
            .self::close(self::SALES_FOOTER);
        $text[] = self::SALES_BODY;

        return [
            'subject' => 'New Lab Calculator Submission — '.Js::string($data['referenceId'] ?? null).' — '.Js::string($data['company'] ?? null),
            'html' => $html,
            'text' => self::text('New calculator submission', $text, 'View Internal Report', $adminUrl, [], self::SALES_FOOTER),
        ];
    }
}
