<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A rendered catalog notification as an email. Sent synchronously by the
 * already-queued notification, through the mailer MailService resolves.
 */
final class TemplatedMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        $paragraphs = array_map(
            static fn (string $paragraph): string => '<p>'.nl2br(e($paragraph)).'</p>',
            preg_split('/\n{2,}/', trim($this->body)) ?: [],
        );

        return new Content(htmlString: '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937">'
            .implode('', $paragraphs).'</body></html>');
    }
}
