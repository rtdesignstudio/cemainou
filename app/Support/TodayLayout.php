<?php

namespace App\Support;

use App\Filament\Widgets\Blockers;
use App\Filament\Widgets\ClientConnectAgent;
use App\Filament\Widgets\ClientDoneWeek;
use App\Filament\Widgets\ClientNext;
use App\Filament\Widgets\ClientPeopleCard;
use App\Filament\Widgets\ClientRequests;
use App\Filament\Widgets\ClientShared;
use App\Filament\Widgets\ClientSiteFeedback;
use App\Filament\Widgets\ClientWaiting;
use App\Filament\Widgets\Deadlines;
use App\Filament\Widgets\DidYouKnow;
use App\Filament\Widgets\FollowedProjects;
use App\Filament\Widgets\LatestUpdates;
use App\Filament\Widgets\LateTasks;
use App\Filament\Widgets\MyDayCard;
use App\Filament\Widgets\MyTasks;
use App\Filament\Widgets\MyWeekCard;
use App\Filament\Widgets\NewComments;
use App\Filament\Widgets\ProjectCards;
use App\Filament\Widgets\RadarSignals;
use App\Filament\Widgets\RecentNotes;
use App\Filament\Widgets\TeamToday;
use App\Models\User;

/**
 * Pagina Azi, așezată de fiecare om: ordinea secțiunilor, care se văd și cât de late sunt (întreagă / jumătate), în users.today_layout.
 * O secțiune nouă (dintr-o versiune nouă) apare la sfârșit, cu felul ei implicit; una pe care omul n-o poate vedea (canView) nu apare deloc.
 */
class TodayLayout
{
    /** cheie => [widget, lățimea implicită, se vede implicit]; ordinea de aici e cea implicită */
    public const SECTIONS = [
        'late' => [LateTasks::class, 'full', true],
        'team' => [TeamToday::class, 'full', true],
        'clients' => [ClientPeopleCard::class, 'full', true],                // oamenii clienților: ce așteaptă, ce au făcut
        'projects' => [ProjectCards::class, 'full', true],
        'updates' => [LatestUpdates::class, 'half', true],
        'my-tasks' => [MyTasks::class, 'half', true],
        'blockers' => [Blockers::class, 'full', true],
        'radar' => [RadarSignals::class, 'full', true],                   // doar pentru lideri, când e ceva
        'tips' => [DidYouKnow::class, 'full', true],
        // de adăugat din „Personalizează" (ascunse până le alege omul)
        'deadlines' => [Deadlines::class, 'half', false],
        'comments' => [NewComments::class, 'half', false],
        'followed' => [FollowedProjects::class, 'half', false],
        'notes' => [RecentNotes::class, 'half', false],
        'my-day' => [MyDayCard::class, 'full', true],
        'my-week' => [MyWeekCard::class, 'half', false],
    ];

    /**
     * Pagina Azi a clientului: feedback-ul pe site (dacă proiectul are widget-ul), ce așteaptă de la el, cererile lui, agentul (până îl
     * conectează sau îl închide), ce s-a făcut, ce urmează, ce i s-a arătat, proiectele, comentariile.
     */
    public const CLIENT_SECTIONS = [
        'site-feedback' => [ClientSiteFeedback::class, 'full', true],
        'waiting' => [ClientWaiting::class, 'half', true],
        'requests' => [ClientRequests::class, 'half', true],
        'agent' => [ClientConnectAgent::class, 'full', true],
        'done-week' => [ClientDoneWeek::class, 'full', true],
        'next' => [ClientNext::class, 'half', true],
        'shared' => [ClientShared::class, 'half', true],
        'projects' => [ProjectCards::class, 'full', true],
        'comments' => [NewComments::class, 'half', true],
        'updates' => [LatestUpdates::class, 'half', false],
    ];

    /** @return array<string, array{0: class-string, 1: string, 2: bool}> secțiunile omului: ale clientului sau ale echipei */
    public static function sections(User $user): array
    {
        return $user->isClient() ? self::CLIENT_SECTIONS : self::SECTIONS;
    }

    /** Așezări gata făcute, ca punct de pornire: ce se vede, în ordine, cu lățimea (restul se ascund). */
    public const PRESETS = [
        'lead' => [['blockers', 'half'], ['late', 'half'], ['team', 'full'], ['followed', 'half'], ['updates', 'half'], ['projects', 'full']],
        'focus' => [['my-tasks', 'half'], ['deadlines', 'half'], ['comments', 'half'], ['my-week', 'half'], ['late', 'full']],
        'minimal' => [['my-tasks', 'full'], ['late', 'full']],
    ];

    public static function applyPreset(User $user, string $preset): void
    {
        $chosen = collect(self::PRESETS[$preset] ?? [])->map(fn (array $p) => ['key' => $p[0], 'span' => $p[1], 'visible' => true]);
        $sections = self::sections($user);
        $rest = collect(array_keys($sections))->diff($chosen->pluck('key'))->map(fn (string $key) => ['key' => $key, 'span' => $sections[$key][1], 'visible' => false]);
        self::save($user, $chosen->concat($rest)->values()->all());
    }

