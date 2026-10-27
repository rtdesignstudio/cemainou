<?php

namespace App\Filament\Pages;

use App\Support\NoticePrefs;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Centrul de notificări: toată istoria (90 de zile), pe zile - necitite sau toate, pe feluri, cu căutare; un clic o marchează citită și deschide locul ei.
 * Jos, ce primești, pe feluri (clopoțel și telefon). Clopoțelul arată doar ultimele 10 și duce aici.
 */
class Notifications extends Page
{
    public const KEEP_DAYS = 90;

    protected string $view = 'filament.pages.notifications';

    protected static ?string $slug = 'notifications';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'show')]
    public string $show = 'all';

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'q')]
    public string $find = '';

    public int $limit = 40;

    public function getTitle(): string|Htmlable
    {
        return __('Notifications');
    }

    public function getSubheading(): ?string
    {
        return __('Everything that reached your bell in the last :days days. A click opens it and marks it read.', ['days' => self::KEEP_DAYS]);
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['show', 'type', 'find'], true)) {
            $this->limit = 40;
        }
    }

    public function more(): void
    {
        $this->limit += 40;
    }

    /** Deschide notificarea: citită, apoi locul ei. */
    public function open(string $id): void
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $this->redirect(self::urlOf($notification) ?? Dashboard::getUrl());
    }

    public function markRead(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->update(['read_at' => now()]);
    }

    public function markUnread(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->update(['read_at' => null]);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public static function urlOf(DatabaseNotification $n): ?string
    {
        return $n->data['viewData']['wsa']['url'] ?? collect($n->data['actions'] ?? [])->pluck('url')->filter()->first();
    }

    /** Felul notificării: cel ținut la trimitere sau, la cele vechi, ghicit după iconiță. */
    public static function typeOf(DatabaseNotification $n): string
    {
        return $n->data['viewData']['wsa']['type'] ?? match (true) {
            str_contains((string) ($n->data['icon'] ?? ''), 'chat-bubble') => 'comments',
            str_contains((string) ($n->data['icon'] ?? ''), 'at-symbol') => 'mentions',
            str_contains((string) ($n->data['icon'] ?? ''), 'megaphone') => 'feedback',
            str_contains((string) ($n->data['icon'] ?? ''), 'sun') => 'morning',
            str_contains((string) ($n->data['icon'] ?? ''), 'check') => 'done',
            default => 'tasks',
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $tz = $user->tz();
        $types = NoticePrefs::types($user);
        $query = $user->notifications()
            ->when($this->show === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(trim($this->find) !== '', fn ($q) => $q->where('data', 'like', '%'.trim($this->find).'%'));
        $rows = $query->latest()->limit(500)->get()
            ->when($this->type !== '', fn (Collection $c) => $c->filter(fn (DatabaseNotification $n) => self::typeOf($n) === $this->type));
        $page = $rows->take($this->limit);

        return [
            'days' => $page->groupBy(fn (DatabaseNotification $n) => $n->created_at->copy()->setTimezone($tz)->toDateString()),
            'more' => $rows->count() > $this->limit,
            'types' => $types,
            'unread' => $user->unreadNotifications()->count(),
            'total' => $user->notifications()->count(),
            'dayLabel' => fn (string $date) => ucfirst(Carbon::parse($date)->locale(app()->getLocale())->isoFormat('dddd, D MMMM')),
        ];
    }
}
