<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Filament\Pages\Shared;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** „Share" din telefon spre cemainou (share_target): se pune deoparte, omul alege ce devine; comenzile scurte /idea și /task. */
class ShareAndCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shared_screenshot_becomes_my_task_with_its_file_in_two_taps(): void
    {
        Storage::fake('data');
        $ion = User::factory()->create();
        $site = Project::factory()->create(['name' => 'Site']);
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('/share', $manifest['share_target']['action']);

        $back = $this->actingAs($ion)->post('/share', ['title' => 'Galeria la Studio Arc', 'text' => 'cu swipe', 'url' => 'https://example.com/x',
            'files' => [UploadedFile::fake()->image('ecran.png')]]);
        parse_str(parse_url($back->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['id']);

        $this->get(route('share.file', ['id' => $q['id'], 'index' => 0]))->assertOk();                      // previzualizarea capturii, din sesiunea lui
        Livewire::withQueryParams(['id' => $q['id']])->test(Shared::class)
            ->assertSet('text', "Galeria la Studio Arc\ncu swipe\nhttps://example.com/x")->assertSet('kind', 'task')->assertSet('mine', true)   // o captură: sarcină pentru mine
            ->assertSeeHtml(route('share.file', ['id' => $q['id'], 'index' => 0]))
            ->set('project', $site->id)->call('save')->assertHasNoErrors();
        $task = Task::sole();
        $this->assertSame([TaskStatus::Todo, 'Galeria la Studio Arc', 1, $ion->id], [$task->status, $task->title, $task->attachments()->count(), $task->assignee_id]);
        $this->assertSame([], Storage::disk('data')->allFiles('shared'));                                     // ce a fost pus deoparte, curățat
    }

    public function test_clients_cannot_share_in_and_the_short_commands_are_installed(): void
    {
        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($maria)->post('/share', ['text' => 'x'])->assertForbidden();

        $this->get('/agent/idea.md')->assertOk();
        $this->get('/agent/task.md')->assertOk();
        $this->assertStringContainsString('idea: true', file_get_contents(resource_path('agent/idea.md')));
        $this->assertStringContainsString('due as YYYY-MM-DD', file_get_contents(resource_path('agent/task.md')));
        $install = file_get_contents(resource_path('agent/install.sh'));
        $this->assertStringContainsString('idea task', $install);
    }
}
