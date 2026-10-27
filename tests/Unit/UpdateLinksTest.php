<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Support\UpdateLinks;
use PHPUnit\Framework\TestCase;

class UpdateLinksTest extends TestCase
{
    private function project(?string $repoKey): Project
    {
        return (new Project)->forceFill(['repo_key' => $repoKey]);
    }

    public function test_commit_hashes_open_on_github_or_gitlab(): void
    {
        $this->assertSame([['label' => 'a1b2c3d', 'url' => 'https://github.com/rt/site/commit/a1b2c3d', 'commit' => true]],
            UpdateLinks::resolve(['a1b2c3d'], $this->project('github.com/rt/site')));
        $this->assertSame('https://gitlab.com/rt/site/-/commit/89554c9',
            UpdateLinks::resolve(['89554c9'], $this->project('gitlab.com/rt/site'))[0]['url']);
    }

    public function test_a_hash_without_a_known_repo_stays_text(): void
    {
        $this->assertSame([['label' => 'a1b2c3d', 'url' => null, 'commit' => true]], UpdateLinks::resolve(['a1b2c3d'], null));
        $this->assertNull(UpdateLinks::resolve(['a1b2c3d'], $this->project('git.example.com/rt/site'))[0]['url']);
    }

    public function test_addresses_are_shortened_and_other_text_stays_text(): void
    {
        $links = UpdateLinks::resolve(['https://github.com/firma-demo/studio-nord/pull/14', 'PR-ul de ieri', '  '], null);

        $this->assertSame(['label' => 'github.com/firma-demo/studio-nord/pull/14', 'url' => 'https://github.com/firma-demo/studio-nord/pull/14', 'commit' => false], $links[0]);
        $this->assertSame(['label' => 'PR-ul de ieri', 'url' => null, 'commit' => false], $links[1]);
        $this->assertCount(2, $links);
        $this->assertSame(60, mb_strlen(UpdateLinks::resolve(['https://example.com/'.str_repeat('a', 100)], null)[0]['label']));
    }
}
