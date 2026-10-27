<?php

namespace Tests\Feature;

use App\Console\Commands\CemainouUmamiWeekly;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rezumatul săptămânal din Umami: oprit fără setări; notița spune vizitatorii pe domenii, schimbarea, paginile, sursele și acțiunile. */
class UmamiWeeklyTest extends TestCase
{
    use RefreshDatabase;

    public function test_off_without_settings_and_the_note_reads_well(): void
    {
        config(['cemainou.umami_summary_ssh' => null]);
        $this->artisan('cemainou:umami-weekly')->expectsOutputToContain('Not set up')->assertSuccessful();

        $note = CemainouUmamiWeekly::note(['hosts' => [['hostname' => 'rt.md', 'visitors' => 30, 'views' => 80]], 'previous' => [['hostname' => 'rt.md', 'visitors' => 20]],
            'pages' => [['hostname' => 'rt.md', 'url_path' => '/cemainou/ro/', 'views' => 40], ['hostname' => 'd.rt.md', 'url_path' => '/', 'views' => 9]],
            'referrers' => [['referrer_domain' => 'facebook.com', 'visitors' => 6]], 'countries' => [['country' => 'MD', 'visitors' => 25]], 'events' => [['event_name' => 'download-request', 'n' => 3]]]);
        $this->assertStringContainsString('**rt.md**: 30 vizitatori (+50%), 80 vizualizări', $note);
        $this->assertStringContainsString('/cemainou/ro/ · 40, d.rt.md/ · 9', $note);
        $this->assertStringContainsString('Acțiuni: download-request · 3', $note);
    }
}
