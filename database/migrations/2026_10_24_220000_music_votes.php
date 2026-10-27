<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Muzica biroului: 👍 pe piese, câte unul de om; piesele cu voturi cântă primele din bibliotecă și formează „Preferatele". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('music_tracks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['track_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_votes');
    }
};
