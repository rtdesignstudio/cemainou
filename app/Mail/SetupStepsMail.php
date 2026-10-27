<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/** „Trimite-i pașii": pașii care îi lipsesc unui coleg ca cemainou să meargă la el (App\Support\SetupSteps), fiecare cu linkul lui. */
class SetupStepsMail extends Mailable
{
    /** @param  list<array{title: string, text: string, url: string}>  $steps */
    public function __construct(public User $person, public User $sender, public array $steps) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name sent you the steps to set up cemainou', ['name' => $this->sender->name]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.setup-steps', text: 'mail.setup-steps-text', with: [
            'name' => Str::before($this->person->name, ' '), 'senderName' => $this->sender->name, 'list' => $this->steps,
        ]);
    }
}
