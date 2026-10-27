<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Canalele echipei: fiecare update publicat ajunge în Slack (webhook) și / sau Telegram (bot + chat) - ale proiectului, dacă are, altfel ale
 * echipei, din Setări. Update-urile interne nu pleacă (un canal poate fi comun cu clientul). Trimiterea e după răspuns și nu strică nimic dacă pică.
 * Tot aici, dacă sunt pornite în Setări: o sarcină închisă sau blocată (în afara unui update, care le spune oricum), iar în canalul echipei
 * rezumatul de dimineață (zilele lucrătoare, după 9) și săptămâna echipei (vinerea, după 15), în fusul și limba echipei.
 * În Telegram, fiecare mesaj despre un update sau o sarcină se ține minte: un răspuns la el devine comentariu (Telegram::handle).
 */
class Channels
{
    /** După ce răspunsul a plecat la om (defer): un update nou, cu câte zile de dinainte a mai raportat odată. */
    public static function announceLater(Update $update, int $earlierDays = 0): void
    {
        if ($update->is_internal || ! self::targets($update->project)) {
            return;
        }
        defer(fn () => self::announce($update->fresh(['user', 'project', 'items']), $earlierDays));
    }

    public static function announce(Update $update, int $earlierDays = 0): void
    {
        $targets = self::targets($update->project);
        try {
            if (isset($targets['slack'])) {
                self::slack($targets['slack'], self::slackText($update, $earlierDays));
            }
            if (isset($targets['telegram'])) {
                Telegram::send($targets['telegram'][1], self::telegramText($update, $earlierDays), ['update', $update->id]);
            }
        } catch (Throwable $e) {
            report($e);                                                         // un canal căzut nu oprește nimic
        }
    }

