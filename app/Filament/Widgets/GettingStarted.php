<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Commands;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Support\TeamMail;
use Filament\Widgets\Widget;
use Laravel\Passport\Token as OAuthToken;

/**
 * Lista de pornire de pe „Azi", pentru un membru nou: agentul conectat, primul /upd, aplicația pe telefon, notificările, prima idee.
 * Bifele se pun singure din ce a făcut omul; dispare când e totul gata sau când o ascunde. Înlocuiește banda „Conectează agentul".
 * Clientul are pașii lui: prima cerere, feedback-ul de pe site (dacă proiectul are widget-ul), un fișier pe proiect, telefonul, notificările;
 * agentul are la el cardul lui, cu pașii (ClientConnectAgent).
 */
class GettingStarted extends Widget
{
    protected string $view = 'filament.widgets.getting-started';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->usesAgents() || ($user->is_active && $user->isClient())) && $user->getting_started_hidden_at === null && ! self::allDone(self::steps());
    }

    /** Deschisă din aplicația instalată (sau omul spune că a pus-o): bifa rămâne, pe orice dispozitiv. */
    public function installed(): void
    {
        $user = auth()->user();
        if ($user->app_installed_at === null) {
            $user->forceFill(['app_installed_at' => now()])->save();
        }
    }

    public function hide(): void
    {
        auth()->user()->forceFill(['getting_started_hidden_at' => now()])->save();
        $this->redirect(Dashboard::getUrl(), navigate: true);
    }

    /** @return list<array{key: string, done: bool, title: string, text: string, url: ?string, link: ?string}> */
    public static function steps(): array
    {
        $user = auth()->user();
        $profile = filament()->getProfileUrl();
        $phone = ['key' => 'phone', 'done' => $user->app_installed_at !== null || PushSubscription::where('user_id', $user->id)->exists(),
            'title' => __('Put cemainou on your phone'), 'text' => __('Open this page on your phone, then Share or the browser menu → Add to Home screen.'), 'url' => null, 'link' => null];
        $agent = ['key' => 'agent', 'done' => $user->tokens()->exists() || OAuthToken::where('user_id', $user->id)->where('revoked', false)->exists(),
            'title' => __('Connect your agent'), 'text' => __('Claude Code, Cursor or the Claude app on your phone: once per device.'), 'url' => $profile.'?connect=1', 'link' => __('Connect it')];

        if ($user->isClient()) {
            return self::clientSteps($user, $profile, $phone);
        }
        // adminul unei instalări noi: întâi ce pornește echipa (emailul, primul proiect, oamenii), apoi pașii lui de om din echipă
        $admin = $user->isAdmin() ? [
            ['key' => 'mail', 'done' => TeamMail::ready(),
                'title' => __('Set up email'), 'text' => __('For invitations, forgotten passwords and the weekly summary. Without it, invitations are links you send yourself.'),
                'url' => SystemSettings::getUrl(), 'link' => __('Open Settings')],
            ['key' => 'project', 'done' => Project::exists(),
                'title' => __('Create the first project'), 'text' => __('A client or an internal project: updates, tasks and hours gather on it. A template brings its standard tasks.'),
                'url' => ProjectResource::getUrl('create'), 'link' => __('New project')],
            ['key' => 'team', 'done' => User::where('id', '!=', $user->id)->where('role', '!=', 'client')->exists(),
                'title' => __('Invite your team'), 'text' => __('Each one gets a link, sets a password and connects their agent. Up to five people it is free.'),
                'url' => UserResource::getUrl('index'), 'link' => __('Invite')],
        ] : [];

        return [
            ...$admin,
            $agent,
            ['key' => 'update', 'done' => $user->updates()->exists(),
                'title' => __('Send your first update'), 'text' => __('Type /upd in your agent: it drafts it from git, you confirm.'), 'url' => Commands::getUrl(), 'link' => __('See the commands')],
            $phone,
            ['key' => 'notifications', 'done' => PushSubscription::where('user_id', $user->id)->exists(),
                'title' => __('Turn on notifications'), 'text' => __('New tasks, comments and answers reach your phone.'), 'url' => $profile, 'link' => __('Turn on')],
            ['key' => 'idea', 'done' => Task::where('created_by', $user->id)->exists() || ProjectNote::where('user_id', $user->id)->exists(),
                'title' => __('Note your first idea'), 'text' => __('Press N, or + on the phone: an idea, a task or a note, in one field.'), 'url' => null, 'link' => null],
        ];
    }

    /** Pașii clientului: cererea merge la liderul proiectului, fișierul rămâne între echipă și firma lui. */
    private static function clientSteps(User $user, string $profile, array $phone): array
    {
        $project = Project::visibleTo($user)->orderByDesc('updated_at')->first();
        $site = ClientSiteFeedback::sites()->first();                       // cu widget-ul pornit: și feedback-ul de pe site

        return array_values(array_filter([
            ['key' => 'request', 'done' => Task::where('created_by', $user->id)->exists(),
                'title' => __('Ask the team for something'), 'text' => __('Press N, or + on the phone: write what you need. It goes to the project lead, you see where it is.'), 'url' => null, 'link' => null],
            $site ? ['key' => 'site', 'done' => Task::where('created_by', $user->id)->whereNotNull('site_context')->exists(),
                'title' => __('Send feedback from the site'), 'text' => __('Turn on the Feedback button on :site: point at what to change, the screenshot goes with it.', ['site' => $site['domain']]),
                'url' => route('widget.activate', ['project' => $site['project'], 'domain' => $site['domain']]), 'link' => __('Open the site')] : null,
            ['key' => 'file', 'done' => Attachment::where('user_id', $user->id)->exists() || Document::where('user_id', $user->id)->exists(),
                'title' => __('Add a file to your project'), 'text' => __('A brief, a contract, a link: on the project page. Only the team and your colleagues see it.'),
                'url' => $project ? ProjectResource::getUrl('view', ['record' => $project]).'#documents' : null, 'link' => __('Open the project')],
            $phone,
            ['key' => 'notifications', 'done' => PushSubscription::where('user_id', $user->id)->exists(),
                'title' => __('Turn on notifications'), 'text' => __('When the team answers, finishes something or needs you, it reaches your phone.'), 'url' => $profile, 'link' => __('Turn on')],
        ]));
    }

    private static function allDone(array $steps): bool
    {
        return collect($steps)->every('done');
    }

    protected function getViewData(): array
    {
        $steps = self::steps();

        return ['steps' => $steps, 'done' => collect($steps)->where('done', true)->count()];
    }
}
