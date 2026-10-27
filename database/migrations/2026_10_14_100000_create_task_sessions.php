<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** /work: ce sarcină a pornit omul într-o sesiune de agent și când; din semnalele sesiunii de atunci încolo iese timpul pe sarcină. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamps();
            $table->index(['work_session_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_sessions');
    }
};
