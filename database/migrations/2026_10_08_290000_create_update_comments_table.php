<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentariile la update-uri: ale colegilor și ale autorului (le vede toată echipa) și ale clientului (el le vede doar pe ale lui).
 * author_seen_at: când le-a văzut autorul update-ului (pe pagina lui sau prin agent, din last_update).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->string('source', 20)->default('web');
            $table->timestamp('author_seen_at')->nullable();
            $table->timestamps();
            $table->index(['update_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_comments');
    }
};
