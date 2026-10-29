<?php

namespace App\Support;

use App\Filament\Pages\OutsideAccess;
use App\Mail\AccessAlertMail;
use App\Models\AccessEvent;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paza accesului din afară: ce deschid clienții și colaboratorii (pagini, fișiere, agenți) se notează, iar fiecare deschidere și fiecare
 * trimitere spre ei (clopoțel, telefon, Telegram, email, rezumat, raport) trece printr-o a doua verificare, scrisă aici independent de
 * politici, ca să prindă și o greșeală din ele. Ce n-ar trebui să vadă: adminul află imediat (clopoțel și email), iar trimiterea nu pleacă.
 * Multe pagini „nu există" la rând de la același om (numere încercate) se anunță și ele. Echipa (admin, lider, membru) nu e urmărită.
 */
class AccessGuard
{
    public const TYPES = [Task::class => 'task', Update::class => 'update', Document::class => 'document', Attachment::class => 'file', Project::class => 'project'];

    /** Atâtea pagini „nu există" în 10 minute înseamnă că omul încearcă numere. */
    public const PROBES = 5;

    /** Cât se păstrează jurnalul. */
    public const KEEP_DAYS = 365;

    public static function watches(?User $user): bool
    {
        return $user !== null && ($user->isClient() || $user->isCollaborator());
    }

    /** De ce n-ar trebui omul să vadă lucrul (un cod din reasons()), sau null când are voie. A doua regulă, scrisă fără politici. */
    public static function breach(User $user, Model $subject): ?string
    {
        if (! $user->is_active) {
            return 'inactive';
        }
        if ($subject instanceof Update && $user->isCollaborator() && $subject->user_id === $user->id) {
            return null;                                                        // update-ul lui, chiar fără proiect
        }
        $project = match (true) {
            $subject instanceof Project => $subject,
            $subject instanceof Attachment && $subject->task_id !== null => $subject->task?->project,
            default => $subject->project,
        };
        if (! $project) {
            return 'no_project';
        }
        if ($user->isClient()) {
            return match (true) {
                ! $user->client_id || $project->client_id !== $user->client_id => 'other_client',
                ! $project->client_visible => 'not_shown',
                $subject instanceof Update && $subject->is_internal => 'internal',
                $subject instanceof Document && (! $subject->client_visible || $subject->archived_at !== null) => 'not_shared',
                $subject instanceof Attachment && $subject->task_id === null && ! $subject->client_visible => 'not_shared',
                default => null,
            };
        }
        $member = $project->lead_id === $user->id || DB::table('project_members')->where('project_id', $project->id)->where('user_id', $user->id)->exists();

        return $member ? null : 'not_member';
    }

    /** Omul din afară a deschis ceva (pagină, fișier, agent): se notează; dacă n-ar fi trebuit să-l vadă, adminul află. */
    public static function opened(?User $user, ?Model $subject, string $via): void
    {
        if (! self::watches($user) || ! $subject || ! isset(self::TYPES[$subject::class])) {
            return;
        }
        $reason = self::breach($user, $subject);
        if (! $reason && ! Cache::add('access:'.$user->id.':'.self::TYPES[$subject::class].':'.$subject->getKey().':'.$via, 1, 600)) {
            return;                                                             // aceeași pagină redeschisă în 10 minute: un singur rând
        }
        $event = self::log($user, $subject, $via, $reason ? 'breach' : 'ok', $reason);
        if ($reason) {
            self::alert($event);
        }
    }

    /**
     * Înainte de a trimite ceva unui om din afară: true = nu pleacă (n-ar trebui să vadă lucrul), iar adminul află.
     * $about e lucrul sau adresa lui din cemainou (…/tasks/12, …/projects/3/documents/4).
     */
    public static function blocks(User $person, Model|string|null $about, string $via): bool
    {
        if (! self::watches($person)) {
            return false;
        }
        $subject = is_string($about) ? self::subjectOf($about) : $about;
        if (! $subject || ! ($reason = self::breach($person, $subject))) {
            return false;
        }
        self::alert(self::log($person, $subject, $via, 'blocked', $reason));

        return true;
    }

    /** O pagină sau un lucru cerut care „nu există" pentru om; multe la rând se anunță (o dată pe oră). */
    public static function denied(?User $user, string $what, string $via): void
    {
        if (! self::watches($user)) {
            return;
        }
        $event = AccessEvent::create(['user_id' => $user->id, 'outcome' => 'denied', 'via' => $via, 'reason' => 'not_found', 'path' => Str::limit($what, 250, '')]);
        $recent = AccessEvent::where('user_id', $user->id)->where('outcome', 'denied')->where('created_at', '>=', now()->subMinutes(10))->count();
        if ($recent >= self::PROBES && Cache::add('access:probes:'.$user->id, 1, 3600)) {
            self::alert($event, $recent);
        }
    }

