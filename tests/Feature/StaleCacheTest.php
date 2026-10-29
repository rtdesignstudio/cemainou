<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/** Cache-urile se șterg doar când sunt ale altei versiuni, citit din fișiere (nu din config(), pe care OPcache îl poate da vechi). */
class StaleCacheTest extends TestCase
{
    public function test_a_cache_of_another_version_is_stale_and_one_of_this_version_is_not(): void
    {
        $dir = sys_get_temp_dir().'/cemainou-stale-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/cemainou.php", "<?php\nreturn [\n    'version' => '0.1.410',\n];\n");
        file_put_contents("$dir/config.php", "<?php return array (\n  'cemainou' => \n  array (\n    'version' => '0.1.409',\n  ),\n);\n");
        $this->assertTrue(AppServiceProvider::staleCache("$dir/config.php", "$dir/cemainou.php"));

        file_put_contents("$dir/config.php", "<?php return array (\n  'cemainou' => \n  array (\n    'version' => '0.1.410',\n  ),\n);\n");
        $this->assertFalse(AppServiceProvider::staleCache("$dir/config.php", "$dir/cemainou.php"));

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
