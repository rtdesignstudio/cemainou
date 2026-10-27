<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pe SQLite, constrained() nu creează index pe coloana copil: fiecare with('items'), whereHas, cascadă la ștergere și curățenia zilnică
 * citea tabelele întregi. Doar adăugiri (pe MySQL dublează indexurile automate ale cheilor străine; nu strică).
 */
return new class extends Migration
{
    private const INDEXES = [
        'update_items' => ['update_id', 'task_id'],
        'task_events' => ['task_id'],
        'activities' => ['project_id', 'work_session_id', 'occurred_at'],
        'work_sessions' => ['project_id'],
        'mcp_calls' => ['created_at'],
        'projects' => ['client_id', 'lead_id'],
        'users' => ['focus_project_id'],
        'project_repos' => ['project_id'],
        'time_entries' => ['project_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->dropIndex([$column]);
                }
            });
        }
    }
};
