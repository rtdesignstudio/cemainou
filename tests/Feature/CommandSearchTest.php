<?php

namespace Tests\Feature;

use App\Filament\GlobalSearch\EverythingSearch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ⌘K: căutarea de sus are și acțiuni, potrivite după cuvinte (în română și în engleză); clientul doar pe ale lui. */
class CommandSearchTest extends TestCase
{
    use RefreshDatabase;

    private function titles(string $q): array
    {
        $categories = (new EverythingSearch)->getResults($q)?->getCategories() ?? collect();

        return collect($categories['Actions'] ?? [])->map(fn ($r) => $r->title)->values()->all();
    }

    public function test_actions_match_what_you_type_and_open_the_quick_capture_or_a_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertSame(['New task'], $this->titles('new ta'));
        $this->assertContains('Go to Hours', $this->titles('ore'));                              // și cuvintele românești
        $this->assertContains('Go to Settings', $this->titles('setari'));
        $first = (new EverythingSearch)->getResults('new task')->getCategories()['Actions'][0];
        $this->assertStringContainsString('#capture=task', $first->url);
    }

    public function test_a_client_gets_only_what_they_can_do(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'client', 'client_id' => Client::factory()->create()->id]));
        $this->assertSame(['New task'], $this->titles('new task'));
        $this->assertSame([], $this->titles('setari'));
        $this->assertSame([], $this->titles('idee'));
    }
}