    /** Numele și rostul fiecărei secțiuni, pentru „Personalizează". @return array{0: string, 1: string} */
    public static function describe(string $key): array
    {
        return match ($key) {
            'late' => [__('Late'), __('Tasks past their due date, by person.')],
            'team' => [__('Team today'), __('What everyone is working on now.')],
            'clients' => [__('Clients'), __('The clients\' people: what waits for us, what waits for them, what they did lately.')],
            'projects' => [__('Projects'), __('The active projects and where they are.')],
            'updates' => [__('Latest updates'), __('What colleagues reported.')],
            'my-tasks' => [__('My tasks'), __('What is on you.')],
            'blockers' => [__('Blockers'), __('What is waiting for someone.')],
            'radar' => [__('Radar'), __('For leads: clients waiting, deadlines with nothing moving, done without proof.')],
            'tips' => [__('Did you know'), __('A tip about cemainou.')],
            'deadlines' => [__('Due this week'), __('Your tasks and the free ones due by Sunday.')],
            'comments' => [__('New comments for me'), __('On your tasks and updates, this week.')],
            'followed' => [__('Projects I follow'), __('Open and blocked tasks, the last update.')],
            'notes' => [__('Recent notes'), __('What colleagues noted for the team.')],
            'my-day' => [__('My day'), __('Today by the hour, the day\'s figures and what you finished.')],
            'my-week' => [__('My week'), __('Active time, finished tasks and projects since Monday.')],
            'site-feedback' => [__('Feedback on the site'), __('The Feedback button on your site, and what you sent from there.')],
            'agent' => [__('Connect your agent'), __('The steps for Claude Code and the Claude app, until you connect one.')],
            'waiting' => [__('Waiting for you'), __('The tasks the team gave you.')],
            'requests' => [__('Your requests'), __('What you asked for, and where it is.')],
            'done-week' => [__('Done this week'), __('Finished tasks and the team\'s updates.')],
            'next' => [__('Coming next'), __('The next steps from the updates.')],
            'shared' => [__('New for you'), __('Documents and files shared with you lately.')],
            default => [$key, ''],
        };
    }

    /**
     * Așezarea omului: secțiunile pe care le poate vedea, în ordinea lui, cu lățimea și dacă se văd.
     *
     * @return list<array{key: string, widget: class-string, span: string, visible: bool}>
     */
    public static function for(User $user, bool $all = false): array
    {
        $sections = self::sections($user);
        $saved = collect($user->today_layout ?? [])->filter(fn ($row) => is_array($row) && isset($sections[$row['key'] ?? null]))->keyBy('key');
        $keys = $saved->keys()->values()->all();                              // o secțiune nouă intră lângă vecina ei din ordinea implicită, nu la sfârșit
        $defaults = array_keys($sections);
        foreach ($defaults as $i => $key) {
            if (in_array($key, $keys, true)) {
                continue;
            }
            $next = collect(array_slice($defaults, $i + 1))->first(fn (string $k) => in_array($k, $keys, true));
            $at = $next === null ? count($keys) : array_search($next, $keys, true);
            array_splice($keys, $at, 0, [$key]);
        }
        $keys = collect($keys);

        return $keys->map(function (string $key) use ($saved, $sections) {
            [$widget, $span, $visible] = $sections[$key];
            $row = $saved->get($key, []);

            return ['key' => $key, 'widget' => $widget, 'span' => in_array($row['span'] ?? null, ['full', 'half'], true) ? $row['span'] : $span,
                'visible' => array_key_exists('visible', $row) ? (bool) $row['visible'] : $visible];
        })->filter(fn (array $row) => $all || $row['widget']::canView())->values()->all();   // $all: și cele ascunse azi, ca ordinea lor să nu se piardă
    }

    /** @param  list<array{key: string, span?: string, visible?: bool}>  $rows */
    public static function save(User $user, array $rows): void
    {
        $sections = self::sections($user);
        $clean = collect($rows)->filter(fn ($row) => isset($sections[$row['key'] ?? null]))
            ->map(fn ($row) => ['key' => $row['key'], 'span' => ($row['span'] ?? 'full') === 'half' ? 'half' : 'full', 'visible' => (bool) ($row['visible'] ?? true)])
            ->unique('key')->values()->all();
        $user->forceFill(['today_layout' => $clean ?: null])->save();
    }

    /** Ordinea nouă (după ce omul a tras o secțiune), cu lățimile și vizibilitatea păstrate. @param  list<string>  $keys */
    public static function reorder(User $user, array $keys): void
    {
        $rows = collect(self::for($user, all: true))->keyBy('key');
        self::save($user, collect($keys)->filter(fn ($k) => $rows->has($k))->map(fn ($k) => $rows[$k])
            ->merge($rows->except($keys)->values())->values()->all());
    }

    public static function change(User $user, string $key, array $values): void
    {
        self::save($user, collect(self::for($user, all: true))->map(fn (array $row) => $row['key'] === $key ? array_merge($row, $values) : $row)->all());
    }

    public static function reset(User $user): void
    {
        $user->forceFill(['today_layout' => null])->save();
    }
}
