<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Livewire\ProjectDocuments;
use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddTimeTool;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use App\Support\WorkTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Tests\TestCase;

/** Compartimentul adminului: setările sistemului, salvate din pagina Setări și folosite peste tot în locul valorilor fixe. */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Chisinau';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'timezone' => self::TZ]);
    }

    private function set(array $values): void
    {
        Settings::save($values, $this->admin);
    }

    public function test_only_admins_reach_the_settings_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get(SystemSettings::getUrl())->assertForbidden();
        $database = ['sqlite' => 'SQLite', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL'][\DB::connection()->getDriverName()];
        $this->actingAs($this->admin)->get(SystemSettings::getUrl())->assertOk()->assertSee(['Settings', 'A pause counts as work up to', 'System', $database]);
    }

    public function test_the_page_shows_the_defaults_saves_typed_values_and_refuses_bad_ones(): void
    {
        $page = Livewire::actingAs($this->admin)->test(SystemSettings::class)
            ->assertSet('data.gap_minutes', 15)->assertSet('data.allow_html', true);

        $page->set('data.gap_minutes', '2')->call('save')->assertHasErrors('data.gap_minutes');
        $this->assertSame(0, Setting::where('key', '!=', 'installation_id')->count());   // numărul instalării se face la prima vedere a paginii (Licență)

        $page->set('data.gap_minutes', '30')->set('data.locale', 'ro')->call('save')->assertHasNoErrors();
        $this->assertSame([30, 'ro'], [Settings::get('gap_minutes'), Settings::get('locale')]);
        $this->assertSame(30, Setting::find('gap_minutes')->value);                                               // număr, nu text
        $this->assertSame($this->admin->id, Setting::find('gap_minutes')->updated_by);
    }

    public function test_the_pause_that_counts_as_work_comes_from_the_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));
        foreach (['10:00', '10:25'] as $time) {
            Activity::create(['user_id' => $this->admin->id, 'kind' => 'tool', 'source' => 'claude-code', 'occurred_at' => Carbon::parse("2026-10-08 {$time}", self::TZ)->utc()]);
        }

        $this->assertSame(0, WorkTime::forDay($this->admin)['active_minutes']);                                   // 25 de minute > 15
        $this->set(['gap_minutes' => 30]);
        $this->assertSame(25, WorkTime::forDay($this->admin)['active_minutes']);
    }

    public function test_how_far_back_time_can_be_added_comes_from_the_settings(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00', self::TZ));
        Project::factory()->create(['slug' => 'auto-nord']);
        $add = fn () => CemainouServer::actingAs($this->admin)->tool(AddTimeTool::class, ['project' => 'auto-nord', 'minutes' => 30, 'date' => '2026-10-01']);

        $this->set(['report_days' => 3]);
        $add()->assertHasErrors(['last 3 days']);
        $this->set(['report_days' => 14]);
        $add()->assertOk();
    }

    public function test_history_goes_back_600_days_and_can_reach_1000(): void
    {
        $this->assertSame(600, Settings::get('history_days'));
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->set('data.history_days', '1000')->call('save')->assertHasNoErrors();
        Livewire::actingAs($this->admin)->test(SystemSettings::class)->set('data.history_days', '1001')->call('save')->assertHasErrors('data.history_days');
    }

    public function test_file_size_and_html_uploads_follow_the_settings(): void
    {
        Storage::fake(Attachment::DISK);
        $project = Project::factory()->create();
        $upload = fn (UploadedFile $file) => Livewire::actingAs($this->admin)->test(ProjectDocuments::class, ['project' => $project])->set('file', $file)->call('saveFile');

        $this->set(['max_upload_mb' => 1, 'allow_html' => false]);
        $upload(UploadedFile::fake()->create('mare.pdf', 1100))->assertHasErrors('file');
        $upload(UploadedFile::fake()->createWithContent('deck.html', '<h1>x</h1>'))->assertHasErrors('file');
        $upload(UploadedFile::fake()->create('mic.pdf', 900))->assertHasNoErrors();

        $this->assertSame(['mic.pdf'], Attachment::pluck('name')->all());
    }

    public function test_new_people_and_passwords_follow_the_settings(): void
    {
        $this->set(['timezone' => 'Europe/Bucharest', 'locale' => 'ro', 'password_min' => 16]);

        Livewire::actingAs($this->admin)->test(CreateUser::class)
            ->assertSchemaStateSet(['timezone' => 'Europe/Bucharest', 'locale' => 'ro']);
        $this->assertTrue(Validator::make(['p' => 'scurta-123456'], ['p' => Password::defaults()])->fails());   // 13 < 16
        $this->assertTrue(Validator::make(['p' => 'destul-de-lunga-1'], ['p' => Password::defaults()])->passes());
    }

    public function test_the_sign_in_page_speaks_the_teams_language(): void
    {
        $this->get('/login')->assertSee('Sign in');
        $this->set(['locale' => 'ro']);
        $this->get('/login')->assertSee('Intră în cont');
    }
}
