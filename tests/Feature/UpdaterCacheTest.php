<?php

namespace Tests\Feature;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Migrările rulate de mână (`php artisan migrate`) golesc starea „de actualizat" din cache: aplicația nu rămâne pe „se actualizează". */
class UpdaterCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_migration_run_forgets_the_cached_pending_state(): void
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $key = 'cemainou.update.'.md5(config('cemainou.version').'|'.implode(',', array_keys($files)));
        Cache::forever($key, ['from' => '0.1.340', 'to' => config('cemainou.version'), 'migrations' => ['x']]);   // starea veche, de dinainte de migrare

        event(new MigrationsEnded('up'));

        $this->assertFalse(Cache::has($key));
    }
}
