<?php

namespace Tests\Feature;

use App\Enums\AttachmentKind;
use App\Livewire\ProjectDocuments;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Support\Files;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Fișierele și linkurile unui proiect: păstrate în data/files, servite doar celor logați; HTML și SVG izolate; șterse doar de cine are voie. */
class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->project = Project::factory()->create(['name' => 'Auto Nord']);
    }

    private function block(?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->ana)->test(ProjectDocuments::class, ['project' => $this->project]);
    }

    public function test_an_uploaded_file_is_kept_in_data_and_listed_on_the_project(): void
    {
        $this->block()->call('open', 'file')->set('file', UploadedFile::fake()->createWithContent('Prezentare client.html', '<h1>Salut</h1>'))
            ->set('title', 'Prezentarea pentru client')->call('saveFile')->assertHasNoErrors()
            ->assertSee(['Prezentarea pentru client', 'HTML', 'Ana']);

        $file = Attachment::sole();
        $this->assertSame([AttachmentKind::File, 'Prezentare client.html', 14], [$file->kind, $file->name, $file->size]);
        $this->assertStringStartsWith($this->project->id.'/', $file->path);
        Storage::disk(Attachment::DISK)->assertExists($file->path);
    }

    public function test_only_known_kinds_of_files_are_accepted(): void
    {
        $this->block()->set('file', UploadedFile::fake()->create('script.exe', 10))->call('saveFile')->assertHasErrors('file');
        $this->block()->set('file', UploadedFile::fake()->create('mare.pdf', Files::maxKb() + 1))->call('saveFile')->assertHasErrors('file');

        $this->assertSame(0, Attachment::count());
    }

    public function test_a_link_needs_a_title_and_a_web_address(): void
    {
        $this->block()->call('open', 'link')->set('title', 'Machete Figma')->set('url', 'javascript:alert(1)')->call('addLink')->assertHasErrors('url');
        $this->block()->call('open', 'link')->set('title', 'Machete Figma')->set('url', 'https://www.figma.com/design/abc')->call('addLink')
            ->assertHasNoErrors()->assertSee(['Machete Figma', 'figma.com']);

        $this->assertSame(AttachmentKind::Link, Attachment::sole()->kind);
    }

    public function test_html_and_svg_open_isolated_images_inline_and_the_rest_as_downloads(): void
    {
        $html = Files::attach($this->project, $this->ana, '<script>fetch("/")</script>', 'deck.html');
        $png = Files::attach($this->project, $this->ana, 'png', 'logo.png');
        $zip = Files::attach($this->project, $this->ana, 'zip', 'surse.zip');

        $deck = $this->actingAs($this->ana)->get(route('files.show', $html))->assertOk();
        $this->assertStringStartsWith('text/html', $deck->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox allow-scripts', $deck->headers->get('Content-Security-Policy'));   // fără allow-same-origin: origine opacă
        $this->assertStringNotContainsString('allow-same-origin', $deck->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('inline', $deck->headers->get('Content-Disposition'));

        $image = $this->get(route('files.show', $png))->assertOk();
        $this->assertSame(['image/png', 'nosniff'], [$image->headers->get('Content-Type'), $image->headers->get('X-Content-Type-Options')]);
        $this->assertSame("frame-ancestors 'none'", $image->headers->get('Content-Security-Policy'));

        $this->assertStringStartsWith('attachment', $this->get(route('files.show', $zip))->headers->get('Content-Disposition'));
    }

    public function test_files_are_served_only_to_people_signed_in_and_active(): void
    {
        $file = Files::attach($this->project, $this->ana, 'png', 'logo.png');

        $this->get(route('files.show', $file))->assertRedirect(route('filament.admin.auth.login'));
        $this->actingAs(User::factory()->create(['is_active' => false]))->get(route('files.show', $file))->assertNotFound();
    }

    public function test_only_the_uploader_the_lead_or_an_admin_delete_and_the_file_goes_too(): void
    {
        $file = Files::attach($this->project, $this->ana, 'png', 'logo.png');

        $this->block(User::factory()->create())->call('remove', $file->id)->assertForbidden();
        $this->block()->call('remove', $file->id);

        $this->assertSame(0, Attachment::count());
        Storage::disk(Attachment::DISK)->assertMissing($file->path);
    }
}
