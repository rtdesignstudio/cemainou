<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Mail\GuestFeedbackDone;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Models\WidgetDevice;
use App\Models\WidgetInvite;
use App\Support\FeedbackWidget;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Widget-ul de feedback de pe site-ul clientului: vizitatorii nu văd nimic; un om cemainou cu acces la proiect îl pornește pe dispozitivul lui
 * (cod de unică folosință → cheia dispozitivului), apoi ce trimite intră pe proiect în numele lui. Doar de pe domeniile permise.
 */
class FeedbackWidgetTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://autonord.test';

    private User $lead;

    private User $dima;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $client = Client::factory()->create(['name' => 'Auto Nord']);
        $this->lead = User::factory()->create(['name' => 'Alex', 'role' => 'lead']);
        $this->dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $client->id]);
        $this->project = Project::factory()->create(['name' => 'Auto Nord – site nou', 'client_id' => $client->id, 'client_visible' => true, 'lead_id' => $this->lead->id,
            'widget_enabled' => true, 'widget_domains' => ['https://www.autonord.test/']]);
    }

    /** Pagina de confirmare (audit 21 oct.): domeniul la vedere, apoi apăsarea trimite codul contului spre site. */
    private function confirmedLocation($page): string
    {
        $page->assertOk()->assertSee(__('Open the site'));
        preg_match('/name="payload" value="([^"]+)"/', $page->getContent(), $m);

        return $this->post(route('widget.confirm'), ['payload' => html_entity_decode($m[1])])->assertRedirect()->headers->get('Location');
    }

    private function activate(User $user, string $origin = self::ORIGIN): ?string
    {
        $location = $this->confirmedLocation($this->actingAs($user)->get(route('widget.activate', ['project' => $this->project, 'domain' => 'autonord.test'])));
        $this->assertStringStartsWith('https://autonord.test/#wsa=', $location);
        auth()->logout();

        return $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => $origin])->json('token');
    }

    public function test_the_domain_is_cleaned_and_the_script_is_served_only_when_on(): void
    {
        $this->assertSame(['autonord.test'], $this->project->fresh()->widget_domains);
        $snippet = FeedbackWidget::snippet($this->project);                            // în pagină: fără nicio cerere pentru vizitatori
        $this->assertStringContainsString("localStorage.getItem('wsa-feedback-'+k)", $snippet);
        $this->assertStringNotContainsString('<script src=', $snippet);
        $this->assertNotNull($this->project->widget_key);
        $this->get('/api/widget/'.$this->project->widget_key.'.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')->assertSee('"key":"'.$this->project->widget_key.'"', false);

        $this->get('/api/widget/'.$this->project->widget_key.'.js')->assertCookieMissing(config('session.cookie'));   // vizitatorii nu lasă sesiuni

        $this->project->update(['widget_enabled' => false]);
        $this->get('/api/widget/'.$this->project->widget_key.'.js')->assertOk()->assertSee('widget off', false);
    }

    public function test_a_client_turns_it_on_and_sends_feedback_that_becomes_a_task_for_the_lead(): void
    {
        $token = $this->activate($this->dima);
        $this->assertNotNull($token);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN];

        $this->getJson('/api/widget/'.$this->project->widget_key.'/me', $auth)->assertOk()->assertJson(['name' => 'Dima Toma']);
        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', [], ['Authorization' => 'Bearer '.$token, 'Origin' => 'https://alt-site.md'])->assertUnauthorized();

        $context = ['url' => 'https://autonord.test/auto/123?x=1', 'title' => 'BMW X5', 'viewport' => ['w' => 390, 'h' => 844], 'dpr' => 3, 'theme' => 'dark', 'secret' => 'nu',
            'errors' => [['msg' => 'TypeError: x is undefined', 'src' => 'https://autonord.test/app.js', 'line' => 12]], 'element' => ['selector' => '#rezerva', 'text' => 'Rezervă']];
        $this->post('/api/widget/'.$this->project->widget_key.'/feedback', [
            'text' => "Butonul de rezervare nu merge pe iPhone\nApăs și nu se întâmplă nimic.", 'context' => json_encode($context),
            'shots' => [UploadedFile::fake()->image('captura.png')],
        ], $auth + ['Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'])
            ->assertOk()->assertJson(['ok' => true]);

        $task = Task::latest('id')->first();
        $this->assertSame(['Butonul de rezervare nu merge pe iPhone', 'Apăs și nu se întâmplă nimic.', $this->dima->id, $this->lead->id, 'todo'], [$task->title, $task->notes, $task->created_by, $task->assignee_id, $task->status->value]);
        $this->assertSame(['/auto/123', 'Safari 18.1', 'iOS 18.1', 'phone', 390, '#rezerva'], [$task->site_context['path'], $task->site_context['browser'], $task->site_context['os'], $task->site_context['device'], $task->site_context['viewport']['w'], $task->site_context['element']['selector']]);
        $this->assertArrayNotHasKey('secret', $task->site_context);                                    // doar cheile cunoscute
        $this->assertSame(1, $task->attachments()->count());
        $this->actingAs($this->lead)->get(route('filament.admin.resources.tasks.view', $task))->assertOk()->assertSee('Safari 18.1')->assertSee('#rezerva')->assertSee('TypeError: x is undefined');
        auth()->logout();

        $mine = $this->getJson('/api/widget/'.$this->project->widget_key.'/mine', $auth)->assertOk()->assertJsonPath('items.0.id', $task->id)->assertJsonPath('items.0.status', 'todo');
        $this->get($mine->json('items.0.thumb'))->assertOk()->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');   // miniatura: link semnat, se vede pe site
        $this->get(strtok($mine->json('items.0.thumb'), '?'))->assertForbidden();                                             // fără semnătură: nu
        $this->get('/api/widget/font/latin-ext.woff2')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->getJson('/api/widget/'.$this->project->widget_key.'/page?url='.urlencode('https://autonord.test/auto/123'), $auth)->assertOk()->assertJsonPath('items.0.selector', '#rezerva');
        $this->getJson('/api/widget/'.$this->project->widget_key.'/page?url='.urlencode('https://autonord.test/'), $auth)->assertOk()->assertJsonCount(0, 'items');   // altă pagină: fără semne
        $task->update(['status' => 'done']);
        $this->getJson('/api/widget/'.$this->project->widget_key.'/page?url='.urlencode('https://autonord.test/auto/123'), $auth)->assertOk()->assertJsonCount(0, 'items');   // închisă: semnul dispare
    }

    public function test_several_elements_shown_on_the_page_are_kept_together(): void
    {
        $token = $this->activate($this->dima);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN, 'Accept' => 'application/json'];
        $els = [['selector' => '#rezerva', 'text' => 'Rezervă'], ['selector' => '.pret', 'text' => '24.900 €'], ['selector' => '#rezerva', 'text' => 'dublat']];
        $this->post('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Prețul și butonul nu se aliniază',
            'context' => json_encode(['url' => 'https://autonord.test/auto/123', 'element' => $els[0], 'elements' => $els])], $auth)->assertOk();

        $site = Task::latest('id')->first()->site_context;
        $this->assertSame('#rezerva', $site['element']['selector']);                                    // primul, pentru semnele de pe pagină
        $this->assertSame(['#rezerva', '.pret'], array_column($site['elements'], 'selector'));          // se adună, fără dubluri
        $this->actingAs($this->lead)->get(route('filament.admin.resources.tasks.view', Task::latest('id')->first()))->assertOk()->assertSee(['#rezerva', '.pret', '24.900 €']);
    }

    public function test_the_client_sees_the_team_answers_and_a_sign_when_there_is_news(): void
    {
        $token = $this->activate($this->dima);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN];
        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Logo-ul e prea mic', 'context' => json_encode(['url' => 'https://autonord.test/'])], $auth)->assertOk();
        $task = Task::latest('id')->first();
        $page = '/api/widget/'.$this->project->widget_key.'/page?url='.urlencode('https://autonord.test/');
        $this->getJson($page, $auth)->assertJsonPath('news_at', null);

        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->dima->id, 'body' => 'Și pe mobil']);         // al lui: nu e o noutate
        $this->getJson($page, $auth)->assertJsonPath('news_at', null);

        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->lead->id, 'body' => 'L-am **mărit**, vezi acum.']);
        $this->assertNotNull($this->getJson($page, $auth)->json('news_at'));
        $this->getJson('/api/widget/'.$this->project->widget_key.'/mine', $auth)->assertJsonPath('items.0.reply.by', 'Alex')->assertJsonPath('items.0.reply.text', 'L-am mărit, vezi acum.');
        $this->assertSame(1, $this->dima->notifications()->count());                                                  // și în clopoțel

        $task->moveTo(TaskStatus::Done, $this->lead);
        $this->assertSame(2, $this->dima->notifications()->count());
        $this->assertNotNull($this->getJson('/api/widget/'.$this->project->widget_key.'/mine', $auth)->json('items.0.news_at'));
    }

    public function test_a_javascript_url_is_not_kept(): void
    {
        $this->assertArrayNotHasKey('url', FeedbackWidget::context(['url' => 'javascript:alert(1)'], null));
    }

    public function test_the_team_sends_a_task_for_the_lead_and_a_device_can_be_turned_off(): void
    {
        $token = $this->activate($this->lead);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => 'https://staging.autonord.test', 'Accept' => 'application/json'];   // subdomeniu: merge
        $this->post('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Animație la meniul mobil'], $auth)->assertOk();
        $task = Task::latest('id')->first();
        $this->assertSame(['todo', $this->lead->id], [$task->status->value, $task->assignee_id]);   // de la oricine: la liderul proiectului

        $this->deleteJson('/api/widget/'.$this->project->widget_key.'/me', [], $auth)->assertOk();
        $this->getJson('/api/widget/'.$this->project->widget_key.'/me', $auth)->assertUnauthorized();
    }

    public function test_codes_work_once_only_from_allowed_domains_and_access_is_checked_every_time(): void
    {
        $location = $this->confirmedLocation($this->actingAs($this->dima)->get(route('widget.activate', ['project' => $this->project, 'domain' => 'autonord.test'])));
        $code = substr($location, strpos($location, '=') + 1);
        auth()->logout();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => $code], ['Origin' => 'https://alt-site.md'])->assertForbidden();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => $code], ['Origin' => self::ORIGIN])->assertForbidden();   // codul s-a consumat

        $this->get(route('widget.activate', ['project' => $this->project, 'domain' => 'autonord.test']))->assertRedirect();   // neautentificat: la login
        $stranger = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($stranger)->get(route('widget.activate', ['project' => $this->project, 'domain' => 'autonord.test']))->assertForbidden();

        $token = $this->activate($this->dima);
        $this->project->update(['client_visible' => false]);                                          // clientul nu mai vede proiectul
        $this->getJson('/api/widget/'.$this->project->widget_key.'/me', ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN])->assertUnauthorized();
        $this->assertSame(1, WidgetDevice::count());
    }

    public function test_the_client_talks_on_a_sent_item_sees_the_fix_and_what_is_open_on_the_page(): void
    {
        $token = $this->activate($this->dima);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN];
        $context = ['url' => 'https://autonord.test/contact', 'requests' => [['method' => 'post', 'url' => '/api/contact', 'status' => 500], ['url' => '']]];
        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Formularul nu trimite', 'context' => json_encode($context)], $auth)->assertOk();
        $task = Task::latest('id')->first();
        $this->assertEquals([['method' => 'POST', 'url' => '/api/contact', 'status' => 500]], $task->site_context['requests']);   // assertEquals: MySQL reordonează cheile din JSON      // cererile eșuate, curățate
        $this->actingAs($this->lead)->get(route('filament.admin.resources.tasks.view', $task))->assertSee(['1 failed request', 'POST /api/contact']);
        auth()->logout();

        // deschise pe pagina asta: și fără element arătat
        $this->getJson('/api/widget/'.$this->project->widget_key.'/page?url='.urlencode('https://autonord.test/contact'), $auth)->assertJsonPath('items.0.id', $task->id)->assertJsonPath('items.0.selector', null);

        // conversația și răspunsul din widget
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->lead->id, 'body' => 'Ce mesaj apare?']);
        $base = '/api/widget/'.$this->project->widget_key.'/thread/'.$task->id;
        $this->getJson($base, $auth)->assertOk()->assertJsonPath('comments.0.by', 'Alex')->assertJsonPath('comments.0.mine', false);
        $this->postJson($base, ['text' => 'Niciunul, doar se învârte.'], $auth)->assertOk()->assertJsonPath('comments.1.mine', true);
        $this->assertSame('Niciunul, doar se învârte.', $task->comments()->reorder()->latest('id')->first()->body);
        $this->assertTrue($this->lead->notifications()->where('data', 'like', '%comment%')->exists());

        $foreign = Task::factory()->for(Project::factory()->create())->create(['site_context' => ['path' => '/']]);
        $this->getJson('/api/widget/'.$this->project->widget_key.'/thread/'.$foreign->id, $auth)->assertNotFound();   // nu e a proiectului: nu există

        // închisă cu un link ca dovadă: „Vezi rezolvarea"
        $task->close($this->lead, 'Reparat, vezi https://autonord.test/contact?v=2');
        $this->getJson('/api/widget/'.$this->project->widget_key.'/mine', $auth)->assertJsonPath('items.0.fixed_url', 'https://autonord.test/contact?v=2');
    }

    public function test_feedback_on_the_site_opens_in_the_browser_or_by_a_qr_code_with_or_without_signing_in(): void
    {
        $this->actingAs($this->dima)->get(ProjectResource::getUrl('view', ['record' => $this->project]))
            ->assertSee(['Feedback on the site', 'Open in the browser', 'Invite to give feedback', 'Active invitations']);
        $modal = view('filament.resources.projects.widget-qr', ['url' => 'https://cemainou.test/w/x', 'svg' => '<svg></svg>', 'text' => 't', 'note' => 'n'])->render();
        $this->assertStringContainsString('Copy the link', $modal);                                              // QR-ul, cu linkul lui de copiat
        $this->assertStringContainsString('cemainou.test/w/x', $modal);
        auth()->logout();

        // fără logare: o dată, în numele celui care l-a cerut
        $url = FeedbackWidget::qrUrl($this->dima, $this->project, 'autonord.test');
        $location = $this->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://autonord.test/#wsa=', $location);
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => self::ORIGIN])
            ->assertOk()->assertJsonPath('guest', true);                                                        // un vizitator: își spune numele întâi
        $this->get($url)->assertStatus(410);

        // cu cont: același link ca în browser; nelogat, întâi contul
        $this->get(route('widget.activate', ['project' => $this->project, 'domain' => 'autonord.test']))->assertRedirect();
    }

    public function test_an_invitation_is_reused_until_it_ends_and_stopping_it_stops_its_phones(): void
    {
        $invite = WidgetInvite::make($this->dima, $this->project, 'autonord.test', '30');
        $redeem = function () use ($invite) {
            $location = $this->get($invite->url())->assertRedirect()->headers->get('Location');

            return $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => self::ORIGIN])
                ->assertOk()->assertJsonPath('guest', true)->json('token');
        };
        $first = $redeem();
        $redeem();                                                                                              // refolosibilă: încă un telefon
        $this->assertSame(2, $invite->fresh()->uses);
        $this->assertSame(2, $invite->devices()->count());
        $auth = ['Authorization' => 'Bearer '.$first, 'Origin' => self::ORIGIN];
        $this->postJson('/api/widget/'.$this->project->widget_key.'/guest', ['name' => 'Ion Popescu'], $auth)->assertOk();

        // logat cu acces: intră pe contul lui, nu ca vizitator
        $location = $this->confirmedLocation($this->actingAs($this->lead)->get($invite->url()));
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => self::ORIGIN])
            ->assertOk();
        $this->assertSame([$this->lead->id, false], [WidgetDevice::latest('id')->first()->user_id, WidgetDevice::latest('id')->first()->guest]);
        auth()->logout();

        $invite->forceFill(['revoked_at' => now()])->save();                                                     // oprită: linkul și telefoanele ei
        $this->get($invite->url())->assertStatus(410);
        $this->postJson('/api/widget/'.$this->project->widget_key.'/guest', ['name' => 'Ion Popescu'], $auth)->assertStatus(401);

        $once = WidgetInvite::make($this->dima, $this->project, 'autonord.test', 'once');            // o dată: a doua deschidere nu mai merge
        $this->get($once->url())->assertRedirect();
        $this->get($once->url())->assertStatus(410);
    }

    public function test_the_lead_turns_it_on_from_the_project_header_and_gets_the_code(): void
    {
        $this->project->update(['widget_enabled' => false, 'widget_domains' => null]);
        $member = User::factory()->create(['role' => 'member']);
        Livewire::actingAs($member)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])->assertActionHidden('widgetSetup');   // doar cine poate edita proiectul

        $page = Livewire::actingAs($this->lead)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])->assertActionVisible('widgetSetup');
        $page->callAction('widgetSetup', ['domain' => 'nu e o adresa'])->assertHasActionErrors(['domain']);
        Livewire::actingAs($this->lead)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->callAction('widgetSetup', ['domain' => 'https://www.autonord.test/'])->assertActionMounted('widgetReady');   // după pornire: codul și încercarea
        $project = $this->project->fresh();
        $this->assertTrue($project->widget_enabled);
        $this->assertSame(['autonord.test'], $project->widget_domains);
        $this->assertNotNull($project->widget_key);

        // pornit: meniul de pe domeniu, cu „Oprește" pentru lider
        Livewire::actingAs($this->lead)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])->assertActionHidden('widgetSetup')
            ->callAction('widgetOff0');
        $this->assertFalse($this->project->fresh()->widget_enabled);
    }

    public function test_the_project_page_makes_an_invitation_shows_its_link_and_stops_it(): void
    {
        $page = Livewire::actingAs($this->dima)->test(ViewProject::class, ['record' => $this->project->getRouteKey()]);
        $page->callAction('widgetInvite0', ['duration' => '7'])->assertActionMounted('widgetInviteShow0');
        $invite = WidgetInvite::sole();
        $this->assertSame(7, (int) round(now()->diffInDays($invite->expires_at)));
        Livewire::actingAs($this->dima)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->mountAction('widgetInvite0')->assertActionMounted('widgetInvite0');                                      // a doua oară: linkul tău e arătat direct

        Livewire::actingAs($this->dima)->test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->mountAction('widgetInvites0')->call('revokeInvite', $invite->id);
        $this->actingAs($this->dima);
        $this->assertStringContainsString('Stop', view('filament.resources.projects.widget-invites', ['invites' => collect([$invite->fresh()->load('user')->loadCount('devices')]), 'leads' => false])->render());
        $this->assertNotNull($invite->fresh()->revoked_at);
    }

    public function test_a_guest_from_the_qr_without_signing_in_says_who_they_are_and_sees_only_their_own(): void
    {
        Mail::fake();
        Settings::save(['mail_mailer' => 'smtp', 'mail_host' => 'smtp.test', 'mail_from_address' => 'cemainou@rt.test'], User::factory()->create(['role' => 'admin']));
        $this->withoutDefer();
        $location = $this->get(FeedbackWidget::qrUrl($this->lead, $this->project, 'autonord.test'))->headers->get('Location');
        $redeem = $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => self::ORIGIN])
            ->assertOk()->assertJsonPath('guest', true)->assertJsonPath('needs_name', true);
        $auth = ['Authorization' => 'Bearer '.$redeem->json('token'), 'Origin' => self::ORIGIN];

        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Fără nume'], $auth)->assertStatus(422);   // întâi numele
        $this->postJson('/api/widget/'.$this->project->widget_key.'/guest', ['name' => 'Ion Popescu', 'email' => 'ion@test.md'], $auth)
            ->assertOk()->assertJsonPath('name', 'Ion Popescu · through Alex')->assertJsonPath('needs_name', false);

        // a lui Alex, trimisă din browserul lui: vizitatorul n-o vede
        $own = Task::add($this->project, $this->lead, 'A lui Alex');
        $own->forceFill(['site_context' => ['path' => '/', 'url' => 'https://autonord.test/']])->save();

        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Meniul se suprapune', 'context' => json_encode(['url' => 'https://autonord.test/'])], $auth)->assertOk();
        $task = Task::latest('id')->first();
        $this->assertSame(['name' => 'Ion Popescu', 'email' => 'ion@test.md'], $task->site_context['guest']);
        $this->getJson('/api/widget/'.$this->project->widget_key.'/mine', $auth)->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $task->id);
        $this->getJson('/api/widget/'.$this->project->widget_key.'/thread/'.$own->id, $auth)->assertNotFound();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/thread/'.$task->id, ['text' => 'E pe iPhone'], $auth)->assertOk();
        $this->assertSame('Ion Popescu: E pe iPhone', $task->comments()->reorder()->latest('id')->first()->body);

        $this->actingAs($this->lead)->get(route('filament.admin.resources.tasks.view', $task))->assertSee(['Sent by Ion Popescu', 'ion@test.md']);
        $task->moveTo(TaskStatus::Done, $this->lead);
        Mail::assertSent(GuestFeedbackDone::class, fn ($m) => $m->hasTo('ion@test.md'));
    }

    public function test_a_phone_signed_in_to_cemainou_scanning_the_qr_without_signing_in_starts_as_that_person(): void
    {
        $location = $this->confirmedLocation($this->actingAs($this->dima)->get(FeedbackWidget::qrUrl($this->lead, $this->project, 'autonord.test')));
        auth()->logout();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => self::ORIGIN])
            ->assertOk()->assertJsonPath('name', 'Dima Toma')->assertJsonMissingPath('guest');   // cu cont: fără „Cine ești?"
    }

    public function test_time_in_the_widget_counts_as_time_in_the_app_on_its_project_once_a_minute(): void
    {
        $token = $this->activate($this->lead);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN];
        $this->postJson('/api/widget/'.$this->project->widget_key.'/presence', [], $auth)->assertJsonPath('ok', true);
        $this->postJson('/api/widget/'.$this->project->widget_key.'/presence', [], $auth)->assertJsonPath('ok', true);   // același minut: nu se dublează
        $this->assertSame([[$this->project->id, 'app']], Activity::where('user_id', $this->lead->id)->get()->map(fn ($a) => [$a->project_id, $a->kind->value])->all());

        $client = $this->activate($this->dima);                                                                // clientul: nu se măsoară
        $this->postJson('/api/widget/'.$this->project->widget_key.'/presence', [], ['Authorization' => 'Bearer '.$client, 'Origin' => self::ORIGIN])->assertJsonPath('ok', false);
    }

    public function test_while_typing_the_widget_points_to_a_similar_open_item_instead_of_a_duplicate(): void
    {
        $token = $this->activate($this->dima);
        $auth = ['Authorization' => 'Bearer '.$token, 'Origin' => self::ORIGIN];
        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Butonul de căutare e sub tastatură pe telefon', 'context' => json_encode(['url' => 'https://autonord.test/catalog'])], $auth)->assertOk();
        $first = Task::latest('id')->first();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/feedback', ['text' => 'Logo-ul din footer e prea mic', 'context' => json_encode(['url' => 'https://autonord.test/'])], $auth)->assertOk();

        $similar = fn (string $q, string $url = 'https://autonord.test/catalog') => $this->getJson('/api/widget/'.$this->project->widget_key.'/similar?q='.urlencode($q).'&url='.urlencode($url), $auth);
        $similar('Pe telefon butonul cautare ramane sub tastatura')->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $first->id)->assertJsonPath('items.0.same_page', true);   // fără diacritice, alte cuvinte: tot ea
        $similar('Culoarea meniului e prea închisă')->assertJsonCount(0, 'items');
        $similar('ok')->assertJsonCount(0, 'items');
    }

    public function test_an_address_with_a_path_keeps_the_widget_to_the_pages_under_it(): void
    {
        $this->assertSame('example.test/d/site', FeedbackWidget::address('https://www.example.test/d/site/?x=1'));
        $this->project->update(['widget_domains' => ['https://example.test/d/site/', 'autonord.test']]);
        $this->assertSame(['example.test/d/site', 'autonord.test'], $this->project->fresh()->widget_domains);
        $this->assertSame([['h' => 'example.test', 'p' => '/d/site'], ['h' => 'autonord.test', 'p' => '']], FeedbackWidget::scope($this->project->fresh()));
        $this->assertTrue(FeedbackWidget::allows($this->project->fresh(), 'https://example.test'));          // Origin-ul n-are cale: o verifică scriptul
        $this->get('/api/widget/'.$this->project->widget_key.'.js')->assertSee('"scope":[{"h":"example.test","p":"/d/site"}', false);

        $location = $this->confirmedLocation($this->actingAs($this->lead)->get(route('widget.activate', ['project' => $this->project, 'domain' => 'example.test/d/site'])));
        $this->assertStringStartsWith('https://example.test/d/site/#wsa=', $location);                          // „Feedback pe site" deschide chiar secțiunea
        auth()->logout();
        $this->postJson('/api/widget/'.$this->project->widget_key.'/activate', ['code' => substr($location, strpos($location, '=') + 1)], ['Origin' => 'https://example.test'])->assertOk();
    }
}
