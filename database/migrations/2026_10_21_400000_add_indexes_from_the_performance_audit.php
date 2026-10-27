<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Indexurile găsite lipsă la auditul de performanță (21 oct. 2026): pe SQLite, constrained() nu face index, iar aceste coloane se caută des.
return new class extends Migration
{
    private const INDEXES = [
        'task_events' => [['user_id', 'created_at']],
        'tasks' => [['created_by'], ['due_on'], ['updated_at']],
        'updates' => [['created_at'], ['user_id', 'started_at']],
        'project_notes' => [['user_id', 'created_at']],
        'update_items' => [['resolved_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                if (Schema::hasTable($table) && collect($columns)->every(fn ($c) => Schema::hasColumn($table, $c)) && ! Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $columns)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
                }
            }
        }
    }
};
