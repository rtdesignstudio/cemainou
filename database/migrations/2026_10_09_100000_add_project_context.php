<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contextul proiectului (/context): un document fixat al proiectului (is_context), făcut de agent din repo, din cemainou și din răspunsurile omului,
 * ca un coleg nou să pornească cu toată imaginea. document_reads: ce versiune a citit fiecare (agentul spune ce s-a schimbat de atunci).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->boolean('is_context')->default(false)->after('client_visible'));
        Schema::create('document_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('read_at');
            $table->unique(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reads');
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('is_context'));
    }
};
