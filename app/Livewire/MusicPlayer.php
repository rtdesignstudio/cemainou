<?php

namespace App\Livewire;

use App\Models\MusicQueueItem;
use App\Models\MusicTrack;
use App\Models\User;
use App\Support\Jukebox;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Muzica biroului, în bara de sus: iconița (cu egalizatorul cât cântă) și panoul cu ce cântă, coada, linkul de pus și istoricul echipei. */
class MusicPlayer extends Component
{
    public bool $open = false;

    public string $url = '';

    public ?string $error = null;

    public ?string $added = null;

    public bool $all = false;

    public string $search = '';

    /** Filtrul bibliotecii: '' = toate, 'fav' = preferatele, altfel id-ul omului care a pus piesele. */
    public string $person = '';

    /** Mini-playerul a apăsat un buton: panoul se reîmprospătează pe loc. */
    #[On('wsa-music-changed')]
    public function refreshState(): void {}

    /** Panoul rămâne deschis între pagini (pe ecranele late stă lângă conținut, nu peste el). */
    public function mount(): void
    {
        $this->open = (bool) session('music_open', false);
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        session(['music_open' => $this->open]);
        $this->reset('error', 'added');
    }

    public function updatedUrl(): void
    {
        $this->reset('error', 'added');
    }

    public function add(): void
    {
        $this->guard();
        $this->error = Jukebox::add(auth()->user(), $this->url);
        if (! $this->error) {
            $title = MusicTrack::where('video_id', Jukebox::videoId($this->url))->value('title');
            $this->added = __('Added to the queue: :song. It is number :n.', ['song' => $title, 'n' => MusicQueueItem::count()]);
            $this->url = '';
        }
    }

    public function control(string $what): void
    {
        $this->guard();
        Jukebox::control($what);
        $this->dispatch('wsa-music-tick');                                             // mini-playerul (dacă e aici) face pe loc ce s-a cerut
    }

    public function again(int $track): void
    {
        $this->guard();
        $this->reset('added');
        $this->error = Jukebox::queue(auth()->user(), MusicTrack::findOrFail($track));
        if (! $this->error) {
            $this->added = __('Added to the queue: :song. It is number :n.', ['song' => MusicTrack::find($track)->title, 'n' => MusicQueueItem::count()]);
        }
    }

    /** Spațiu, cât panoul e deschis. */
    public function togglePlay(): void
    {
        $this->guard();
        Jukebox::setPlaying(! Jukebox::current()['playing']);
        $this->dispatch('wsa-music-tick');
    }

    public function seek(float $seconds): void
    {
        $this->guard();
        Jukebox::seek($seconds);
        $this->dispatch('wsa-music-tick');
    }

    public function volume(int $volume): void
    {
        $this->guard();
        Jukebox::setVolume($volume);
        $this->dispatch('wsa-music-tick');
    }

    public function playNow(int $track): void
    {
        $this->guard();
        $this->reset('added');
        $this->error = Jukebox::playNow(auth()->user(), MusicTrack::findOrFail($track));
        $this->dispatch('wsa-music-tick');
    }

    /** Piesele care urmează, trase în altă ordine. */
    public function reorder(array $items): void
    {
        $this->guard();
        Jukebox::reorder(array_slice($items, 0, MusicQueueItem::count()));
    }

    /** Piesele din bibliotecă, trase în altă ordine: după ea cântă biblioteca la rând. */
    public function reorderLibrary(array $tracks): void
    {
        $this->guard();
        Jukebox::reorderLibrary(array_slice($tracks, 0, 500));
    }

    public function vote(int $track): void
    {
        $this->guard();
        Jukebox::vote(auth()->user(), MusicTrack::findOrFail($track));
    }

    /** „Cântă tot" pe filtrul ales: acum (înlocuiește ce urmează) sau la coadă. */
    public function playAll(bool $now): void
    {
        $this->guard();
        abort_if($this->person === '', 403);
        $n = Jukebox::playAll(auth()->user(), $this->person === 'fav' ? 'fav' : (int) $this->person, $now);
        $this->reset('error');
        $this->added = trans_choice('{0} No song to add.|{1} One song added.|[2,*] :count songs added.', $n, ['count' => $n]);
        $this->dispatch('wsa-music-tick');
    }

