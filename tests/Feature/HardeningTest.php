<?php

namespace Tests\Feature;

use App\Exceptions\KeepsHistory;
use App\Exceptions\LastAdmin;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\ProjectTasks;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/** Corecțiile din auditul de securitate (7 oct 2026). */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_repo_addresses_are_stored_without_passwords_or_tokens(): void
    {
        $project = Project::factory()->create(['repo_url' => 'https://oauth2:glpat-secret@gitlab.com/firma/site.git']);
        $this->assertSame('https://oauth2@gitlab.com/firma/site.git', $project->repo_url);
        $this->assertSame('gitlab.com/firma/site', $project->repo_key);

        $repo = $project->repos()->create(['url' => 'https://x-access-token:ghp_secret@github.com/firma/site-api', 'status' => 'pending']);
        $this->assertSame('https://x-access-token@github.com/firma/site-api', $repo->url);
        $this->assertSame('git@github.com:firma/site.git', Project::cleanRepoUrl('git@github.com:firma/site.git'));   // forma scp: nimic de scos
        $this->assertNull(Project::cleanRepoUrl('  '));
    }

    public function test_demo_data_is_refused_in_production_and_the_default_seeder_creates_nothing(): void
    {
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);
        $this->assertSame(0, User::count());

        $this->app->detectEnvironment(fn () => 'production');
        Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
        $this->assertSame(0, User::count());
        $this->assertStringContainsString('only for local', Artisan::output());
    }

    public function test_the_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->expectException(LastAdmin::class);
        $admin->update(['is_active' => false]);
    }

    public function test_an_admin_can_step_down_while_another_active_admin_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $admin->update(['role' => 'member']);
        $this->assertSame('member', $admin->fresh()->role->value);

        $this->expectException(LastAdmin::class);
        $other->update(['role' => 'member']);                      // acum e ultimul
    }

    public function test_the_edit_page_explains_why_the_last_admin_stays_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['role' => 'member'])->call('save')->assertNotified(__('The team needs at least one active admin.'));
        $this->assertSame('admin', $admin->fresh()->role->value);
    }

    public function test_passwords_need_at_least_twelve_characters(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Nou', 'email' => 'nou@cemainou.test', 'role' => 'member', 'timezone' => 'Europe/Chisinau', 'locale' => 'ro', 'password' => 'scurt123'])
            ->call('create')->assertHasFormErrors(['password']);
        $this->assertSame(1, User::count());
    }

    public function test_switching_the_language_only_redirects_inside_the_app(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/locale/ro', ['Referer' => 'https://evil.example/phish'])->assertRedirect(url('/'));
        $this->get('/locale/ro', ['Referer' => url('/tasks')])->assertRedirect(url('/tasks'));
        $this->get('/locale/xx')->assertNotFound();
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")->assertHeaderMissing('Strict-Transport-Security');   // HSTS doar pe https
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_requests_without_a_key_are_rate_limited_per_address(): void
    {
        config(['cemainou.mcp_ip_per_minute' => 3]);
        app()->forgetInstance(Settings::class);           // setările își țin definițiile pe cerere: valoarea implicită nouă din config
        $call = fn () => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        for ($i = 0; $i < 3; $i++) {
            $call()->assertUnauthorized();
        }
        $call()->assertTooManyRequests();
        $this->postJson('/api/heartbeat', ['event' => 'Stop'])->assertTooManyRequests();   // aceeași limită, aceeași adresă
    }

    public function test_a_client_with_projects_cannot_be_deleted_even_from_code(): void
    {
        $client = Client::factory()->create();
        Project::factory()->create(['client_id' => $client->id]);

        $this->expectException(KeepsHistory::class);
        $client->delete();
    }

    public function test_tasks_cannot_be_given_to_inactive_people(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        Livewire::test(ProjectTasks::class, ['project' => $project])->set('data.text', 'Ceva')->set('assignee', (string) $inactive->id)->call('add')->assertHasErrors(['assignee']);
        $this->assertSame(0, Task::count());
    }
}
