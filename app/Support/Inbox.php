<?php

namespace App\Support;

use App\Enums\TaskStatus;
use App\Filament\Pages\Notifications;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Feedback;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\UpdateComment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Clopoțelul din bara de sus (notificările Filament din baza de date): ce te privește, chiar fără email - o sarcină primită de la altcineva,
 * o sarcină nouă de la client (la liderul proiectului), o sarcină a ta sau cerută de tine terminată de altcineva, un comentariu pe o sarcină
 * la care ești (a ta, scrisă de tine sau unde ai scris), un comentariu la update-ul tău. Niciodată celui care a făcut lucrul; doar celor activi care văd; în limba fiecăruia.
 */
class Inbox
{
    /**
     * O sarcină nouă: omului căruia i s-a dat (dacă n-a scris-o el) și, de la client, liderului proiectului (fără lider, adminilor).
     * Cererea unui client ajunge de obicei chiar la lider: atunci primește o singură notificare, „de la client", nu și „ți-a dat o sarcină".
     */
    public static function added(Task $task, User $by): void
    {
        $task->loadMissing(['assignee', 'creator']);
        $leads = $task->fromClient()
            ? ($task->loadMissing('project.lead')->project->lead ? collect([$task->project->lead]) : User::where('role', 'admin')->get())
            : collect();
        if (! $leads->contains('id', $task->assignee_id)) {
            self::assigned($task, $by);
        }
        if ($leads->isNotEmpty()) {
            self::send($task, $leads, $by, 'added');
        }
    }

    public static function assigned(Task $task, User $by): void
    {
        if ($task->assignee_id && $task->assignee_id !== $by->id) {
            self::send($task, collect([$task->loadMissing('assignee')->assignee]), $by, 'assigned');
        }
    }

    /** Sarcina cuiva, preluată sau dată altcuiva de un coleg (liderul a uitat, omul lipsește): omul care o avea află. */
    public static function handedOver(Task $task, ?User $previous, User $by): void
    {
        if ($previous && ! $previous->is($by) && $previous->id !== $task->assignee_id) {
            self::send($task, collect([$previous]), $by, $task->assignee_id === $by->id ? 'taken' : 'reassigned');
        }
    }

    /** Gata: celui care a cerut-o și omului ei, dacă n-au terminat-o ei. */
    public static function done(Task $task, User $by): void
    {
        self::send($task, collect([$task->loadMissing('creator')->creator, $task->loadMissing('assignee')->assignee]), $by, 'done');
    }

    public static function commented(TaskComment $comment): void
    {
        $comment->loadMissing(['task.assignee', 'task.creator', 'author']);
        $task = $comment->task;
        $mentioned = Mentions::in($comment->body, $comment->author)->filter(fn (User $person) => Task::visibleTo($person)->whereKey($task->id)->exists());
        self::mentioned($mentioned, $comment->author, '#'.$task->id.' '.$task->title.' · '.$task->project?->name, $comment->body, TaskResource::getUrl('view', ['record' => $task]).'#comments');
        $people = collect([$task->assignee, $task->creator])->concat(User::whereIn('id', $task->comments()->select('user_id'))->get())
            ->reject(fn (?User $person) => $person && $mentioned->contains('id', $person->id));
        self::send($task, $people, $comment->author, 'commented', $comment);
    }

    /** Un comentariu la un update: la autorul lui și la cei din echipă care au mai scris acolo (clientul își vede doar comentariile lui: nu e anunțat). */
    public static function updateCommented(UpdateComment $comment): void
    {
        $comment->loadMissing(['statusUpdate.user', 'statusUpdate.project', 'author']);
        $update = $comment->statusUpdate;
        $mentioned = Mentions::in($comment->body, $comment->author)->filter(fn (User $person) => $person->can('view', $update));   // doar cine vede update-ul
        self::mentioned($mentioned, $comment->author, Str::limit(Markdown::plain($update->summary), 90).($update->project ? ' · '.$update->project->name : ''),
            $comment->body, UpdateResource::getUrl('view', ['record' => $update]).'#comments');
        $people = collect([$update->user])->concat(User::whereIn('id', $update->comments()->select('user_id'))->get())->filter()->unique('id')
            ->reject(fn (User $person) => $person->isClient() || $person->is($comment->author) || ! $person->is_active || $mentioned->contains('id', $person->id));
        $by = $comment->author;
        foreach ($people as $person) {
            $locale = $person->locale ?: config('app.locale');
            $name = $by?->name ?? __('Someone', [], $locale);
            self::notify($person,
                $person->is($update->user) ? __(':name commented on your update', ['name' => $name], $locale) : __(':name wrote on an update', ['name' => $name], $locale),
                Str::limit(Markdown::plain($update->summary), 90).($update->project ? ' · '.$update->project->name : '').': «'.Str::limit(Markdown::plain($comment->body), 140).'»',
                UpdateResource::getUrl('view', ['record' => $update]).'#comments', Heroicon::OutlinedChatBubbleLeft, $locale, 'comments', "update:{$update->id}:comments", $name);
        }
    }

