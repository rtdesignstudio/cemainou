<?php

namespace App\Support;

use App\Enums\ActivityKind;
use App\Models\Activity;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Update;
use App\Models\User;
use App\Models\WidgetDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Passport\Token as OAuthToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pe cardurile din „Echipa azi" (18 oct 2026): dacă uneltele fiecăruia chiar merg, nu doar dacă le-a pornit cândva. Agentul (cheia, când a
 * fost folosită), timpul măsurat (hook-urile, în ultimele 7 zile), /upd (când a raportat), aplicația Claude, telefonul, notificările,
 * Telegram (dacă botul e pus) și widget-ul (la cei de pe proiecte cu widget). Plus: fișierele agentului rămase în urmă și hook-urile pe jumătate blocate. Fiecare: ok, warn (ceva s-a stricat sau a rămas în urmă) sau off.
 */
class TeamSetup
{
    public const STALE_KEY_DAYS = 10;

    public const NO_TIME_DAYS = 7;

    public const UPD_WORKDAYS = 3;

    /** Prompturi și unelte, dar nicio pornire de sesiune de atâtea zile: hook-ul de pornire (care aduce și actualizările) nu rulează. */
    public const NO_START_DAYS = 3;

    /** @param  Collection<int, User>  $users  @return array<int, list<array{key: string, label: string, state: string, note: ?string}>> */
    public static function for(Collection $users): array
    {
        $ids = $users->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $keys = PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->groupBy('tokenable_id')
            ->selectRaw('tokenable_id, max(last_used_at) as used, count(*) as n')->get()->keyBy('tokenable_id');
        $hooks = [ActivityKind::SessionStart->value, ActivityKind::Prompt->value, ActivityKind::Tool->value, ActivityKind::Stop->value, ActivityKind::SessionEnd->value];   // doar hook-urile, nu cererile MCP din aplicația Claude
        $timed = Activity::whereIn('user_id', $ids)->whereIn('kind', $hooks)->where('occurred_at', '>=', now()->subDays(self::NO_TIME_DAYS))
            ->distinct()->pluck('user_id')->flip();
        $starts = Activity::whereIn('user_id', $ids)->where('kind', ActivityKind::SessionStart->value)->groupBy('user_id')->selectRaw('user_id, max(occurred_at) as at')->pluck('at', 'user_id');
        $beats = Activity::whereIn('user_id', $ids)->where('source', 'claude-code')->whereIn('kind', [ActivityKind::Prompt->value, ActivityKind::Tool->value])->where('occurred_at', '>=', now()->subDays(self::NO_START_DAYS))
            ->groupBy('user_id')->selectRaw('user_id, max(occurred_at) as at')->pluck('at', 'user_id');
        $current = AgentFiles::version();
        $lastUpdate = Update::whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, max(created_at) as at')->pluck('at', 'user_id');
        $workedSince = Activity::whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, max(occurred_at) as at')->pluck('at', 'user_id');
        $apps = OAuthToken::whereIn('user_id', $ids)->where('revoked', false)->distinct()->pluck('user_id')->flip();
        $pushed = PushSubscription::whereIn('user_id', $ids)->distinct()->pluck('user_id')->flip();
        $widgets = WidgetDevice::whereIn('user_id', $ids)->whereNull('revoked_at')->distinct()->pluck('user_id')->flip();
        $widgetProjects = Project::where('widget_enabled', true)->where('is_archived', false)->get(['id', 'lead_id']);
        $onWidget = $widgetProjects->pluck('lead_id')->filter()
            ->merge(Update::whereIn('project_id', $widgetProjects->pluck('id'))->where('created_at', '>=', now()->subDays(30))->distinct()->pluck('user_id'))->flip();
        $telegram = Telegram::configured();

        $out = [];
        foreach ($users as $user) {
            $id = $user->id;
            $key = $keys->get($id);
            $used = $key?->used ? Carbon::parse($key->used, 'UTC') : null;
            $upd = isset($lastUpdate[$id]) ? Carbon::parse($lastUpdate[$id], 'UTC') : null;
            $worked = isset($workedSince[$id]) ? Carbon::parse($workedSince[$id], 'UTC') : null;
            $updLate = $upd && $worked && $worked->gt($upd) && self::workdaysSince($upd) >= self::UPD_WORKDAYS;

            // fișierele agentului: în urmă la două porniri la rând = actualizarea automată nu merge pe calculatorul lui (o dată e normal, după o lansare)
            $stale = $user->agent_version && $user->agent_version !== $current && $user->agent_behind >= 2;
            $start = isset($starts[$id]) ? Carbon::parse($starts[$id], 'UTC') : null;
            $noStart = isset($beats[$id]) && $start && $start->lt(now()->subDays(self::NO_START_DAYS));   // doar la cine a avut porniri (Claude Code); celelalte unelte n-au hook de pornire
            $agentOk = $used && $used->gt(now()->subDays(self::STALE_KEY_DAYS));

            $items = [
                ['key' => 'agent', 'label' => __('Agent'),
                    'state' => ! $key ? 'off' : ($agentOk && ! $stale ? 'ok' : 'warn'),
                    'note' => ! $key ? __('no key on a computer') : collect([
                        $used ? __('used :when', ['when' => $used->diffForHumans()]) : __('the key was never used'),
                        $stale ? __('the agent files are out of date and do not update themselves: run :command', ['command' => AgentFiles::updateCommand()]) : null,
                    ])->filter()->join(' · ')],
                ['key' => 'time', 'label' => __('Time measured'),
                    'state' => $timed->has($id) && ! $noStart ? 'ok' : ($key ? 'warn' : 'off'),
                    'note' => $noStart ? __('no session start for :days days, only some signals: the hooks are partly blocked, and the agent files cannot update', ['days' => self::NO_START_DAYS])
                        : ($timed->has($id) ? __('sessions in the last :days days', ['days' => self::NO_TIME_DAYS]) : __('no sessions in :days days: the hooks may not run', ['days' => self::NO_TIME_DAYS]))],
                ['key' => 'upd', 'label' => '/upd',
                    'state' => ! $upd ? 'off' : ($updLate ? 'warn' : 'ok'),
                    'note' => $upd ? __('last update :when', ['when' => $upd->diffForHumans()]).($updLate ? ' · '.__('worked since, without reporting') : '') : __('no update yet')],
                ['key' => 'app', 'label' => __('Claude app'), 'state' => $apps->has($id) ? 'ok' : 'off', 'note' => $apps->has($id) ? __('connected with the account') : __('not connected')],
                ['key' => 'phone', 'label' => __('Phone'), 'state' => $user->app_installed_at || $pushed->has($id) ? 'ok' : 'off', 'note' => null],
                ['key' => 'push', 'label' => __('Notifications'), 'state' => $pushed->has($id) ? 'ok' : 'off', 'note' => null],
            ];
            if ($telegram) {
                $items[] = ['key' => 'telegram', 'label' => 'Telegram', 'state' => $user->telegram_chat_id ? 'ok' : 'off', 'note' => null];
            }
            if ($onWidget->has($id)) {
                $items[] = ['key' => 'widget', 'label' => __('Site widget'), 'state' => $widgets->has($id) ? 'ok' : 'off', 'note' => null];
            }
            $out[$id] = $items;
        }

        return $out;
    }

    /** Pentru adminul de sus: câți au o problemă, pe feluri (fără timp măsurat, cheie nefolosită, /upd în urmă). @param  array<int, list<array>>  $setup */
    public static function problems(array $setup): array
    {
        $count = fn (string $key) => collect($setup)->filter(fn (array $items) => collect($items)->contains(fn ($i) => $i['key'] === $key && $i['state'] === 'warn'))->count();

        return array_filter([
            'time' => $count('time') ? trans_choice(':count person without measured time for :days days|:count people without measured time for :days days', $count('time'), ['days' => self::NO_TIME_DAYS]) : null,
            'agent' => $count('agent') ? trans_choice(':count unused agent key|:count unused agent keys', $count('agent')) : null,
            'upd' => $count('upd') ? trans_choice(':count person behind on /upd|:count people behind on /upd', $count('upd')) : null,
        ]);
    }

    private static function workdaysSince(Carbon $since): int
    {
        $days = 0;
        for ($d = $since->copy()->addDay()->startOfDay(); $d->lte(now()); $d->addDay()) {
            if (! $d->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
