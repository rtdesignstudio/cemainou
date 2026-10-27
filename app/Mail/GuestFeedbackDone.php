<?php

namespace App\Mail;

use App\Models\Task;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Vizitatorului care a trimis feedback prin „QR fără logare" și și-a lăsat emailul: ce a cerut e gata (cu dovada, dacă e). */
class GuestFeedbackDone extends Mailable
{
    public function __construct(public Task $task, public string $guestName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Done: :title', ['title' => $this->task->title]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.guest-done', text: 'mail.guest-done-text', with: [
            'name' => $this->guestName, 'title' => $this->task->title, 'project' => $this->task->project?->name,
            'proof' => $this->task->proof, 'url' => $this->task->site_context['url'] ?? null,
        ]);
    }
}
