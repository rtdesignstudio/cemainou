<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    private string $backups;

    /** Backup-urile din teste (și cel zilnic, din curățenie) merg într-un folder temporar, nu în data/backups; lista de versiuni de pe rt.md nu se citește. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->backups = sys_get_temp_dir().'/wsa-backups-'.uniqid();
        config(['cemainou.backup_path' => $this->backups, 'cemainou.release_feed' => null]);   // și fără cereri spre rt.md (lista de versiuni)
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backups);
        parent::tearDown();
    }
}
