<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Refacerea demo-ului pe un fișier SQLite: baza nouă se face alături și o înlocuiește pe cea veche, chiar dacă aceea s-a stricat (30 oct 2026). */
class DemoResetFileTest extends TestCase
{
    public function test_a_broken_demo_database_is_replaced_by_a_fresh_one(): void
    {
        $file = sys_get_temp_dir().'/cemainou-demo-'.uniqid().'.sqlite';
        file_put_contents($file, str_repeat("\0", 8192));                    // ca pe server: un fișier plin de zerouri
        config(['cemainou.demo' => true, 'database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');

        try {
            $this->artisan('cemainou:demo-reset')->assertSuccessful();

            $this->assertSame('SQLite format 3', substr((string) file_get_contents($file, false, null, 0, 15), 0, 15));
            $this->assertTrue(User::where('email', 'ana@cemainou.test')->exists());
            $this->assertFileDoesNotExist($file.'.fresh');
        } finally {
            DB::disconnect('sqlite');
            @unlink($file);
        }
    }
}
