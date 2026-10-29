<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Adminului, imediat: cineva din afară a deschis ce n-ar trebui, i s-a oprit o trimitere sau încearcă numere (App\Support\AccessGuard). */
class AccessAlertMail extends Mailable
{
    public function __construct(public string $alert, public string $details, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.access-alert', text: 'mail.access-alert-text', with: ['alert' => $this->alert, 'details' => $this->details, 'link' => $this->link]);
    }
}
