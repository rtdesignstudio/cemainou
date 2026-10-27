<?php

namespace App\Livewire;

use App\Models\Badge;
use App\Models\User;
use App\Support\Badges\Badges;
use App\Support\Badges\Catalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Badge-urile unui om. În profil (fără $userId): progresul, toate badge-urile pe categorii (cele neprimite estompate), alegerile omului
 * (le arată echipei, le ascunde, ora de seară) și pagina fiecăruia. Pe pagina unui coleg ($userId): doar cele câștigate, dacă le arată, cu „bravo".
 */
class BadgeCollection extends Component
{
    public ?int $userId = null;

    public bool $shared = false;

    public bool $hidden = false;

    public int $eveningHour = 19;

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId;
        $me = auth()->user();
        $this->shared = (bool) $me->badges_shared;
        $this->hidden = (bool) $me->badges_hidden;
        $this->eveningHour = (int) ($me->evening_hour ?: 19);
    }

    public function updatedShared(): void
    {
        $this->own()?->update(['badges_shared' => $this->shared]);
    }

    public function updatedHidden(): void
    {
        $this->own()?->update(['badges_hidden' => $this->hidden]);
    }

    public function updatedEveningHour(): void
    {
        $this->eveningHour = max(15, min(23, $this->eveningHour));
        $this->own()?->update(['evening_hour' => $this->eveningHour]);
    }

    /** Alegerile se schimbă doar în profil, pentru omul însuși. */
    private function own(): ?User
    {
        return $this->userId === null ? auth()->user() : null;
    }

    public function render(): View
    {
        $viewer = auth()->user();
        $person = $this->userId ? User::find($this->userId) : $viewer;
        $mine = $person?->is($viewer) ?? false;
        $visible = $person && Badges::enabled() && ! $person->isClient() && ($mine ? true : ($person->badges_shared && ! $person->badges_hidden && Badges::for($viewer)));
        if (! $visible) {
            return view('livewire.badge-collection', ['visible' => false, 'mine' => $mine, 'profile' => $this->userId === null]);
        }

        $earned = $person->badges()->with('kudos.user')->orderBy('earned_at')->get()->filter(fn (Badge $b) => $b->definition())->groupBy('key');
        $rows = collect(Catalog::all())->map(function (array $def) use ($earned, $viewer) {
            /** @var Collection<int, Badge> $tiers */
            $tiers = $earned->get($def['key'], collect());
            $top = $tiers->sortByDesc('tier')->first();

            return $def + [
                'name' => Catalog::name($def['key']), 'criterion' => Catalog::criterion($def['key']), 'tier' => $top?->tier ?? 0, 'badge' => $top,
                'history' => $tiers->sortBy('tier')->map(fn (Badge $b) => ['tier' => $b->tier, 'level' => Catalog::levelLabel($def['key'], $b->tier),
                    'when' => Badges::day($b, $viewer)->locale(app()->getLocale())->isoFormat('D MMM YYYY')])->values()->all(),
                'levelLabels' => collect(range(1, count(Catalog::levels($def['key']))))->map(fn (int $t) => Catalog::levelLabel($def['key'], $t))->all(),
                'kudos' => $top ? $tiers->flatMap->kudos->map(fn ($k) => ['name' => $k->user?->name, 'note' => $k->note])->values()->all() : [],
                'kudosFromMe' => $top ? $tiers->flatMap->kudos->contains(fn ($k) => $k->user_id === $viewer->id) : false,
            ];
        });
        if (! $mine) {
            $rows = $rows->filter(fn (array $r) => $r['tier'] > 0);           // la un coleg: doar ce a câștigat
        }

        return view('livewire.badge-collection', [
            'visible' => true, 'mine' => $mine, 'profile' => $this->userId === null, 'person' => $person,
            'categories' => $rows->groupBy('category')->sortBy(fn ($g, $c) => array_search($c, Catalog::CATEGORIES, true)),
            'progress' => $mine ? collect(Badges::progress($person))->map(fn (array $p) => $p + ['name' => Catalog::name($p['key'])])->all() : [],
            'count' => $rows->where('tier', '>', 0)->count(), 'total' => collect(Catalog::all())->reject(fn ($b) => $b['secret'])->count(),
            'rarity' => fn (string $key, int $tier) => Badges::rarity($key, $tier),
        ]);
    }
}
