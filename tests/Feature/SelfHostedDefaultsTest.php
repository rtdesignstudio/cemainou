<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Valori implicite pentru găzduirea la echipă (hosting PHP obișnuit, fără worker sau cron): SQLite care suportă mai mulți
 * oameni odată, sesiuni și cache pe fișiere, coada pe loc, jurnal pe zile; baza stă în data/, lângă celelalte date ale echipei.
 */
class SelfHostedDefaultsTest extends TestCase
{
    public function test_sqlite_is_tuned_for_several_people_at_once(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertSame('wal', $sqlite['journal_mode']);
        $this->assertSame(5000, (int) $sqlite['busy_timeout']);
        $this->assertSame('normal', $sqlite['synchronous']);
    }

    public function test_the_example_env_needs_no_worker_and_keeps_data_out_of_the_code(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach (['SESSION_DRIVER=file', 'CACHE_STORE=file', 'QUEUE_CONNECTION=sync', 'LOG_STACK=daily'] as $line) {
            $this->assertStringContainsString($line, $example);
        }
        $this->assertStringContainsString("base_path('data/database.sqlite')", file_get_contents(config_path('database.php')));
        $this->assertFileExists(base_path('data/.gitignore'));
    }
}
