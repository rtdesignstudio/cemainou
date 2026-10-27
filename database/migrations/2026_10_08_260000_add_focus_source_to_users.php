<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De unde vine „Lucrează la" al fiecăruia: sarcina luată în lucru sau update-ul în care agentul a scris rândul „în lucru" (pe „Azi", textul duce acolo).
 * Pentru ce e scris deja: update-ul cel mai nou al omului pe proiectul de acum care are rândul acela, altfel sarcina lui în lucru cu același titlu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('focus_task_id')->nullable()->after('focus_project_id')->constrained('tasks')->nullOnDelete();
            $table->foreignId('focus_update_id')->nullable()->after('focus_task_id')->constrained('updates')->nullOnDelete();
        });

        foreach (DB::table('users')->whereNotNull('focus_text')->get(['id', 'focus_text', 'focus_project_id']) as $user) {
            $task = DB::table('tasks')->where('assignee_id', $user->id)->where('title', $user->focus_text)
                ->when($user->focus_project_id, fn ($q) => $q->where('project_id', $user->focus_project_id))->orderByDesc('id')->value('id');
            $update = DB::table('update_items')->join('updates', 'updates.id', '=', 'update_items.update_id')
                ->where('updates.user_id', $user->id)->where('update_items.text', $user->focus_text)
                ->when($user->focus_project_id, fn ($q) => $q->where('updates.project_id', $user->focus_project_id))->orderByDesc('updates.id')->value('updates.id');
            $line = $update ? DB::table('update_items')->where('update_id', $update)->where('text', $user->focus_text)->value('task_id') : null;
            DB::table('users')->where('id', $user->id)->update(['focus_task_id' => $line ?? $task, 'focus_update_id' => ($line ?? $task) ? null : $update]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('focus_update_id');
            $table->dropConstrainedForeignId('focus_task_id');
        });
    }
};
