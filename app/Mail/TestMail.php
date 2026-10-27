<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Emailul de test din Setări: dacă ajunge, invitațiile și resetările de parolă vor ajunge și ele. */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('cemainou: email works'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test');
    }
}