    /**
     * @menționat: „:name te-a menționat", cu unde și fragmentul. Doar oamenii activi din echipă (Mentions), niciodată autorul.
     *
     * @param  Collection<int, User>  $people
     */
    public static function mentioned(Collection $people, ?User $by, string $where, string $text, string $url): void
    {
        foreach ($people as $person) {
            if (! $person->is_active || ($by && $person->is($by))) {
                continue;
            }
            $locale = $person->locale ?: config('app.locale');
            self::notify($person, __(':name mentioned you', ['name' => $by?->name ?? __('Someone', [], $locale)], $locale),
                $where.': «'.Str::limit(Markdown::plain($text), 160).'»', $url, Heroicon::OutlinedAtSymbol, $locale, 'mentions');
        }
    }

    /** Un feedback nou: la admini (nu și la cel care l-a trimis). */
    public static function feedback(Feedback $feedback): void
    {
        $feedback->loadMissing('author');
        foreach (User::where('role', 'admin')->where('is_active', true)->when($feedback->user_id, fn ($q) => $q->whereKeyNot($feedback->user_id))->get() as $admin) {
            $locale = $admin->locale ?: config('app.locale');
            self::notify($admin, __(':name sent feedback', ['name' => $feedback->author?->name ?? ($feedback->installation ? __('A cemainou installation', [], $locale) : __('Someone', [], $locale))], $locale),
                Str::limit(Markdown::plain($feedback->body), 160), FeedbackResource::getUrl('view', ['record' => $feedback]), Heroicon::OutlinedMegaphone, $locale, 'feedback');
        }
    }

    /** Rezolvat: autorul află (dacă nu l-a rezolvat el). */
    public static function feedbackResolved(Feedback $feedback, User $by): void
    {
        $author = $feedback->loadMissing('author')->author;
        if (! $author || $author->is($by) || ! $author->is_active) {
            return;
        }
        $locale = $author->locale ?: config('app.locale');
        self::notify($author, __('Your feedback was resolved', [], $locale),
            Str::limit(Markdown::plain($feedback->body), 100).($feedback->resolution ? ': «'.Str::limit(Markdown::plain($feedback->resolution), 140).'»' : ''),
            FeedbackResource::getUrl('view', ['record' => $feedback]), Heroicon::OutlinedCheck, $locale, 'feedback');   // la feedback-ul lui, cu tot ce s-a făcut (pagina de unde l-a trimis era adesea chiar cea pe care stă)
    }

    private static function send(Task $task, Collection $people, ?User $by, string $kind, ?TaskComment $comment = null): void
    {
        $task->loadMissing('project');
        $people->filter()->unique('id')
            ->reject(fn (User $person) => $person->is($by) || ! $person->is_active)
            ->filter(fn (User $person) => Task::visibleTo($person)->whereKey($task->id)->exists())
            ->each(function (User $person) use ($task, $by, $kind, $comment) {
                $locale = $person->locale ?: config('app.locale');
                $name = $by?->name ?? __('Someone', [], $locale);
                // clientul a răspuns la o sarcină blocată: cine lucrează la ea află și poate debloca dintr-un clic (pagina sarcinii, „Deblochezi?")
                $answer = $kind === 'commented' && $by?->isClient() && $task->status === TaskStatus::Blocked && ! $person->isClient();
                [$title, $icon] = $answer ? [__(':name answered · unblock it?', ['name' => $name], $locale), Heroicon::OutlinedLockOpen] : match ($kind) {
                    'assigned' => [__(':name gave you a task', ['name' => $name], $locale), Heroicon::OutlinedArrowDownTray],
                    'added' => [__(':name, from the client, added a task', ['name' => $name], $locale), Heroicon::OutlinedPlus],
                    'done' => [__(':name finished a task', ['name' => $name], $locale), Heroicon::OutlinedCheck],
                    'taken' => [__(':name took over your task', ['name' => $name], $locale), Heroicon::OutlinedArrowsRightLeft],
                    'reassigned' => [__(':name gave your task to :other', ['name' => $name, 'other' => $task->loadMissing('assignee')->assignee?->name ?? __('nobody', [], $locale)], $locale), Heroicon::OutlinedArrowsRightLeft],
                    'commented' => [__(':name wrote on a task', ['name' => $name], $locale), Heroicon::OutlinedChatBubbleLeft],
                };
                self::notify($person, $title, '#'.$task->id.' '.$task->title.' · '.$task->project->name.($comment ? ': «'.Str::limit(Markdown::plain($comment->body), 140).'»' : ''),
                    TaskResource::getUrl('view', ['record' => $task]).($answer ? '?unblock=1' : '').($comment ? '#comments' : ''), $icon, $locale, match ($kind) {
                        'done' => 'done', 'commented' => 'comments', default => 'tasks'
                    }, $kind === 'commented' ? "task:{$task->id}:comments" : null, $name);
            });
    }

