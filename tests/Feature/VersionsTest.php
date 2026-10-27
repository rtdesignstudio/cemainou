<?php

namespace Tests\Feature;

use App\Filament\Pages\WhatsNew;
use App\Mcp\Servers\CemainouServer;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Version;
use ReflectionClass;
use Tests\TestCase;

/** Versiunea e una singură (config, serverul MCP, CHANGELOG.md), iar „Ce e nou" o arată echipei. */
class VersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_is_the_same_everywhere_and_has_its_changelog_section(): void
    {
        $version = config('cemainou.version');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);                                   // MAJOR.MINOR.PATCH

        $mcp = (new ReflectionClass(CemainouServer::class))->getAttributes(Version::class)[0]->newInstance();
        $this->assertSame($version, $mcp->value);
        $changelog = file_get_contents(base_path('CHANGELOG.md'));
        if (str_contains($changelog, '## Unreleased')) {                                                        // repo-ul privat: lista pe versiuni, cu secțiunea fiecărei versiuni
            $this->assertMatchesRegularExpression('/^## '.preg_quote($version, '/').' - \d{4}-\d{2}-\d{2}$/m', $changelog);
        } else {                                                                                                // repo-ul public și arhiva: doar noutățile importante
            $this->assertMatchesRegularExpression('/^## \d+\.\d+\.\d+ - \d{4}-\d{2}-\d{2}$/m', $changelog);
        }
    }

    public function test_the_team_reads_whats_new_and_clients_do_not(): void
    {
        $this->actingAs(User::factory()->create())->get(WhatsNew::getUrl())->assertOk()
            ->assertSee(["What's new", config('cemainou.version')])->assertDontSee("# What's new in cemainou");
        $this->get('/')->assertSee("What's new · ".config('cemainou.version'));                                   // în meniul contului

        $client = User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($client)->get(WhatsNew::getUrl())->assertForbidden();
    }
}
