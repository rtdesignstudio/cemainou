<?php

namespace App\Mail;

use App\Models\MailOutbox;
use App\Models\User;
use App\Support\Markdown;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Emailul despre sarcini, grupat (App\Support\Outbox): tot ce s-a strâns pentru un om, pe sarcini - cine a adăugat, cine a terminat,
 * cine ce a scris. Cu un singur lucru arată ca un email obișnuit despre acea sarcină. În limba omului.
 *
 * @property Collection<int, Collection<int, MailOutbox>> $groups
 */
class NoticeDigestMail extends Mailable
{
    /** @param  Collection<int, Collection<int, MailOutbox>>  $groups  pe sarcini */
    public function __construct(public User $person, public Collection $groups)
    {
        $this->locale($person->locale ?: config('app.locale'));
    }

    public function envelope(): Envelope
    {
        $count = $this->groups->sum(fn (Collection $items) => $items->count());
        $first = $this->groups->first()->first();

        return new Envelope(subject: $count === 1 ? $this->line($first, subject: true) : ($this->groups->count() === 1
            ? __(':count updates on «:title»', ['count' => $count, 'title' => $first->task->title])
            : __(':count updates on :tasks tasks', ['count' => $count, 'tasks' => $this->groups->count()])));
    }

    public function content(): Content
    {
        $count = $this->groups->sum(fn (Collection $items) => $items->count());

        return new Content(view: 'mail.notice-digest', text: 'mail.notice-digest-text', with: [
            'heading' => $count === 1 ? $this->line($this->groups->first()->first()) : __('What happened on your tasks'),
            'tasks' => $this->groups->map(fn (Collection $items) => [
                'title' => $items->first()->task->title,
                'project' => $items->first()->task->project?->name,
                'url' => $items->first()->url,
                'lines' => $items->map(fn (MailOutbox $row) => [
                    'what' => $this->line($row),
                    'text' => $row->kind === 'commented' ? Markdown::plain((string) $row->comment?->body) : ($row->kind === 'added' ? Markdown::plain((string) $row->task->notes) : ''),
                ])->all(),
            ])->all(),
            'profileUrl' => filament()->getPanel('admin')->getProfileUrl(),
        ]);
    }

    private function line(MailOutbox $row, bool $subject = false): string
    {
        $name = $row->actor?->name ?? __('Someone');
        $title = $row->task->title;

        return $subject ? match ($row->kind) {
            'added' => __('New task on :project: :title', ['project' => $row->task->project?->name, 'title' => $title]),
            'done' => __('Done: :title', ['title' => $title]),
            default => __('New comment on :title', ['title' => $title]),
        } : match ($row->kind) {
            'added' => __(':name added a task on :project', ['name' => $name, 'project' => $row->task->project?->name]),
            'done' => __(':name finished a task you asked for', ['name' => $name]),
            default => __(':name wrote on a task', ['name' => $name]),
        };
    }
}