    /**
     * În clopoțel și pe telefon sau calculator (dacă omul le-a pornit), fiecare doar dacă omul n-a oprit tipul acesta pe canalul acela (NoticePrefs).
     * Fiecare notificare își ține felul și adresa (pentru pagina Notificări). Cu $group (ex. comentariile pe aceeași sarcină), una necitită de același fel
     * din ultimele 2 zile se completează - „3 comentarii noi · Andrei, Mihai" - în loc de un rând nou.
     */
    public static function notify(User $person, string $title, string $body, string $url, Heroicon $icon, string $locale, string $type, ?string $group = null, ?string $by = null): void
    {
        if (AccessGuard::blocks($person, $url, 'notice')) {
            return;                                                             // cuiva din afară, despre ce n-ar trebui să vadă: nu pleacă pe niciun canal
        }
        if (NoticePrefs::wants($person, $type, 'bell')) {
            $meta = ['type' => $type, 'url' => $url, 'group' => $group, 'by' => array_values(array_filter([$by])), 'count' => 1];
            $open = $group ? $person->unreadNotifications()->where('created_at', '>=', now()->subDays(2))->where('data', 'like', '%"group":"'.$group.'"%')->latest()->first() : null;
            if ($open) {
                $data = $open->data;
                $old = $data['viewData']['wsa'] ?? $meta;
                $meta['count'] = (int) ($old['count'] ?? 1) + 1;
                $meta['by'] = array_values(array_unique(array_merge($old['by'] ?? [], $meta['by'])));
                $data['title'] = trans_choice(':count new comment · :names|:count new comments · :names', $meta['count'], ['names' => implode(', ', array_slice($meta['by'], 0, 3))], $locale);
                $data['body'] = $body;
                $data['viewData'] = ['wsa' => $meta];
                $open->forceFill(['data' => $data, 'created_at' => now()])->save();
            } else {
                Notification::make()->title($title)->body($body)->icon($icon)->viewData(['wsa' => $meta])
                    ->actions([Action::make('open')->label(__('Open it', [], $locale))->url($url)->markAsRead(),
                        Action::make('read')->label(__('Mark as read', [], $locale))->color('gray')->markAsRead()])
                    ->sendToDatabase($person);
            }
        }
        if (NoticePrefs::wants($person, $type, 'push')) {
            WebPush::send($person, $title, $body, $url);
        }
        if ($person->telegram_chat_id && NoticePrefs::wants($person, $type, 'telegram')) {
            Telegram::notify($person, $title, $body, $url);                      // în privat, la cine și-a legat contul
        }
    }

    /**
     * Ai deschis pagina la care se referă notificările tale (o sarcină, un update, un proiect): cele necitite despre ea devin citite,
     * ca în clopoțel să rămână doar ce n-ai văzut. Se compară adresa fără ancoră și fără parametri (…/tasks/12#comments = …/tasks/12).
     */
    public static function readAbout(User $user, string $pageUrl): int
    {
        $page = self::bare($pageUrl);
        $ids = $user->unreadNotifications()->latest()->limit(300)->get()
            ->filter(fn ($n) => ($url = Notifications::urlOf($n)) && self::bare($url) === $page)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return $user->notifications()->whereIn('id', $ids)->update(['read_at' => now()]);
    }

    private static function bare(string $url): string
    {
        return rtrim(strtok(strtok($url, '#'), '?'), '/');
    }
}