    /** Lucrul din cemainou la care duce o adresă (sarcină, update, document, fișier, proiect), sau null. */
    public static function subjectOf(string $url): ?Model
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        return match (true) {
            (bool) preg_match('#/projects/(\d+)/documents/(\d+)(?:/|$)#', $path, $m) => Document::find($m[2]),
            (bool) preg_match('#/tasks/(\d+)(?:/|$)#', $path, $m) => Task::find($m[1]),
            (bool) preg_match('#/updates/(\d+)(?:/|$)#', $path, $m) => Update::find($m[1]),
            (bool) preg_match('#/files/(\d+)(?:/|$)#', $path, $m) => Attachment::find($m[1]),
            (bool) preg_match('#/projects/(\d+)(?:/|$)#', $path, $m) => Project::find($m[1]),
            default => null,
        };
    }

    /** @return array<string, string> codul și textul (în engleză; se traduce la afișare) */
    public static function reasons(): array
    {
        return [
            'inactive' => 'the account is deactivated',
            'no_project' => 'it belongs to no project of theirs',
            'other_client' => 'a project of another client',
            'not_shown' => 'the project is not shown to the client',
            'internal' => 'an internal update',
            'not_shared' => 'not shared with the client',
            'not_member' => 'a project they are not on',
            'not_found' => 'does not exist for them',
        ];
    }

    /** @return array<string, string> */
    public static function vias(): array
    {
        return [
            'web' => 'page', 'file' => 'file', 'agent' => 'agent', 'notice' => 'notification',
            'email' => 'email', 'digest' => 'weekly digest', 'report' => 'monthly report',
        ];
    }

    /** „#12 Titlu", „update #5", numele documentului, al fișierului sau al proiectului. */
    public static function label(AccessEvent $event): string
    {
        if (! $event->subject_type) {
            return (string) $event->path;
        }
        $class = array_search($event->subject_type, self::TYPES, true);
        $subject = $class ? $class::find($event->subject_id) : null;

        return match ($event->subject_type) {
            'task' => '#'.$event->subject_id.($subject ? ' '.$subject->title : ''),
            'update' => __('update #:id', ['id' => $event->subject_id]),
            'document' => $subject?->title ?? __('document #:id', ['id' => $event->subject_id]),
            'file' => $subject ? ($subject->title ?: $subject->name ?: __('file #:id', ['id' => $event->subject_id])) : __('file #:id', ['id' => $event->subject_id]),
            default => $subject?->name ?? __('project #:id', ['id' => $event->subject_id]),
        };
    }

    private static function log(User $user, Model $subject, string $via, string $outcome, ?string $reason): AccessEvent
    {
        $project = $subject instanceof Project ? $subject->id : ($subject->project_id ?? null);

        return AccessEvent::create(['user_id' => $user->id, 'outcome' => $outcome, 'via' => $via, 'subject_type' => self::TYPES[$subject::class],
            'subject_id' => $subject->getKey(), 'project_id' => $project, 'reason' => $reason, 'path' => Str::limit((string) request()?->path(), 250, '')]);
    }

    /** Adminii activi: în clopoțel (și pe telefon, Telegram) și pe email; o dată pe zi pentru același om și același lucru. */
    private static function alert(AccessEvent $event, int $probes = 0): void
    {
        if (! $probes && ! Cache::add('access:alert:'.$event->user_id.':'.$event->outcome.':'.$event->subject_type.':'.$event->subject_id, 1, 86400)) {
            return;
        }
        $event->loadMissing(['user', 'project']);
        $url = OutsideAccess::getUrl();
        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            $locale = $admin->locale ?: config('app.locale');
            [$title, $body] = self::text($event, $probes, $locale);
            try {
                Inbox::notify($admin, $title, $body, $url, Heroicon::OutlinedLockClosed, $locale, 'access');
                if (TeamMail::ready()) {
                    Mail::to($admin)->locale($locale)->send(new AccessAlertMail($title, $body, $url));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @return array{0: string, 1: string} titlul și textul, în limba adminului */
    private static function text(AccessEvent $event, int $probes, string $locale): array
    {
        $name = $event->user?->name ?? __('Someone', [], $locale);
        if ($probes) {
            return [__(':name tried :count pages that do not exist', ['name' => $name, 'count' => $probes], $locale),
                __('In the last 10 minutes; perhaps guessing numbers. The last one: :what', ['what' => $event->path], $locale)];
        }
        $what = self::label($event).($event->project && $event->subject_type !== 'project' ? ' · '.$event->project->name : '');
        $why = __(self::reasons()[$event->reason] ?? (string) $event->reason, [], $locale);
        $via = __(self::vias()[$event->via] ?? $event->via, [], $locale);

        return $event->outcome === 'blocked'
            ? [__('Stopped: a :via to :name', ['via' => $via, 'name' => $name], $locale), __(':what: :why. It was not sent.', ['what' => $what, 'why' => $why], $locale)]
            : [__(':name opened something they should not see', ['name' => $name], $locale), __(':what: :why. Through: :via.', ['what' => $what, 'why' => $why, 'via' => $via], $locale)];
    }
}
