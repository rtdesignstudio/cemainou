<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jurnalul apelurilor MCP notează și agentul care a chemat (Claude Code, Codex, Cursor), pentru transparență. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('tool');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_calls', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
