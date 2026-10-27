<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\ClientConnectAgent;
use App\Filament\Widgets\ClientSiteFeedback;
use App\Filament\Widgets\GettingStarted;
use App\Mail\Invitation;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Dismissal;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Primirea clientului: invitația cu textul lui și proiectele lui, lista „Primii pași" de pe Azi cu pașii lui. */
class ClientOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $dima;

    private Project $site;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Client::factory()->create(['name' => 'Auto Nord']);
        $this->site = Project::factory()->create(['name' => 'Site nou', 'client_id' => $company->id, 'client_visible' => true]);
        Project::factory()->create(['name' => 'Proiect intern', 'client_id' => $company->id, 'client_visible' => false]);
        Project::factory()->create(['name' => 'Alt client']);
        $this->dima = User::factory()->create(['name' => 'Dima Toma', 'role' => 'client', 'client_id' => $company->id, 'invited_at' => now()]);
    }

    public function test_the_client_invitation_tells_what_he_can_do_and_names_only_his_projects(): void
    {
        $radu = User::factory()->create(['name' => 'Radu', 'role' => 'admin']);
        $html = (new Invitation($this->dima, $radu, 'https://x.test/invitation/abc'))->render();
        $this->assertStringContainsString('you follow your projects with the team', $html);
        $this->assertStringContainsString('Site nou', $html);
        $this->assertStringNotContainsString('Proiect intern', $html);
        $this->assertStringNotContainsString('Alt client', $html);
        $this->assertStringNotContainsString('written by our AI agents', $html);

        $ana = User::factory()->create(['role' => 'member', 'invited_at' => now()]);
        $this->assertStringContainsString('written by our AI agents', (new Invitation($ana, $radu, 'https://x.test/i'))->render());
    }

    public function test_the_client_gets_his_own_getting_started_steps(): void
    {
        $this->actingAs($this->dima);
        $this->assertTrue(GettingStarted::canView());
        $this->assertSame(['request', 'file', 'phone', 'notifications'], collect(GettingStarted::steps())->pluck('key')->all());

        Task::factory()->for($this->site)->create(['created_by' => $this->dima->id]);
        Attachment::create(['project_id' => $this->site->id, 'user_id' => $this->dima->id, 'kind' => 'link', 'url' => 'https://figma.com/x', 'title' => 'Machete']);
        $this->assertSame(['request', 'file'], collect(GettingStarted::steps())->where('done', true)->pluck('key')->values()->all());
        $this->get('/')->assertOk()->assertSee(['Getting started', 'Ask the team for something', 'Add a file to your project'])->assertDontSee('Send your first update');

        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));
        $this->assertNotContains('agent', collect(GettingStarted::steps())->pluck('key')->all());   // agentul are cardul lui, cu pașii
    }

    public function test_the_agent_card_shows_the_steps_and_goes_away_when_closed_or_connected(): void
    {
        $this->actingAs($this->dima);
        $this->assertFalse(ClientConnectAgent::canView());                                   // adminul n-a permis agenții clienților
        Settings::save(['clients_use_agents' => true], User::factory()->create(['role' => 'admin']));
        $this->assertTrue(ClientConnectAgent::canView());
        $this->get('/')->assertOk()->assertSee(['Connect your agent', 'claude mcp add --transport http --scope user cemainou', 'Add custom connector'], false);

        Livewire::test(ClientConnectAgent::class)->call('hide');
        $this->assertFalse(ClientConnectAgent::canView());
        $this->get('/')->assertDontSee('claude mcp add', false);

        Dismissal::query()->delete();
        $this->dima->createToken('Claude Code', ['mcp']);
        $this->assertFalse(ClientConnectAgent::canView());                                   // conectat: dispare singur
    }

    public function test_with_the_widget_on_the_client_gets_the_site_feedback_card_and_step(): void
    {
        $this->actingAs($this->dima);
        $this->assertFalse(ClientSiteFeedback::canView());
        $this->site->update(['widget_enabled' => true, 'widget_domains' => ['example.test/d/site']]);
        $this->assertTrue(ClientSiteFeedback::canView());
        $this->assertSame(['request', 'site', 'file', 'phone', 'notifications'], collect(GettingStarted::steps())->pluck('key')->all());
        $this->get('/')->assertOk()->assertSee(['Feedback on the site', 'Open example.test/d/site with the button', 'Link and QR'])
            ->assertSee(route('widget.activate', ['project' => $this->site, 'domain' => 'example.test/d/site']), false);

        $task = Task::factory()->for($this->site)->create(['created_by' => $this->dima->id, 'title' => 'Logo-ul e prea mic', 'site_context' => ['url' => 'https://example.test/d/site/']]);
        $this->assertTrue(collect(GettingStarted::steps())->firstWhere('key', 'site')['done']);
        $this->get('/')->assertSee(['Logo-ul e prea mic', '1 sent']);
        Livewire::test(ClientSiteFeedback::class)->call('showQr', $this->site->id.'|example.test/d/site')->assertSee('<svg', false)->assertSee('Copy the link')
            ->call('showQr', $this->site->id.'|alt-site.md')->assertDontSee('Scan it with the phone camera');   // doar adresele proiectului
    }

    public function test_a_task_for_the_client_on_a_project_not_visible_to_him_says_what_to_turn_on_and_his_page_shows_what_he_sees(): void
    {
        $radu = User::factory()->create(['role' => 'admin']);
        $hidden = Project::where('name', 'Proiect intern')->first();
        try {
            Task::add($hidden, $radu, 'Textele', assignee: $this->dima);
            $this->fail('A trebuit să refuze');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('turn on "Visible to client"', $e->getMessage());
        }
        $this->actingAs($radu)->get(UserResource::getUrl('edit', ['record' => $this->dima]))->assertOk()
            ->assertSee('Sees: Site nou.')->assertSee('Proiect intern', false);
    }

    public function test_the_client_can_get_tasks_on_a_restricted_project_of_his_company_that_he_sees(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $tourism = Project::factory()->create(['name' => 'Turism local', 'client_id' => $this->dima->client_id, 'client_visible' => true, 'restricted' => true, 'lead_id' => $lead->id]);
        $this->assertTrue($this->dima->canSeeProject($tourism));
        $this->assertTrue(User::assignableOn($tourism)->whereKey($this->dima->id)->exists());
        $this->assertTrue(User::assignableOn($tourism)->whereKey($lead->id)->exists());
        $this->assertFalse(User::assignableOn($tourism)->whereKey(User::factory()->create(['role' => 'member'])->id)->exists());   // restrâns: un coleg din afara listei, nu
        $this->assertSame($this->dima->id, Task::add($tourism, $lead, 'Trimite textele', assignee: $this->dima)->assignee_id);
    }

    public function test_a_new_account_starts_in_the_team_language(): void
    {
        Settings::save(['locale' => 'ro'], User::factory()->create(['role' => 'admin']));
        $this->assertSame('ro', User::factory()->create()->locale);
        $this->assertSame('en', User::factory()->create(['locale' => 'en'])->locale);              // aleasă anume: rămâne
    }
}