    public function unqueue(int $item): void
    {
        $this->guard();
        MusicQueueItem::whereKey($item)->delete();
    }

    public function exclude(int $track): void
    {
        $this->guard();
        MusicTrack::whereKey($track)->update(['excluded_until' => now()->addWeek()]);
    }

    public function include(int $track): void
    {
        $this->guard();
        MusicTrack::whereKey($track)->update(['excluded_until' => null]);
    }

    public function remove(int $track): void
    {
        $this->guard();
        MusicTrack::whereKey($track)->delete();
    }

    public function render(): View
    {
        $on = Jukebox::enabledFor(auth()->user());
        $s = Jukebox::current();
        $track = $on && $s['track_id'] ? MusicTrack::with('adder')->find($s['track_id']) : null;
        $online = Jukebox::officeOnline();
        $id = $this->open && $this->url !== '' ? Jukebox::videoId($this->url) : null;

        return view('livewire.music-player', [
            'on' => $on,
            'online' => $online,
            'canPlay' => Jukebox::canPlay(auth()->user()),
            'where' => $on && $this->open ? Jukebox::playsAt() : null,
            'playing' => $online && $track && $s['playing'],
            'track' => $track,
            'by' => $s['user_id'] ? User::find($s['user_id']) : null,
            'radio' => $s['radio'],
            'shuffle' => $s['shuffle'],
            'volume' => $s['volume'],
            'repeat' => Jukebox::repeat($s),
            'paused' => ! $s['playing'],
            'position' => Jukebox::position($s),
            'duration' => $s['duration'],
            'queue' => $on && $this->open ? Jukebox::upcoming() : collect(),
            'preview' => $id && ! $this->error ? $this->preview($id) : null,
            'library' => $on && $this->open ? $this->library($track) : collect(),
            'people' => $on && $this->open ? MusicTrack::query()->whereNotNull('added_by')->selectRaw('added_by, count(*) as n')->groupBy('added_by')->orderByDesc('n')->get()
                ->map(fn ($row) => ['user' => User::find($row->added_by), 'n' => $row->n])->filter(fn ($p) => $p['user']) : collect(),
            'favCount' => $on && $this->open ? MusicTrack::has('votes')->count() : 0,
            'libraryCount' => $on && $this->open ? MusicTrack::count() : 0,
        ]);
    }

    /** Biblioteca de sub ce urmează, în ordinea ei (trasă de mână): toate sau un filtru (ale unui om, preferatele); cu voturi și cine a votat. */
    private function library(?MusicTrack $track)
    {
        $base = Jukebox::filtered($this->person === '' ? null : ($this->person === 'fav' ? 'fav' : (int) $this->person));

        return $base->with(['adder', 'votes.user'])->withCount('votes')
            ->when($this->person === '', fn ($q) => $q->whereNotIn('id', MusicQueueItem::select('track_id'))->when($track, fn ($q) => $q->whereKeyNot($track->id)))
            ->when(($q = mb_substr(trim($this->search), 0, 100)) !== '', fn ($query) => $query->where(fn ($w) => $w->where('title', 'like', '%'.$q.'%')->orWhere('author', 'like', '%'.$q.'%')))
            ->when(! $this->all, fn ($q) => $q->limit(15))->get();
    }

    /** Titlul și imaginea linkului lipit, înainte de „La coadă". @return array{title: string, author: ?string, thumb: string}|null */
    private function preview(string $id): ?array
    {
        $meta = MusicTrack::where('video_id', $id)->first()?->only('title', 'author') ?? Jukebox::lookup($id);

        return $meta ? $meta + ['thumb' => 'https://i.ytimg.com/vi/'.$id.'/mqdefault.jpg', 'start' => Jukebox::startAt($this->url)] : null;
    }

    private function guard(): void
    {
        abort_unless(Jukebox::enabledFor(auth()->user()), 403);
    }
}
