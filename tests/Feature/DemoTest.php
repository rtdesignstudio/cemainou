<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Project;
use App\Models\Task;
use App\Models\Update;
use App\Models\User;
use App\Support\Demo;
use App\Support\Files;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Instanța demo publică: intrarea dintr-un clic ca unul dintre oamenii inventați (niciun admin), fără fișiere, iar refacerea doar în demo. */
class DemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_outside_the_demo_nothing_of_it_exists(): void
    {
        $this->seed(DemoSeeder::class);
        $ana = User::where('email', 'ana@cemainou.test')->sole();

        $this->get('/login')->assertOk()->assertDontSee('Try the demo as');
        $this->post(route('demo.enter', $ana))->assertNotFound();
        $this->artisan('cemainou:demo-reset')->assertFailed();                    // nu șterge niciodată datele unei echipe reale
        $this->assertTrue(User::where('email', 'ana@cemainou.test')->exists());
    }

    public function test_the_english_demo_has_its_made_up_content_in_english(): void
    {
        config(['cemainou.demo' => true, 'cemainou.demo_locale' => 'en']);
        $this->seed(DemoSeeder::class);

        $this->assertTrue(Project::where('name', 'Example Winery rebrand')->exists());
        $this->assertFalse(Project::where('name', 'like', '%Vinăria%')->exists());
        $this->assertTrue(Task::where('title', 'Logo, 3 options')->exists());
        $this->assertTrue(Update::where('summary', 'Logo in burgundy and gold, approved by the client')->exists());
    }

    public function test_in_the_demo_anyone_enters_as_a_made_up_person_but_never_as_an_admin(): void
    {
        config(['cemainou.demo' => true]);
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'sef@cemainou.test']);
        $mihai = User::where('email', 'mihai@cemainou.test')->sole();

        $this->get('/login')->assertOk()->assertSee(['Try the demo as', 'Ana Popescu', 'Mihai Rotaru', 'Elena de la Vinăria'])->assertDontSee('sef@cemainou.test')
            ->assertDontSee('type="password"', false);                                          // fără email și parolă: doar oamenii demo
        $this->get('/login?lang=ro')->assertOk()->assertSee('Intră în demo ca');                // limba paginii de pe care vine
        $this->post(route('demo.enter', $admin))->assertNotFound();
        $this->post(route('demo.enter', $mihai))->assertRedirect('/');
        $this->assertAuthenticatedAs($mihai);

        $rebrand = Project::where('slug', 'rebranding-vinaria-exemplu')->sole();
        $this->assertTrue($mihai->can('view', $rebrand));                          // colaboratorul vede proiectul lui
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Demo cu date inventate');   // limba aleasă la intrare (?lang=ro) rămâne după intrare

        $this->expectException(HttpException::class);
        Files::attach($rebrand, $mihai, 'x', 'nota.txt');                          // demo-ul nu găzduiește fișiere
    }

    public function test_a_demo_admin_exists_only_on_the_local_computer_when_asked(): void
    {
        config(['cemainou.demo' => true, 'cemainou.demo_admin' => true]);
        $this->assertFalse(Demo::withAdmin());                                                      // nu pe un server: testele nu rulează „local"

        $this->app['env'] = 'local';
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@cemainou.test')->sole();
        $this->assertTrue(Demo::allowed($admin));
        $this->assertTrue(Demo::people()->contains('id', $admin->id));                             // ultimul la intrarea dintr-un clic

        config(['cemainou.demo_admin' => false]);
        $this->assertFalse(Demo::allowed($admin));
    }
}
