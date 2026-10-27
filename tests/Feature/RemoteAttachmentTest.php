<?php

namespace Tests\Feature;

use App\Mcp\Servers\CemainouServer;
use App\Mcp\Tools\AddAttachmentTool;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\RemoteFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * add_attachment cu source_url: serverul descarcă fișierul de la un link public (imagine, PDF, text), pe proiect sau pe o sarcină;
 * nimic de la adrese private, localhost sau prin redirect spre ele, nimic peste limită sau de alt tip.
 */
class RemoteAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $radu;

    private Project $proDrive;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        $this->radu = User::factory()->create(['role' => 'admin']);
        $this->proDrive = Project::factory()->create(['name' => 'Pro Drive', 'slug' => 'pro-drive']);
        RemoteFile::$resolver = fn (string $host) => match ($host) {
            'intern.test' => ['10.0.0.5'],
            'rebind.test' => ['93.184.216.34', '127.0.0.1'],
            default => ['93.184.216.34'],
        };
    }

    protected function tearDown(): void
    {
        RemoteFile::$resolver = null;
        parent::tearDown();
    }

    private function attach(array $args)
    {
        return CemainouServer::actingAs($this->radu)->tool(AddAttachmentTool::class, ['project' => 'pro-drive'] + $args);
    }

    public function test_a_public_image_is_downloaded_onto_a_task(): void
    {
        Http::fake(['cdn.example.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $task = Task::factory()->for($this->proDrive)->create();

        $this->attach(['source_url' => 'https://cdn.example.com/capturi/email-kristen.png', 'task' => $task->id])->assertOk()->assertSee('Downloaded and added');
        $file = Attachment::sole();
        $this->assertSame([$task->id, 'email-kristen.png'], [$file->task_id, $file->name]);
        Storage::disk('data')->assertExists($file->path);
    }

    public function test_a_google_drive_share_link_becomes_a_download_and_the_name_comes_from_the_server(): void
    {
        Http::fake(['drive.google.com/*' => Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="oferta.pdf"'])]);

        $this->attach(['source_url' => 'https://drive.google.com/file/d/1AbC_d-9/view?usp=sharing', 'title' => 'Oferta'])->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://drive.google.com/uc?export=download&id=1AbC_d-9');
        $this->assertSame(['oferta.pdf', 'Oferta'], [Attachment::sole()->name, Attachment::sole()->title]);
    }

    public function test_private_addresses_and_redirects_to_them_are_refused(): void
    {
        Http::fake([
            'intern.test/*' => Http::response('secret'),
            'short.example.com/*' => Http::response('', 302, ['Location' => 'https://intern.test/admin']),
        ]);

        foreach ([
            'https://intern.test/x.png' => 'private or local address',
            'https://127.0.0.1/x.png' => 'private or local address',
            'https://[::1]/x.png' => 'private or local address',
            'https://localhost/x.png' => 'private or local address',
            'https://rebind.test/x.png' => 'private or local address',              // una din adrese e locală: refuz
            'https://short.example.com/x' => 'private or local address',            // redirect spre o adresă internă
            'http://cdn.example.com/x.png' => 'Only https',
            'https://cdn.example.com:8443/x.png' => 'standard https port',
            'https://user:pass@cdn.example.com/x.png' => 'user or password',
        ] as $url => $why) {
            $this->attach(['source_url' => $url])->assertHasErrors([$why]);
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'intern.test'));
        $this->assertSame(0, Attachment::count());
    }

    public function test_too_large_missing_or_another_kind_of_file_gives_a_clear_error(): void
    {
        Http::fake([
            'cdn.example.com/mare.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Length' => (string) (21 * 1024 * 1024)]),
            'cdn.example.com/lipsa.png' => Http::response('', 404),
            'cdn.example.com/arhiva' => Http::response("PK\x03\x04".str_repeat("\0", 40), 200, ['Content-Type' => 'application/zip']),
            'drive.google.com/*' => Http::response('<!doctype html><html><head><title>Sign in</title></head><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->attach(['source_url' => 'https://cdn.example.com/mare.png'])->assertHasErrors(['larger than 20 MB']);   // limita din Setări (implicit 20)
        $this->attach(['source_url' => 'https://cdn.example.com/lipsa.png'])->assertHasErrors(['answered 404']);
        $this->attach(['source_url' => 'https://cdn.example.com/arhiva'])->assertHasErrors(['not accepted from a link']);
        $this->attach(['source_url' => 'https://drive.google.com/file/d/privat/view'])->assertHasErrors(['web page, not the file']);   // Drive nepartajat
        $this->attach(['source_url' => 'https://cdn.example.com/x.png', 'base64' => self::PNG])->assertHasErrors(['leave out url, text and base64']);
        $this->assertSame(0, Attachment::count());
    }
}
