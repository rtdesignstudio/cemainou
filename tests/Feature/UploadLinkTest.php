<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Mcp\Tools\UploadLinkTool;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\UploadLink;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * upload_link: agentul cere un link, omul îl deschide pe telefon (fără logare) și pune până la 6 fișiere pe sarcină sau pe proiect, în numele lui;
 * linkul merge o dată și 30 de minute, nu arată nimic din proiect, iar tokenul se păstrează doar ca hash.
 */
class UploadLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $radu;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        $this->radu = User::factory()->create(['name' => 'Radu Rusu', 'role' => 'admin']);
        $this->task = Task::factory()->for(Project::factory()->create(['name' => 'Pro Drive', 'slug' => 'pro-drive']))->create(['title' => 'Emailul lui Kristen']);
    }

    private function link(array $args = []): string
    {
        $url = null;
        CemainouServer::actingAs($this->radu)->tool(UploadLinkTool::class, $args ?: ['task' => $this->task->id])->assertOk()
            ->assertSee(['/u/', 'works once'])->assertStructuredContent(function ($json) use (&$url) {
                $json->where('url', function (string $value) use (&$url) {
                    $url = $value;

                    return true;
                })->etc();
            });

        return $url;
    }

    public function test_the_person_uploads_from_the_phone_onto_the_task_once(): void
    {
        $url = $this->link();
        $this->assertStringNotContainsString(basename($url), UploadLink::sole()->token_hash);                    // doar hash-ul în baza de date

        $this->get($url)->assertOk()->assertSee(['Add files', 'Emailul lui Kristen', 'Pro Drive', 'Radu Rusu']);
        $this->post($url, ['files' => [UploadedFile::fake()->image('email.png'), UploadedFile::fake()->create('factura.pdf', 200, 'application/pdf')]])
            ->assertRedirect($url);
        $this->get($url)->assertSee('2 files added');

        $files = Attachment::orderBy('id')->get();
        $this->assertSame([[$this->task->id, $this->radu->id, 'email.png'], [$this->task->id, $this->radu->id, 'factura.pdf']],
            $files->map(fn ($f) => [$f->task_id, $f->user_id, $f->name])->all());

        $this->get($url)->assertOk()->assertSee('This link no longer works');                                    // o singură dată
        $this->post($url, ['files' => [UploadedFile::fake()->image('inca.png')]])->assertStatus(410);
        $this->assertSame(2, Attachment::count());
    }

    public function test_an_old_wrong_or_project_link_and_bad_files(): void
    {
        $url = $this->link(['project' => 'pro-drive']);
        $this->post($url, ['files' => [UploadedFile::fake()->create('virus.exe', 5)]])->assertSessionHasErrors('files.0');
        $this->post($url, ['files' => array_fill(0, 7, UploadedFile::fake()->image('x.png'))])->assertSessionHasErrors('files');
        $this->post($url, ['files' => [UploadedFile::fake()->image('ecran.png')]])->assertRedirect($url);
        $this->assertNull(Attachment::sole()->task_id);                                                          // pe proiect

        $old = $this->link();
        $this->travel(UploadLink::MINUTES + 1)->minutes();
        $this->get($old)->assertSee('This link no longer works');
        $this->get('/u/'.str_repeat('a', 40))->assertSee('This link no longer works');
    }

    public function test_a_client_gets_a_link_only_for_what_they_may_touch(): void
    {
        $maria = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        CemainouServer::actingAs($maria)->tool(UploadLinkTool::class, ['task' => $this->task->id])->assertHasErrors(["There is no task #{$this->task->id}."]);   // clientul altei firme: sarcina nici nu există pentru el
        $this->assertSame(0, UploadLink::count());
    }

    public function test_the_page_speaks_the_teams_language_without_sign_in(): void
    {
        Settings::save(['locale' => 'ro'], $this->radu);
        $this->get('/u/'.str_repeat('a', 40))->assertOk()->assertSee('Linkul nu mai merge');
    }

    public function test_an_agent_with_a_shell_uploads_the_file_itself_with_curl_once_and_knows_the_real_limits(): void
    {
        Settings::save(['max_upload_mb' => 50], $this->radu);
        $this->assertStringContainsString('50 MB', (new UploadLinkTool)->description());
        $this->assertStringContainsString('50 MB with source_url', (new AddAttachmentTool)->description());
        $token = basename($this->link());
        $api = '/api/upload/'.$token;

        $this->post($api, ['files' => [UploadedFile::fake()->create('macheta.pdf', 30 * 1024, 'application/pdf')]], ['User-Agent' => 'curl/8.7.1'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('files.0.name', 'macheta.pdf');   // 30 MB: peste cei 10 de prin conversație, sub cei 50
        $this->assertSame(['macheta.pdf'], $this->task->attachments()->pluck('name')->all());
        $this->post($api, ['files' => [UploadedFile::fake()->create('alt.pdf', 10)]])->assertStatus(410);   // o singură dată

        $other = basename($this->link());
        $this->post('/api/upload/'.$other, ['files' => [UploadedFile::fake()->create('mare.pdf', 51 * 1024)]])->assertStatus(422)->assertJsonPath('max_file_mb', 50);
    }
}
