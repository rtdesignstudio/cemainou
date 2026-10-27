<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sarcinile: pe un proiect, libere (fără om) sau ale cuiva
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('status')->default('todo');           // TaskStatus
            $table->string('blocked_reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assignee_seen_at')->nullable();   // gol = „nouă" pentru omul ei
            $table->timestamp('started_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('source')->default('web');            // Source
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        // istoria unei sarcini: creată, atribuită, luată, mutată, pomenită într-un update
        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');                              // TaskEventType
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->foreignId('update_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // un rând dintr-un update poate trimite la o sarcină
        Schema::table('update_items', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('update_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('update_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });
        Schema::dropIfExists('task_events');
        Schema::dropIfExists('tasks');
    }
};
