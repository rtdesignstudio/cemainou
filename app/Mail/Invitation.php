<?php

namespace App\Mail;

use App\Models\Project;
use App\Models\User;
use App\Support\Invitations;
use App\Support\Privacy;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitația: cine te-a invitat și linkul la care îți alegi parola (în limba ta, din cont). Clientul primește textul lui, cu proiectele pe care le vede. */
class Invitation extends Mailable
{
    public function __construct(public User $person, public User $inviter, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name invited you to cemainou', ['name' => $this->inviter->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation', text: 'mail.invitation-text', with: [
            'inviterName' => $this->inviter->name,                       // nu 'inviter': proprietatea publică cu același nume ar câștiga
            'email' => $this->person->email,
            'url' => $this->url,
            'privacyUrl' => Privacy::shareableUrl(),
            'client' => $this->person->isClient(),
            'projects' => $this->person->isClient() ? Project::visibleTo($this->person)->orderBy('name')->pluck('name')->all() : [],
            'until' => $this->person->invited_at->copy()->addDays(Invitations::DAYS)->setTimezone($this->person->tz())->locale(app()->getLocale())->isoFormat('D MMMM'),
        ]);
    }
}
