<?php

namespace App\Support;

use App\Models\MusicQueueItem;
use App\Models\MusicTrack;
use App\Models\MusicVote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Muzica biroului: un tonomat pe YouTube, fără nicio cheie. Echipa pune linkuri la coadă (fără dubluri); pagina biroului
 * (JukeboxController::office, lăsată deschisă pe calculatorul din birou) cântă coada cu playerul YouTube și raportează la câteva secunde
 * unde a ajuns. Când coada se termină, cântă la întâmplare din istoricul echipei (fără cele excluse și fără ce a cântat în ultimele 2 ore).
 * Ce cântă acum stă în cache (cheia jukebox); piesele și coada, în baza de date.
 */
class Jukebox
{
    private const KEY = 'jukebox';

    public static function enabledFor(?User $user): bool
    {
        return $user && $user->seesWholeTeam() && Settings::get('music_on');   // nu clienții și nici colaboratorii: muzica arată numele întregii echipe
    }

    /** Poate porni muzica la el (s-o mute pe calculatorul lui): adminii și oamenii aleși de admin în Setări. */
    public static function canPlay(?User $user): bool
    {
        return self::enabledFor($user) && ($user->isAdmin() || in_array($user->id, (array) Settings::get('music_players'), true));
    }

    /** Id-ul clipului dintr-un link de YouTube (watch?v=, youtu.be/, shorts/, embed/, music.youtube.com), sau null. */
    public static function videoId(string $url): ?string
    {
        $url = trim($url);
        $patterns = [
            '~^https?://(?:www\.|m\.|music\.)?youtube\.com/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})~',
            '~^https?://youtu\.be/([A-Za-z0-9_-]{11})~',
            '~^https?://(?:www\.|m\.)?youtube\.com/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** Titlul și canalul clipului, de la YouTube (oEmbed, fără cheie); null dacă nu există sau e privat. @return array{title: string, author: ?string}|null */
    public static function lookup(string $videoId): ?array
    {
        return Cache::remember('jukebox.oembed.'.$videoId, now()->addDay(), function () use ($videoId) {
            $response = rescue(fn () => Http::timeout(6)->get('https://www.youtube.com/oembed', ['url' => 'https://www.youtube.com/watch?v='.$videoId, 'format' => 'json']), null, false);
            if (! $response?->ok() || blank($response->json('title'))) {
                return null;
            }

            return ['title' => mb_substr((string) $response->json('title'), 0, 255), 'author' => $response->json('author_name') ? mb_substr((string) $response->json('author_name'), 0, 255) : null];
        }) ?: null;
    }

    /** Pune un link la coadă; întoarce mesajul de eroare, sau null dacă a mers. */
    public static function add(User $user, string $url): ?string
    {
        $id = self::videoId($url);
        if (! $id) {
            return __('This is not a YouTube link. youtube.com/watch?v=… and youtu.be/… work.');
        }
        $start = self::startAt($url);
        $track = MusicTrack::where('video_id', $id)->first();
        if ($track && $start) {
            $track->update(['start' => $start]);                                    // un link nou, cu alt timp de început: îl ține minte
        }

        return $track ? self::queue($user, $track) : (($meta = self::lookup($id))
            ? self::queue($user, MusicTrack::create(['video_id' => $id, 'title' => $meta['title'], 'author' => $meta['author'], 'start' => $start, 'added_by' => $user->id]))
            : __('YouTube does not find this clip (it may be private or removed).'));
    }

    /** Secunda de început din link: t=90, t=90s, t=1m30s, t=1h2m3s sau start=90; 0 dacă lipsește. */
    public static function startAt(string $url): int
    {
        if (! preg_match('~[?&#](?:t|start)=([0-9hms]+)~', $url, $m)) {
            return 0;
        }
        if (ctype_digit($m[1])) {
            return (int) $m[1];
        }
        preg_match('~^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$~', $m[1], $p);

        return (int) ($p[1] ?? 0) * 3600 + (int) ($p[2] ?? 0) * 60 + (int) ($p[3] ?? 0);
    }

    /** Pune la coadă o piesă din istoric (sau una nouă); întoarce mesajul de eroare, sau null. */
    public static function queue(User $user, MusicTrack $track): ?string
    {
        if ($track->blocked) {
            return __('This clip does not allow playing on other sites.');
        }
        if (self::current()['track_id'] === $track->id || MusicQueueItem::where('track_id', $track->id)->exists()) {
            return __('This song is already playing or in the queue.');
        }
        MusicQueueItem::create(['track_id' => $track->id, 'user_id' => $user->id, 'sort' => (MusicQueueItem::max('sort') ?? 0) + 1]);

        return null;
    }

    /** 👍 pe o piesă, sau retras dacă omul votase deja. */
    public static function vote(User $user, MusicTrack $track): void
    {
        $vote = MusicVote::where('track_id', $track->id)->where('user_id', $user->id)->first();
        $vote ? $vote->delete() : MusicVote::create(['track_id' => $track->id, 'user_id' => $user->id]);
    }

    /**
     * „Cântă tot" pentru un filtru din bibliotecă: piesele puse de un om ($by = id-ul lui) sau preferatele echipei ($by = 'fav').
     * now = ce urmează se înlocuiește cu ele și prima pornește pe loc; queue = se adaugă după ce urmează deja. Cu amestecat, vin amestecate.
     */
    public static function playAll(User $user, int|string $by, bool $now): int
    {
        $current = self::current();
        $tracks = self::filtered($by)->where('blocked', false)->where(fn ($q) => $q->whereNull('excluded_until')->orWhere('excluded_until', '<=', now()))->get();
        $tracks = $current['shuffle'] ? $tracks->shuffle() : $tracks;
        if ($now) {
            MusicQueueItem::query()->delete();
        } else {
            $tracks = $tracks->reject(fn (MusicTrack $t) => $t->id === $current['track_id'] || MusicQueueItem::where('track_id', $t->id)->exists());
        }
        $sort = (MusicQueueItem::max('sort') ?? 0);
        foreach ($tracks->values() as $track) {
            MusicQueueItem::create(['track_id' => $track->id, 'user_id' => $user->id, 'sort' => ++$sort]);
        }
        if ($now && $tracks->isNotEmpty()) {
            self::next();
        }

        return $tracks->count();
    }

    /** Piesele unui filtru, în ordinea bibliotecii: toate, ale unui om, sau preferatele (cele mai votate întâi). */
    public static function filtered(int|string|null $by)
    {
        return match (true) {
            $by === 'fav' => MusicTrack::withCount('votes')->has('votes')->orderByDesc('votes_count')->orderBy('id'),
            is_numeric($by) => MusicTrack::where('added_by', (int) $by)->orderBy('sort')->orderBy('id'),
            default => MusicTrack::query()->orderBy('sort')->orderBy('id'),
        };
    }

    /** Ordinea nouă a pieselor care urmează (trase cu mouse-ul în panou): id-urile locurilor din coadă, de sus în jos. */
    public static function reorder(array $itemIds): void
    {
        foreach (array_values($itemIds) as $i => $id) {
            MusicQueueItem::whereKey((int) $id)->update(['sort' => $i + 1]);
        }
    }

    /** Ordinea nouă a bibliotecii (trasă cu mouse-ul): piesele trase își schimbă între ele locurile, celelalte rămân unde erau. */
    public static function reorderLibrary(array $trackIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $trackIds)));
        $slots = MusicTrack::whereKey($ids)->orderBy('sort')->orderBy('id')->pluck('sort')->all();
        foreach ($ids as $i => $id) {
            if (isset($slots[$i])) {
                MusicTrack::whereKey($id)->update(['sort' => $slots[$i]]);
            }
        }
    }

    /** @return Collection<int, MusicQueueItem> */
    public static function upcoming()
    {
        return MusicQueueItem::with(['track', 'user'])->orderBy('sort')->get();
    }

    /**
     * Ce cântă acum, așa cum l-a raportat ultima oară pagina biroului.
     *
     * @return array{track_id: ?int, user_id: ?int, radio: bool, playing: bool, position: float, duration: float, at: int, seen: int, notice: ?string, prev: list<int>, owner: ?string, owner_user: ?int, shuffle: bool, repeat: false|'one'|'queue'|'all', serial: int, volume: int, pass: ?int, seek: int}
     */
    public static function current(): array
    {
        return Cache::get(self::KEY, []) + ['track_id' => null, 'user_id' => null, 'radio' => false, 'playing' => true, 'position' => 0.0, 'duration' => 0.0, 'at' => 0, 'seen' => 0, 'notice' => null, 'prev' => [], 'owner' => null, 'owner_user' => null, 'shuffle' => false, 'repeat' => false, 'serial' => 0, 'volume' => 100, 'pass' => null, 'seek' => 0];
    }

    /** „Pornește" pe o pagină a biroului: muzica se mută acolo, de unde a rămas; pagina care cânta până acum se oprește la următorul semn. */
    public static function claim(string $token, User $user): array
    {
        self::update(fn (array $s) => ['owner' => $token, 'owner_user' => $user->id, 'seen' => time(), 'position' => self::position($s), 'at' => time()] + $s);

        return self::forOffice($token);
    }

    /** ✕ pe mini-player: muzica nu mai cântă la calculatorul ăsta (și nicăieri, până o pornește cineva). */
    public static function release(string $token): void
    {
        self::update(fn (array $s) => $s['owner'] === $token ? ['owner' => null, 'owner_user' => null, 'seen' => 0, 'position' => self::position($s), 'at' => time()] + $s : $s);
    }

    /** Amestecat (coada și biblioteca la întâmplare), pornit și oprit; repetat, pe rând: piesa de acum, coada, tot (coada și biblioteca), oprit. */
    public static function toggle(string $mode): void
    {
        abort_unless(in_array($mode, ['shuffle', 'repeat'], true), 403);
        self::update(fn (array $s) => [$mode => $mode === 'shuffle' ? ! $s['shuffle'] : match (self::repeat($s)) {
            false => 'one', 'one' => 'queue', 'queue' => 'all', 'all' => false
        }] + $s);
    }

    /** Sare la o secundă din piesă (clic sau tras pe timeline); pagina la care cântă o face la următorul semn. */
    public static function seek(float $seconds): void
    {
        self::update(fn (array $s) => ['position' => max(0.0, $s['duration'] > 0 ? min($seconds, $s['duration'] - 1) : $seconds), 'at' => time(), 'seek' => $s['seek'] + 1] + $s);
    }

    /** Volumul (0-100), din panou; îl aplică pagina la care cântă. */
    public static function setVolume(int $volume): void
    {
        self::update(fn (array $s) => ['volume' => max(0, min(100, $volume))] + $s);
    }

    /** @return false|'one'|'all' */
    public static function repeat(array $s): false|string
    {
        return $s['repeat'] === true ? 'one' : ($s['repeat'] ?: false);
    }

    /** „Cântă acum" din istoric: piesa începe pe loc (și iese din coadă, dacă era acolo). */
    public static function playNow(User $user, MusicTrack $track): ?string
    {
        if ($track->blocked) {
            return __('This clip does not allow playing on other sites.');
        }
        self::update(function (array $s) use ($user, $track) {
            MusicQueueItem::where('track_id', $track->id)->delete();

            return self::play($track, $user->id, false, $s);
        });

        return null;
    }

    /** Omul la care cântă acum muzica (pagina lui e deschisă), sau null. */
    public static function playsAt(): ?User
    {
        $s = self::current();

        return self::officeOnline() && $s['owner_user'] ? User::find($s['owner_user']) : null;
    }

    /** Pagina care cântă e deschisă (a dat semn în ultimele 30 de secunde). */
    public static function officeOnline(): bool
    {
        return self::current()['seen'] >= time() - 30;
    }

    /** Pauză sau pornire (din panou); pagina biroului o face la următorul semn. */
    public static function setPlaying(bool $playing): void
    {
        self::update(function (array $s) use ($playing) {
            if ($s['playing'] && ! $playing) {
                $s['position'] = self::position($s);
            }

            return ['playing' => $playing, 'at' => time()] + $s;
        });
    }

    /** Trece la piesa următoare: întâi coada, apoi istoricul echipei. */
    public static function next(): void
    {
        self::update(fn (array $s) => self::advance($s));
    }

    /** Butoanele din panou și din mini-player. */
    public static function control(string $what): void
    {
        match ($what) {
            'play' => self::setPlaying(true),
            'pause' => self::setPlaying(false),
            'next' => self::next(),
            'previous' => self::previous(),
            'shuffle', 'repeat' => self::toggle($what),
            default => abort(403),
        };
    }

    /** Înapoi la piesa de dinainte; ce cânta acum (dacă era pusă de cineva) revine prima la coadă. */
    public static function previous(): void
    {
        self::update(function (array $s) {
            $prev = $s['prev'];
            while ($prev && ! ($track = MusicTrack::where('blocked', false)->find(array_pop($prev)))) {
            }
            if (empty($track)) {
                return ['position' => 0.0, 'at' => time()] + $s;                    // nimic înainte: o ia de la capăt
            }
            MusicQueueItem::where('track_id', $track->id)->delete();                  // cântă acum: nu mai stă și la coadă
            if ($s['track_id'] && ! $s['radio'] && MusicTrack::whereKey($s['track_id'])->exists()) {
                MusicQueueItem::create(['track_id' => $s['track_id'], 'user_id' => $s['user_id'], 'sort' => (MusicQueueItem::min('sort') ?? 0) - 1]);
            }

            return self::play($track, null, true, ['prev' => $prev] + $s, false);
        });
    }

    /**
     * Semnul paginii biroului: unde a ajuns clipul. Dacă nu cântă nimic și are ce, pornește următoarea piesă.
     *
     * @return array<string, mixed> ce trebuie să cânte pagina acum
     */
    public static function tick(string $token, ?string $videoId, float $position, float $duration, ?int $seek = null): array
    {
        if (self::current()['owner'] !== $token) {
            return self::forOffice($token);                                           // cântă la altcineva: pagina asta doar așteaptă
        }
        self::update(function (array $s) use ($videoId, $position, $duration, $seek) {
            $s['seen'] = time();
            $track = $s['track_id'] ? MusicTrack::find($s['track_id']) : null;
            if (! $track) {
                return self::advance($s);
            }
            if ($videoId === $track->video_id) {
                if ($duration >= 1 && ! $track->duration) {
                    $track->update(['duration' => (int) round($duration)]);                // durata, pentru bibliotecă: o spune playerul
                }
                $s = ($seek === null || $seek === $s['seek'] ? ['position' => $position, 'at' => time()] : []) + ['duration' => $duration ?: $s['duration']] + $s;   // un salt încă neaplicat de pagină: poziția veche nu-l strică
            }

            return $s;
        });

        return self::forOffice($token);
    }

    /** Clipul s-a terminat (sau nu poate fi redat: îl sărim și îl marcăm, ca să nu mai fie ales). */
    public static function ended(string $token, string $videoId, bool $blocked): array
    {
        self::update(function (array $s) use ($token, $videoId, $blocked) {
            if ($s['owner'] !== $token) {
                return $s;
            }
            $track = $s['track_id'] ? MusicTrack::find($s['track_id']) : null;
            if (! $track || $track->video_id !== $videoId) {
                return $s;                                                            // altă pagină a trecut deja mai departe
            }
            if ($blocked) {
                $track->update(['blocked' => true]);
                $s['notice'] = __('The previous clip does not allow playing on other sites: we moved to the next one.');
            }

            return self::advance($s, natural: ! $blocked);
        });

        return self::forOffice($token);
    }

    /** @return array<string, mixed> */
    public static function forOffice(?string $token = null): array
    {
        $s = self::current();
        $owner = $s['owner_user'] && self::officeOnline() ? User::find($s['owner_user']) : null;
        $track = $s['track_id'] ? MusicTrack::with('adder')->find($s['track_id']) : null;
        $by = $s['user_id'] ? User::find($s['user_id']) : null;

        return [
            'video' => $track?->video_id,
            'title' => $track?->title,
            'by' => $track ? ($s['radio'] ? ($track->adder ? __('first added by :name', ['name' => $track->adder->name]) : null) : ($by ? __('added by :name', ['name' => $by->name]) : null)) : null,
            'radio' => $s['radio'],
            'serial' => $s['serial'],
            'volume' => $s['volume'],
            'seek' => $s['seek'],
            'shuffle' => $s['shuffle'],
            'repeat' => self::repeat($s),
            'playing' => $s['playing'],
            'position' => self::position($s),
            'notice' => $s['notice'],
            'queue' => self::upcoming()->map(fn (MusicQueueItem $q) => ['title' => $q->track->title, 'thumb' => $q->track->thumb(), 'initials' => self::initials($q->user), 'by' => $q->user?->name])->values()->all(),
            'empty' => ! $track && ! MusicTrack::exists(),
            'active' => $token !== null && $s['owner'] === $token,
            'where' => $owner?->name,
        ];
    }

    /** Unde a ajuns clipul acum (socotit din ultimul semn al paginii). */
    public static function position(array $s): float
    {
        $position = $s['position'] + ($s['playing'] && $s['track_id'] ? max(0, time() - $s['at']) : 0);

        return $s['duration'] > 0 ? min($position, $s['duration']) : $position;
    }

    public static function initials(?User $user): string
    {
        return mb_strtoupper(collect(explode(' ', trim((string) $user?->name)))->map(fn (string $w) => mb_substr($w, 0, 1))->filter()->take(2)->join(''));
    }

    private static function advance(array $s, bool $natural = false): array
    {
        $repeat = self::repeat($s);
        $current = $s['track_id'] ? MusicTrack::where('blocked', false)->find($s['track_id']) : null;
        if ($natural && $repeat === 'one' && $current) {
            return self::play($current, $s['user_id'], $s['radio'], $s);              // repetă piesa: aceeași, de la capăt
        }
        if ($repeat === 'queue' && $current && ! $s['radio'] && ! MusicQueueItem::where('track_id', $current->id)->exists()) {
            MusicQueueItem::create(['track_id' => $current->id, 'user_id' => $s['user_id'], 'sort' => (MusicQueueItem::max('sort') ?? 0) + 1]);   // repetă coada: înapoi la sfârșit
        }
        $queue = MusicQueueItem::with('track');
        if ($item = ($s['shuffle'] ? $queue->inRandomOrder() : $queue->orderBy('sort'))->first()) {
            $item->delete();

            return self::play($item->track, $item->user_id, false, ['pass' => null] + $s);
        }
        // coada s-a terminat: cântă la rând toată biblioteca (în ordinea ei, trasă de mână, sau amestecat), fiecare o dată;
        // la sfârșit, liniște, afară de „repetă tot", care o ia de la capăt
        $s['pass'] ??= now()->getTimestamp();                                     // ca last_played_at: după ceasul aplicației
        $pick = function (int $pass) use ($s) {
            $pool = MusicTrack::where('blocked', false)->where(fn ($q) => $q->whereNull('excluded_until')->orWhere('excluded_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('last_played_at')->orWhere('last_played_at', '<', gmdate('Y-m-d H:i:s', $pass)))
                ->when($s['track_id'], fn ($q) => $q->whereKeyNot($s['track_id']));

            return ($s['shuffle'] ? $pool->inRandomOrder() : $pool->orderBy('sort')->orderBy('id'))->first();
        };
        // „mood-ul zilei": prima piesă a zilei din bibliotecă vine din preferatele echipei (una dintre cele mai votate 5)
        $firstToday = ! MusicTrack::where('last_played_at', '>=', now()->startOfDay())->exists();
        $track = $firstToday ? self::filtered('fav')->where('blocked', false)->where(fn ($q) => $q->whereNull('excluded_until')->orWhere('excluded_until', '<=', now()))
            ->limit(5)->get()->shuffle()->first() : null;
        $track ??= $pick($s['pass']);
        if (! $track && $repeat === 'all') {
            $s['pass'] = now()->getTimestamp();                                       // o nouă trecere prin toată biblioteca
            $track = $pick($s['pass']);
        }

        return $track ? self::play($track, null, true, $s) : ['track_id' => null, 'user_id' => null, 'radio' => false, 'position' => 0.0, 'duration' => 0.0, 'at' => time(), 'pass' => null] + $s;
    }

    private static function play(MusicTrack $track, ?int $userId, bool $radio, array $s, bool $remember = true): array
    {
        $track->increment('plays', 1, ['last_played_at' => now()]);
        if ($remember && $s['track_id'] && $s['track_id'] !== $track->id) {
            $s['prev'] = array_slice([...$s['prev'], $s['track_id']], -50);
        }

        return ['serial' => $s['serial'] + 1, 'track_id' => $track->id, 'user_id' => $userId, 'radio' => $radio, 'playing' => true, 'position' => (float) $track->start, 'duration' => 0.0, 'at' => time()] + $s;
    }

    private static function update(callable $change): void
    {
        Cache::lock(self::KEY.'.lock', 5)->block(3, function () use ($change) {
            $before = self::current();
            $after = $change($before);
            if ($after['track_id'] !== $before['track_id'] && ($before['notice'] ?? null) === ($after['notice'] ?? null)) {
                $after['notice'] = null;                                              // mesajul despre clipul sărit ține doar o piesă
            }
            Cache::forever(self::KEY, $after);
        });
    }
}
