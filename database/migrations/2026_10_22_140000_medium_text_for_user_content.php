<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// textele scrise de oameni și de agenți: pe MySQL / MariaDB, `text` se oprește la 64 KB (SQLite n-are limită); mediumText duce 16 MB.
// Documentele erau deja longText. Pe SQLite nu se schimbă nimic.
return new class extends Migration
{
    private const COLUMNS = [
        'projects' => ['description' => true], 'tasks' => ['notes' => true, 'proof' => true], 'project_notes' => ['body' => false],
        'task_comments' => ['body' => false], 'update_comments' => ['body' => false], 'update_items' => ['text' => false],
        'feedback' => ['body' => false, 'resolution' => true], 'clients' => ['notes' => true], 'settings' => ['value' => true],
        'notifications' => ['data' => false], 'project_templates' => ['description' => true], 'project_reports' => ['intro' => true],
        'download_requests' => ['message' => true],
    ];

    public function up(): void
    {
        $this->each(fn (Blueprint $table, string $column, bool $nullable) => $table->mediumText($column)->nullable($nullable)->change());
    }

    public function down(): void
    {
        $this->each(fn (Blueprint $table, string $column, bool $nullable) => $table->text($column)->nullable($nullable)->change());
    }

    private function each(Closure $change): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;                                                      // SQLite: un singur tip TEXT, fără limită
        }
        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns, $change) {
                foreach ($columns as $column => $nullable) {
                    $change($table, $column, $nullable);
                }
            });
        }
    }
};
