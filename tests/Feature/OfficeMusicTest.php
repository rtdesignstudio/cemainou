<?php

namespace Tests\Feature;

use App\Livewire\MusicPlayer;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\OfficeMusicTool;
use App\Models\Client;
use App\Models\MusicQueueItem;
use App\Models\MusicTrack;
use App\Models\User;
use App\Support\Jukebox;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Muzica biroului pe YouTube: linkuri la coadă (3 de om, fără dubluri), pagina biroului care cântă și raportează, istoricul când coada se termină. */
class OfficeMusicTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(['www.youtube.com/oembed*' => fn ($request) => str_contains($request->url(), 'xxxxxxxxxxx')
            ? Http::response('Not Found', 404)
            : Http::response(['title' => 'Song '.(preg_match('~v=([\w-]{11})~', urldecode($request->url()), $m) ? $m[1] : '?'), 'author_name' => 'Band'])]);
        $this->ana = User::factory()->create(['role' => 'admin', 'name' => 'Ana Popescu']);
        Settings::save(['music_on' => true], $this->ana);
    }

    public function test_links_are_parsed(): void
    {
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?si=abc', 'https://music.youtube.com/watch?v=dQw4w9WgXcQ&list=x', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ'] as $url) {
            $this->assertSame('dQw4w9WgXcQ', Jukebox::videoId($url), $url);
        }
        $this->assertNull(Jukebox::videoId('https://open.spotify.com/track/abc'));
        $this->assertNull(Jukebox::videoId('https://evil.com/?u=youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function test_the_queue_has_rules(): void
    {
        $this->assertNull(Jukebox::add($this->ana, 'https://youtu.be/aaaaaaaaaaa'));
        $this->assertSame('Song aaaaaaaaaaa', MusicTrack::first()->title);
        $this->assertNotNull(Jukebox::add($this->ana, 'https://youtu.be/aaaaaaaaaaa'));                 // dublură
        $this->assertNotNull(Jukebox::add($this->ana, 'https://open.spotify.com/track/1'));             // nu e YouTube
        $this->assertNotNull(Jukebox::add($this->ana, 'https://youtu.be/xxxxxxxxxxx'));                 // YouTube nu-l găsește
        Jukebox::add($this->ana, 'https://youtu.be/bbbbbbbbbbb');
        Jukebox::add($this->ana, 'https://youtu.be/ccccccccccc');
        $this->assertNull(Jukebox::add($this->ana, 'https://youtu.be/ddddddddddd?t=1m30s'));            // fără limită de piese
        $this->assertSame(4, MusicQueueItem::count());
        $this->assertSame(90, MusicTrack::where('video_id', 'ddddddddddd')->value('start'));
        foreach (['https://youtu.be/x?t=90' => 90, 'https://www.youtube.com/watch?v=x&t=1h2m3s' => 3723, 'https://youtu.be/x?start=15' => 15, 'https://youtu.be/x' => 0] as $url => $sec) {
            $this->assertSame($sec, Jukebox::startAt($url), $url);
        }
    }

    public function test_the_office_page_plays_the_queue_then_the_history(): void
    {
        $this->actingAs($this->ana)->get(route('music.office'))->assertOk()->assertSee('wsa/jukebox.js', false);
        $this->postJson(route('music.claim'), ['token' => 'office'])->assertOk()->assertJson(['active' => true]);
        $this->postJson(route('music.tick'), ['token' => 'office', 'video' => null])->assertOk()->assertJson(['video' => null, 'empty' => true, 'where' => 'Ana Popescu']);
        $this->assertTrue(Jukebox::officeOnline());

        Jukebox::add($this->ana, 'https://youtu.be/aaaaaaaaaaa');
        Jukebox::add($this->ana, 'https://youtu.be/bbbbbbbbbbb');
        $this->postJson(route('music.tick'), ['token' => 'office', 'video' => null])->assertJson(['video' => 'aaaaaaaaaaa', 'by' => 'added by Ana Popescu', 'radio' => false]);
        $this->postJson(route('music.tick'), ['token' => 'office', 'video' => 'aaaaaaaaaaa', 'position' => 30, 'duration' => 200])->assertJson(['video' => 'aaaaaaaaaaa', 'position' => 30]);

        Jukebox::setPlaying(false);                                                                    // pauză din panou
        $this->postJson(route('music.tick'), ['token' => 'office', 'video' => 'aaaaaaaaaaa', 'position' => 31, 'duration' => 200])->assertJson(['playing' => false]);

        $this->postJson(route('music.ended'), ['token' => 'office', 'video' => 'aaaaaaaaaaa'])->assertJson(['video' => 'bbbbbbbbbbb', 'playing' => true]);
        $this->postJson(route('music.ended'), ['token' => 'office', 'video' => 'aaaaaaaaaaa'])->assertJson(['video' => 'bbbbbbbbbbb']);    // a doua pagină nu mai sare
        $this->travel(2)->seconds();
        $this->postJson(route('music.ended'), ['token' => 'office', 'video' => 'bbbbbbbbbbb', 'error' => 150])->assertJson(['video' => 'aaaaaaaaaaa', 'radio' => true]);
        $this->assertTrue(MusicTrack::where('video_id', 'bbbbbbbbbbb')->first()->blocked);

        MusicTrack::where('video_id', 'aaaaaaaaaaa')->update(['excluded_until' => now()->addWeek()]);
        $this->postJson(route('music.ended'), ['token' => 'office', 'video' => 'aaaaaaaaaaa'])->assertJson(['video' => null]);  // exclusă, blocată: liniște

        Jukebox::add($this->ana, 'https://youtu.be/ccccccccccc');
        Jukebox::next();
        $this->assertSame('ccccccccccc', Jukebox::forOffice()['video']);
        Jukebox::previous();
        $this->assertSame('aaaaaaaaaaa', Jukebox::forOffice()['video']);
        $this->assertSame('ccccccccccc', Jukebox::upcoming()->first()->track->video_id);               // cea pusă de Ana revine prima la coadă
    }

    public function test_the_panel_adds_controls_and_keeps_the_history(): void
    {
        $this->actingAs($this->ana);
        Livewire::test(MusicPlayer::class)->set('open', true)
            ->assertSee(__('Start on my computer'))
            ->set('url', 'https://youtu.be/aaaaaaaaaaa')->assertSee('Song aaaaaaaaaaa')
            ->call('add')->assertSet('url', '')->assertSee('number 1')
            ->set('url', 'https://youtu.be/aaaaaaaaaaa')->call('add')->assertSee(__('This song is already playing or in the queue.'));

        Jukebox::claim('office', $this->ana);
        Jukebox::tick('office', null, 0, 0);
        $track = MusicTrack::first();
        Livewire::test(MusicPlayer::class)->set('open', true)->assertSee('Song aaaaaaaaaaa')->assertSee(__('Library'))
            ->call('control', 'pause')->call('exclude', $track->id)->call('include', $track->id)->call('remove', $track->id);
        $this->assertFalse(Jukebox::current()['playing']);
        $this->assertSame(0, MusicTrack::count());
    }

    public function test_anyone_can_take_the_music_to_their_computer(): void
    {
        Jukebox::add($this->ana, 'https://youtu.be/aaaaaaaaaaa');
        Jukebox::claim('office', $this->ana);
        Jukebox::tick('office', null, 0, 0);
        Jukebox::tick('office', 'aaaaaaaaaaa', 40, 200);

        $ion = User::factory()->create(['role' => 'member', 'name' => 'Ion Rusu']);
        $this->actingAs($ion)->postJson(route('music.claim'), ['token' => 'ion'])->assertForbidden();         // doar adminul și cei aleși
        Livewire::actingAs($ion)->test(MusicPlayer::class)->set('open', true)->assertDontSee(__('Listen on my computer'));
        Settings::save(['music_players' => [(string) $ion->id]], $this->ana);
        $this->assertSame([$ion->id], Settings::get('music_players'));
        $this->actingAs($ion)->postJson(route('music.claim'), ['token' => 'ion'])->assertJson(['active' => true, 'video' => 'aaaaaaaaaaa', 'where' => 'Ion Rusu']);
        $this->assertGreaterThanOrEqual(40, Jukebox::forOffice()['position']);                       // continuă de unde a rămas
        $this->assertFalse(Jukebox::tick('office', 'aaaaaaaaaaa', 41, 200)['active']);                // biroul tace
        $this->assertSame(['video' => 'aaaaaaaaaaa'], array_intersect_key(Jukebox::ended('office', 'aaaaaaaaaaa', false), ['video' => 1]));   // și nu mai poate sări piese
        $this->assertSame('Ion Rusu', Jukebox::playsAt()->name);
        Livewire::actingAs($this->ana)->test(MusicPlayer::class)->set('open', true)->assertSee('Ion Rusu')->assertSee(__('Listen on my computer'));
    }

    public function test_shuffle_repeat_play_now_and_stop(): void
    {
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        Jukebox::claim('mine', $this->ana);
        $first = Jukebox::tick('mine', null, 0, 0);
        $this->assertSame('aaaaaaaaaaa', $first['video']);

        $this->actingAs($this->ana)->postJson(route('music.control'), ['token' => 'mine', 'what' => 'repeat'])->assertJson(['repeat' => 'one']);
        $again = Jukebox::ended('mine', 'aaaaaaaaaaa', false);                                        // repetat: aceeași piesă, din nou
        $this->assertSame('aaaaaaaaaaa', $again['video']);
        $this->assertSame($first['serial'] + 1, $again['serial']);
        Jukebox::next();                                                                              // „următoarea" trece totuși mai departe
        $this->assertSame('bbbbbbbbbbb', Jukebox::forOffice()['video']);

        Jukebox::toggle('repeat');                                                                    // a doua apăsare: coada
        $this->assertSame('queue', Jukebox::forOffice()['repeat']);
        Jukebox::next();                                                                              // bbb revine la sfârșitul cozii
        $this->assertSame(['bbbbbbbbbbb'], Jukebox::upcoming()->map(fn ($q) => $q->track->video_id)->all());
        Jukebox::toggle('repeat');
        $this->assertSame('all', Jukebox::forOffice()['repeat']);                                     // a treia: tot
        Jukebox::toggle('repeat');
        $this->assertFalse(Jukebox::forOffice()['repeat']);
        Jukebox::previous();
        $this->assertSame('bbbbbbbbbbb', Jukebox::forOffice()['video']);
        Jukebox::toggle('shuffle');
        $this->assertTrue(Jukebox::current()['shuffle']);

        $this->assertNull(Jukebox::playNow($this->ana, MusicTrack::where('video_id', 'ccccccccccc')->first()));
        $this->assertSame('ccccccccccc', Jukebox::forOffice()['video']);
        $this->assertSame(0, MusicQueueItem::count());                                                // a ieșit din coadă

        Livewire::test(MusicPlayer::class)->call('seek', 50);                                         // sărit pe timeline
        $state = Jukebox::tick('mine', 'ccccccccccc', 4, 200, 0);                                     // pagina n-a aplicat încă saltul
        $this->assertSame(1, $state['seek']);
        $this->assertGreaterThanOrEqual(50, $state['position']);
        Livewire::test(MusicPlayer::class)->call('volume', 140);
        $this->assertSame(100, Jukebox::forOffice()['volume']);
        Jukebox::setVolume(35);
        $this->assertSame(35, Jukebox::tick('mine', 'ccccccccccc', 3, 200)['volume']);
        $this->postJson(route('music.control'), ['token' => 'mine', 'what' => 'release'])->assertJson(['active' => false]);
        $this->assertNull(Jukebox::playsAt());
        $this->assertFalse(Jukebox::officeOnline());
    }

    public function test_the_library_shows_what_comes_next_then_everything_else(): void
    {
        $this->actingAs($this->ana);
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        Jukebox::claim('mine', $this->ana);
        Jukebox::tick('mine', null, 0, 0);                                                            // aaa cântă; bbb, ccc urmează
        $item = Jukebox::upcoming()->last();
        Livewire::test(MusicPlayer::class)->set('open', true)->assertSeeInOrder(['Song bbbbbbbbbbb', 'next 1', 'Song ccccccccccc', 'next 2'])
            ->call('unqueue', $item->id)->assertSee('Song ccccccccccc');                              // scoasă de la rând, rămâne în bibliotecă
        $this->assertSame(1, MusicQueueItem::count());
        $this->assertSame(3, MusicTrack::count());

        Jukebox::add($this->ana, 'https://youtu.be/ddddddddddd');
        $ids = Jukebox::upcoming()->pluck('id')->all();
        Livewire::test(MusicPlayer::class)->call('reorder', array_reverse($ids));                     // trase în altă ordine
        $this->assertSame('ddddddddddd', Jukebox::upcoming()->first()->track->video_id);

        Jukebox::tick('mine', 'aaaaaaaaaaa', 12, 215.4);                                              // durata o spune playerul
        $this->assertSame(215, MusicTrack::where('video_id', 'aaaaaaaaaaa')->value('duration'));

        Livewire::test(MusicPlayer::class)->set('open', true)->set('search', 'ccc')->assertSee('Song ccccccccccc')->assertDontSee('Song ddddddddddd')
            ->call('togglePlay');
        $this->assertFalse(Jukebox::current()['playing']);
    }

    public function test_after_the_queue_the_whole_library_plays_once_or_again_on_repeat_all(): void
    {
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        Jukebox::claim('mine', $this->ana);
        Jukebox::tick('mine', null, 0, 0);
        Jukebox::ended('mine', 'aaaaaaaaaaa', false);
        Jukebox::ended('mine', 'bbbbbbbbbbb', false);
        $this->travel(5)->seconds();
        $played = [];
        foreach (range(1, 4) as $i) {                                                                 // coada s-a terminat: biblioteca, la rând
            $now = Jukebox::ended('mine', Jukebox::forOffice()['video'], false);
            $played[] = $now['video'];
            $this->travel(2)->seconds();
        }
        $this->assertSame(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc', null], $played);               // toate, o dată; apoi liniște

        Jukebox::add($this->ana, 'https://youtu.be/ccccccccccc');
        Jukebox::tick('mine', null, 0, 0);
        foreach (['one', 'queue', 'all'] as $mode) {
            Jukebox::toggle('repeat');
        }
        $this->travel(5)->seconds();
        $seen = [];
        foreach (range(1, 5) as $i) {
            $seen[] = Jukebox::ended('mine', (string) Jukebox::forOffice()['video'], false)['video'];
            $this->travel(2)->seconds();
        }
        $this->assertNotContains(null, $seen);                                                        // repetă tot: nu se oprește
    }

    public function test_the_library_is_dragged_into_order_and_plays_in_that_order(): void
    {
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        MusicQueueItem::query()->delete();
        $this->assertSame([1, 2, 3], MusicTrack::orderBy('id')->pluck('sort')->all());                // una nouă vine la sfârșit
        $ids = MusicTrack::orderBy('id')->pluck('id')->all();
        $this->actingAs($this->ana);
        Livewire::test(MusicPlayer::class)->call('reorderLibrary', [$ids[2], $ids[0]])                 // ccc trasă deasupra lui aaa (bbb nu s-a mișcat)
            ->set('open', true)->assertSeeInOrder(['Song ccccccccccc', 'Song bbbbbbbbbbb', 'Song aaaaaaaaaaa']);

        Jukebox::claim('mine', $this->ana);
        $played = [Jukebox::tick('mine', null, 0, 0)['video']];
        $this->travel(5)->seconds();
        foreach (range(1, 2) as $i) {
            $played[] = Jukebox::ended('mine', end($played), false)['video'];
            $this->travel(2)->seconds();
        }
        $this->assertSame(['ccccccccccc', 'bbbbbbbbbbb', 'aaaaaaaaaaa'], $played);                     // biblioteca cântă în ordinea trasă
    }

    public function test_an_agent_adds_a_link_and_tells_what_plays(): void
    {
        Jukebox::add($this->ana, 'https://youtu.be/aaaaaaaaaaa');
        Jukebox::claim('mine', $this->ana);
        Jukebox::tick('mine', null, 0, 0);
        foreach (['bbbbbbbbbbb', 'ccccccccccc', 'ddddddddddd'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        CemainouServer::actingAs($this->ana)->tool(OfficeMusicTool::class, ['url' => 'https://youtu.be/eeeeeeeeeee?t=30'])->assertOk()
            ->assertSee('Added to the queue: Song eeeeeeeeeee (number 4)')
            ->assertSee('Playing now: Song aaaaaaaaaaa')->assertSee('1. Song bbbbbbbbbbb')->assertSee('3. Song ddddddddddd')->assertSee('1 more');
        CemainouServer::actingAs($this->ana)->tool(OfficeMusicTool::class, ['url' => 'https://youtu.be/bbbbbbbbbbb'])->assertHasErrors();
        $this->assertSame(30, MusicTrack::where('video_id', 'eeeeeeeeeee')->value('start'));
    }

    public function test_votes_filters_play_all_and_the_mood_of_the_day(): void
    {
        $ion = User::factory()->create(['role' => 'member', 'name' => 'Ion Rusu']);
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb'] as $id) {
            Jukebox::add($this->ana, 'https://youtu.be/'.$id);
        }
        foreach (['ccccccccccc', 'ddddddddddd'] as $id) {
            Jukebox::add($ion, 'https://youtu.be/'.$id);
        }
        MusicQueueItem::query()->delete();
        $d = MusicTrack::where('video_id', 'ddddddddddd')->first();
        $this->actingAs($this->ana);
        Livewire::test(MusicPlayer::class)->call('vote', $d->id)->call('vote', $d->id)->call('vote', $d->id);   // votat, retras, votat
        $this->assertSame(1, $d->votes()->count());

        Livewire::test(MusicPlayer::class)->set('open', true)->assertSee('IR')->assertSee(__('Favorites'))
            ->set('person', (string) $ion->id)->assertSee('Song ccccccccccc')->assertDontSee('Song aaaaaaaaaaa')
            ->call('playAll', false)->assertSee('2 songs added');
        $this->assertSame(['ccccccccccc', 'ddddddddddd'], Jukebox::upcoming()->map(fn ($q) => $q->track->video_id)->all());

        Jukebox::claim('mine', $this->ana);
        Livewire::test(MusicPlayer::class)->set('person', 'fav')->call('playAll', true);              // acum: preferatele, pe loc
        $this->assertSame('ddddddddddd', Jukebox::forOffice()['video']);
        $this->assertSame(0, MusicQueueItem::count());

        MusicTrack::query()->update(['last_played_at' => now()->subDay()]);                          // o zi nouă: prima piesă din bibliotecă vine din preferate
        Jukebox::release('mine');
        Cache::forget('jukebox');
        Jukebox::claim('mine', $this->ana);
        $this->assertSame('ddddddddddd', Jukebox::tick('mine', null, 0, 0)['video']);
    }

    public function test_clients_and_the_switch_keep_it_hidden(): void
    {
        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(route('music.office'))->assertNotFound();
        $collaborator = User::factory()->create(['role' => 'collaborator']);                          // nu vede toată echipa: nici muzica, care arată numele tuturor
        $this->actingAs($collaborator)->get(route('music.office'))->assertNotFound();
        Settings::save(['music_on' => false], $this->ana);
        $this->actingAs($this->ana)->get(route('music.office'))->assertNotFound();
        $this->postJson(route('music.tick'))->assertNotFound();
    }
}
