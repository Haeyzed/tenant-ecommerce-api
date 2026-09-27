<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Mail;

use App\Modules\Messaging\Support\MailBranding;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A rendered catalog notification as an email (layout: mail.notification).
 * Sent synchronously by the already-queued notification, through the
 * mailer MailService resolves.
 */
final class TemplatedMail extends Mailable
{
    private const string FONT = "'Helvetica Neue', Helvetica, Arial, sans-serif";

    private const string URL = '~(https?://[^\s<>"\']+[^\s<>"\'.,:;!?)\]])~i';

    /**
     * @param  array<string, string>  $presentation  NotificationPresentation::resolve()
     * @param  array<string, mixed>  $branding  MailBranding::for()
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly array $presentation = [],
        public readonly array $branding = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        $accent = $this->color();
        $p = $this->presentation;

        $data = [
            'subject' => $this->mailSubject,
            'brandName' => (string) ($this->branding['name'] ?? config('app.name')),
            'logoUrl' => $this->branding['logo_url'] ?? null,
            'footerText' => $this->branding['footer'] ?? null,
            'dir' => ($this->branding['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            'accent' => $accent,
            'band' => match ($p['tone'] ?? 'default') {
                'success' => '#047857',
                'warning' => '#b45309',
                'danger' => '#b91c1c',
                default => $accent,
            },
            'font' => self::FONT,
            'eyebrow' => $p['eyebrow'] ?? null,
            'highlight' => $p['highlight'] ?? null,
            'highlightLabel' => $p['highlight_label'] ?? null,
            'actionUrl' => $p['action_url'] ?? null,
            'actionText' => $p['action_text'] ?? 'Open',
            'preheader' => $p['preheader'] ?? Str::limit((string) preg_replace('/\s+/', ' ', trim($this->body)), 110),
            'bodyText' => trim($this->body),
        ];

        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            with: $data + ['bodyHtml' => $this->bodyHtml($accent)],
        );
    }

    /**
     * Blank line = new paragraph, single line break = <br>, links clickable.
     * URLs are found in the raw text and every piece escaped on its own.
     */
    private function bodyHtml(string $accent): HtmlString
    {
        $html = '';

        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            $parts = preg_split(self::URL, $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$paragraph];
            $inner = '';

            foreach ($parts as $i => $part) {
                $inner .= $i % 2 === 1
                    ? '<a href="'.e($part).'" style="color:'.$accent.';text-decoration:underline;word-break:break-all;">'.e($part).'</a>'
                    : nl2br(e($part), false);
            }

            $html .= '<p style="margin:0 0 16px;font-family:'.self::FONT.';font-size:15px;line-height:24px;color:#374151;">'.$inner.'</p>';
        }

        return new HtmlString($html);
    }

    /**
     * Only a strict #RRGGBB value reaches the CSS.
     */
    private function color(): string
    {
        $color = $this->branding['color'] ?? null;

        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : MailBranding::DEFAULT_COLOR;
    }
}