    /** O sarcină închisă sau blocată, în canalul proiectului (sau al echipei), după răspuns; nu cea mișcată dintr-un update (o spune update-ul). */
    public static function taskLater(Task $task, User $by): void
    {
        if (! Settings::get('channel_tasks') || ! in_array($task->status, [TaskStatus::Done, TaskStatus::Blocked], true) || ! self::targets($task->project)) {
            return;
        }
        $taskId = $task->id;
        $byName = $by->name;
        defer(function () use ($taskId, $byName) {
            $task = Task::with('project')->find($taskId);
            if (! $task) {
                return;
            }
            $targets = self::targets($task->project);
            $locale = (string) Settings::get('locale');
            $title = '#'.$task->id.' '.Markdown::plain($task->title);
            $line = $task->status === TaskStatus::Done
                ? __('✓ :task · :project, closed by :name', ['task' => $title, 'project' => $task->project?->name, 'name' => $byName], $locale)
                : __('■ :task · :project is blocked: :reason (:name)', ['task' => $title, 'project' => $task->project?->name, 'reason' => $task->blocked_reason ?: __('no reason given', [], $locale), 'name' => $byName], $locale);
            $url = TaskResource::getUrl('view', ['record' => $task], panel: 'admin');
            try {
                if (isset($targets['slack'])) {
                    self::slack($targets['slack'], str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $line)."\n<{$url}|".__('Open the task', [], $locale).'>');
                }
                if (isset($targets['telegram'])) {
                    Telegram::send($targets['telegram'][1], e($line)."\n".'<a href="'.e($url).'">'.e(__('Open the task', [], $locale)).'</a>', ['task', $task->id]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Rezumatul de dimineață și săptămâna, în canalul echipei: o dată pe zi, la prima cerere de după oră (fără cron). */
    public static function digestsIfDue(): void
    {
        if (! self::targets(null) || ! Cache::add('cemainou.channels.check', 1, 300)) {
            return;
        }
        $now = now((string) Settings::get('timezone'));
        if (Settings::get('channel_morning') && $now->isWeekday() && $now->hour >= 9 && $now->hour < 12 && Once::claim('channel-morning', $now->toDateString())) {
            self::post(self::morningLines($now));
        }
        if (Settings::get('channel_week') && $now->isFriday() && $now->hour >= 15 && Once::claim('channel-week', $now->toDateString())) {
            self::post(self::weekLines($now));
        }
    }

    /**
     * Dimineața, pentru echipă: ce s-a făcut ieri (update-uri, oameni, sarcini închise), blocajele de acum, întârziatele și proiectele tăcute.
     * Gol dacă n-a fost nimic și nu e nimic de spus.
     *
     * @return list<string>
     */
    public static function morningLines(CarbonInterface $now): array
    {
        $locale = (string) Settings::get('locale');
        $yesterday = $now->copy()->subDay();
        $range = [$yesterday->copy()->startOfDay()->utc(), $yesterday->copy()->endOfDay()->utc()];
        $updates = Update::where('is_internal', false)->whereBetween('created_at', $range);
        $count = (clone $updates)->count();
        $people = (clone $updates)->distinct()->count('user_id');
        $done = Task::where('status', TaskStatus::Done->value)->whereBetween('done_at', $range)->count();
        $blocked = Task::with('project')->where('status', TaskStatus::Blocked->value)->latest('updated_at')->get();
        $late = Task::open()->whereNotNull('due_on')->where('due_on', '<', $now->toDateString())->count();
        $quiet = Project::active()->where('created_at', '<', now()->subDays(QuietProjects::QUIET))->get()
            ->filter(fn (Project $p) => ($last = QuietProjects::lastSign($p)) === null || $last->lt(now()->subDays(QuietProjects::QUIET)))->count();
        if ($count + $done + $blocked->count() + $late === 0) {
            return [];
        }

        return array_values(array_filter([
            '*'.__('Good morning, team', [], $locale).'*',
            $count || $done ? __('Yesterday: :updates from :people, :done.', ['updates' => trans_choice(':count update|:count updates', $count, [], $locale), 'people' => trans_choice(':count person|:count people', $people, [], $locale), 'done' => trans_choice(':count task finished|:count tasks finished', $done, [], $locale)], $locale) : null,
            $blocked->isNotEmpty() ? __('Blocked now: :list', ['list' => $blocked->take(4)->map(fn (Task $t) => '#'.$t->id.' '.Str::limit(Markdown::plain($t->title), 40).($t->project ? ' ('.$t->project->name.')' : ''))->join('; ').($blocked->count() > 4 ? '…' : '')], $locale) : null,
            $late ? trans_choice('{1} :count task past its due date|[2,*] :count tasks past their due date', $late, [], $locale) : null,
            $quiet ? trans_choice('{1} :count quiet project for 14+ days|[2,*] :count quiet projects for 14+ days', $quiet, [], $locale) : null,
        ]));
    }

    /**
     * Vinerea, pentru echipă: sarcinile închise, orele, update-urile și oamenii săptămânii, plus proiectele cu cel mai mult timp.
     *
     * @return list<string>
     */
    public static function weekLines(CarbonInterface $now): array
    {
        $locale = (string) Settings::get('locale');
        $monday = $now->copy()->startOfWeek();
        $since = $monday->copy()->utc();
        $rows = Hours::between(Project::pluck('id'), $monday->toDateString(), $now->toDateString());
        $minutes = (int) $rows->sum(fn (array $r) => $r['measured'] + $r['added'] + $r['estimated']);
        $done = Task::where('status', TaskStatus::Done->value)->where('done_at', '>=', $since)->count();
        $updates = Update::where('is_internal', false)->where('created_at', '>=', $since);
        $count = (clone $updates)->count();
        $people = $rows->pluck('user_id')->concat((clone $updates)->pluck('user_id'))->unique()->count();
        if ($minutes + $done + $count === 0) {
            return [];
        }
        $names = Project::pluck('name', 'id');
        $top = $rows->groupBy('project_id')->map(fn ($r) => (int) $r->sum(fn (array $x) => $x['measured'] + $x['added'] + $x['estimated']))->filter()->sortDesc()->take(3)
            ->map(fn (int $m, int $id) => ($names[$id] ?? '?').' ('.WorkTime::format($m).')')->values();

        return array_values(array_filter([
            '*'.__('The team\'s week', [], $locale).'*',
            __(':done, :time of work, :updates, :people.', ['done' => trans_choice(':count task finished|:count tasks finished', $done, [], $locale), 'time' => WorkTime::format($minutes),
                'updates' => trans_choice(':count update|:count updates', $count, [], $locale), 'people' => trans_choice(':count person|:count people', $people, [], $locale)], $locale),
            $top->isNotEmpty() ? __('Most time: :list.', ['list' => $top->join(', ')], $locale) : null,
            __('Have a good weekend.', [], $locale),
        ]));
    }

    /** Rândurile (primul, între *, e titlul) în canalul echipei: Slack cu *bold*, Telegram cu <b>. @param  list<string>  $lines */
    private static function post(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $targets = self::targets(null);
        $url = Dashboard::getUrl(panel: 'admin');
        $locale = (string) Settings::get('locale');
        try {
            if (isset($targets['slack'])) {
                self::slack($targets['slack'], implode("\n", array_map(fn (string $l) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $l), $lines))."\n<{$url}|".__('Open cemainou', [], $locale).'>');
            }
            if (isset($targets['telegram'])) {
                $html = array_map(fn (string $l) => preg_match('/^\*(.+)\*$/u', $l, $m) ? '<b>'.e($m[1]).'</b>' : e($l), $lines);
                Telegram::send($targets['telegram'][1], implode("\n", $html)."\n".'<a href="'.e($url).'">'.e(__('Open cemainou', [], $locale)).'</a>');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{slack?: string, telegram?: array{0: string, 1: string}} */
    public static function targets(?Project $project): array
    {
        $slack = $project?->notify_slack_webhook ?: Settings::get('slack_webhook');
        $token = Settings::get('telegram_bot_token');
        $chat = $project?->notify_telegram_chat ?: Settings::get('telegram_chat_id');

        return array_filter(['slack' => $slack ?: null, 'telegram' => $token && $chat ? [$token, (string) $chat] : null]);
    }

    /** Un mesaj de test pe canalele echipei: null dacă a mers, altfel motivul. */
    public static function test(): ?string
    {
        $targets = self::targets(null);
        if (! $targets) {
            return __('No channel is set up: add a Slack webhook or a Telegram bot and chat.');
        }
        try {
            $text = __('cemainou is connected: new updates will show up here.');
            if (isset($targets['slack'])) {
                self::slack($targets['slack'], $text);
            }
            if (isset($targets['telegram'])) {
                Telegram::send($targets['telegram'][1], e($text));
            }

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function slack(string $webhook, string $text): void
    {
        if (! str_starts_with($webhook, 'https://hooks.slack.com/')) {                  // doar Slack: niciun POST spre adrese interne
            return;
        }
        Http::timeout(5)->withoutRedirecting()->post($webhook, ['text' => $text, 'unfurl_links' => false])->throw();
    }

    /** @return array{0: string, 1: list<string>, 2: list<string>, 3: string} titlul, făcute, blocaje, linkul */
    private static function parts(Update $update): array
    {
        return [
            ($update->user?->name ?? __('Someone')).($update->project ? ' · '.$update->project->name : ''),
            $update->items->where('type', UpdateItemType::Done)->take(5)->map(fn ($i) => Markdown::plain($i->text))->values()->all(),
            $update->items->where('type', UpdateItemType::Blocked)->take(3)->map(fn ($i) => Markdown::plain($i->text))->values()->all(),
            UpdateResource::getUrl('view', ['record' => $update], panel: 'admin'),
        ];
    }

    private static function slackText(Update $update, int $earlierDays): string
    {
        $esc = fn (string $s) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
        [$title, $done, $blocked, $url] = self::parts($update);
        $lines = ['*'.$esc($title).'*', $esc(Markdown::plain($update->summary))];
        foreach ($done as $line) {
            $lines[] = '✓ '.$esc($line);
        }
        foreach ($blocked as $line) {
            $lines[] = '■ '.$esc($line);
        }
        if ($earlierDays) {
            $lines[] = '_'.$esc(trans_choice('plus :count earlier day|plus :count earlier days', $earlierDays, ['count' => $earlierDays])).'_';
        }

        return implode("\n", $lines)."\n<{$url}|".$esc(__('Open the update')).'>';
    }

    private static function telegramText(Update $update, int $earlierDays): string
    {
        [$title, $done, $blocked, $url] = self::parts($update);
        $lines = ['<b>'.e($title).'</b>', e(Markdown::plain($update->summary))];
        foreach ($done as $line) {
            $lines[] = '✓ '.e($line);
        }
        foreach ($blocked as $line) {
            $lines[] = '■ '.e($line);
        }
        if ($earlierDays) {
            $lines[] = '<i>'.e(trans_choice('plus :count earlier day|plus :count earlier days', $earlierDays, ['count' => $earlierDays])).'</i>';
        }

        return implode("\n", $lines)."\n".'<a href="'.e($url).'">'.e(__('Open the update')).'</a>';
    }
}
