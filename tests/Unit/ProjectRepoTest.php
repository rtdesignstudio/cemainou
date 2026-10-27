<?php

namespace Tests\Unit;

use App\Models\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectRepoTest extends TestCase
{
    #[DataProvider('remotes')]
    public function test_remote_urls_normalize_to_the_same_key(string $remote): void
    {
        $this->assertSame('github.com/firma/site', Project::normalizeRepo($remote));
    }

    public static function remotes(): array
    {
        return [
            'ssh scp' => ['git@github.com:firma/site.git'],
            'https' => ['https://github.com/firma/site'],
            'https .git + slash' => ['https://github.com/Firma/Site.git/'],
            'ssh url' => ['ssh://git@github.com/firma/site.git'],
            'token in url' => ['https://x-access-token:abc@github.com/firma/site.git'],
            'port' => ['ssh://git@github.com:22/firma/site.git'],
        ];
    }

    public function test_empty_remote_has_no_key(): void
    {
        $this->assertNull(Project::normalizeRepo(''));
        $this->assertNull(Project::normalizeRepo(null));
    }
}
