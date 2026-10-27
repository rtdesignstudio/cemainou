<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // o sesiune de agent (Claude Code etc.): când a început, ultima activitate, când s-a încheiat
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_session_id')->nullable();      // id-ul sesiunii din agent, ca semnalele să se lege de ea
            $table->string('source')->default('claude-code');
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'agent_session_id']);
            $table->index(['user_id', 'started_at']);
        });

        // semnalele de activitate trimise de hook-uri: doar cine, proiect, când, ce fel (niciodată cod sau prompturi)
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');                              // ActivityKind
            $table->string('source')->default('claude-code');
            $table->timestamp('occurred_at');
            $table->index(['user_id', 'occurred_at']);
        });

        // orele adăugate manual: ședințe, design, telefoane - ce nu se face cu agentul
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedInteger('minutes');
            $table->string('category')->nullable();              // WorkCategory
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });

        // jurnal de transparență: ce unealtă MCP a chemat cine și când
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('work_sessions');
    }
};
