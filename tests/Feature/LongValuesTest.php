<?php

namespace Tests\Feature;

use App\Mcp\Tools\TeamTool;
use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Valorile la limită încap în coloane: SQLite primește orice lungime, MySQL / MariaDB (strict) opresc salvarea.
 * Pe SQLite testele trec oricum; rost au pe MySQL și MariaDB (scripts/test-db.sh).
 */
class LongValuesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_long_repository_address_is_saved(): void
    {
        $url = 'https://gitlab.firma.md/'.str_repeat('grup-foarte-lung/', 25).'proiect.git';   // ~450 de caractere
        $project = Project::factory()->create(['repo_url' => $url]);
        $project->repos()->create(['url' => $url.'x', 'status' => 'active']);

        $this->assertSame($url, $project->fresh()->repo_url);
        $this->assertGreaterThan(255, strlen($project->fresh()->repo_key));
    }

    public function test_tokens_longer_than_an_address_are_not_repository_candidates(): void
    {
        $this->assertSame(['git@github.com:rt/app.git'], TeamTool::repoCandidates('origin git@github.com:rt/app.git (fetch) https://x.md/'.str_repeat('a', 600)));
    }

    public function test_slugs_that_grow_longer_than_the_name_still_fit(): void
    {
        $name = str_repeat('щ', 255);                                             // „shch": slug-ul de 4 ori mai lung
        $this->assertLessThanOrEqual(255, strlen(Client::create(['name' => $name])->slug));
        $this->assertLessThanOrEqual(255, strlen(Project::factory()->create(['name' => $name])->slug));

        $project = Project::factory()->create();
        $user = User::factory()->create();
        $first = Document::start($project, $user, str_repeat('Щука ', 30));
        $second = Document::start($project, $user, str_repeat('Щука ', 30));
        $this->assertLessThanOrEqual(140, strlen($first->slug));
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_texts_and_emoji_survive_as_they_were_written(): void
    {
        $project = Project::factory()->create(['name' => 'Ăîșț 🚀 Привет', 'description' => str_repeat('Descriere lungă cu diacritice ăîâșț. ', 2000)]);

        $this->assertSame('Ăîșț 🚀 Привет', $project->fresh()->name);
        $this->assertSame(74000, mb_strlen($project->fresh()->description));
        $this->assertSame(1, DB::table('projects')->where('name', 'Ăîșț 🚀 Привет')->count());
    }
}
