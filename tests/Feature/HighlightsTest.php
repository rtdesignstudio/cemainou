<?php

namespace Tests\Feature;

use App\Support\Updater;
use Tests\TestCase;

/** Noutățile din arhivă: în limba omului dacă există (CHANGELOG.ro.md), altfel în engleză; și fișierele de noutăți au formatul citit de aplicație. */
class HighlightsTest extends TestCase
{
    public function test_the_localized_file_wins_and_the_highlights_parse_as_versions(): void
    {
        $ro = base_path('CHANGELOG.ro.md');
        $existed = is_file($ro);
        $existed || file_put_contents($ro, "# Ce e nou\n\n## 0.1.1 - 2026-10-08\n\n**Test.**\n");
        app()->setLocale('ro');
        $this->assertSame($ro, Updater::changelogPath());
        app()->setLocale('en');
        $this->assertSame(base_path('CHANGELOG.md'), Updater::changelogPath());
        $existed || unlink($ro);

        foreach (['docs/highlights.md', 'docs/highlights.ro.md'] as $file) {
            $this->assertGreaterThan(5, preg_match_all('/^## \d+\.\d+\.\d+ - \d{4}-\d{2}-\d{2}\n\n\*\*[^*]+\*\* /m', file_get_contents(base_path($file))), $file);
        }
    }
}
