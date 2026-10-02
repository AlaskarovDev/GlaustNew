<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One branded template for every system mail.
 *
 * $sections: [['title' => 'Bugünkü tapşırıqlar', 'items' => [['title' => ..., 'meta' => ..., 'url' => ..., 'tone' => 'danger'|null]]]]
 */
class SystemMail extends Mailable
{
    public function __construct(
        public string $mailSubject,
        public string $heading,
        public array $lines = [],
        public array $sections = [],
        public ?string $actionText = null,
        public ?string $actionUrl = null,
        public ?string $companyName = null,
        public ?string $footnote = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.system');
    }
}
