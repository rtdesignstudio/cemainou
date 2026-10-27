<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indecși pentru orele pe proiecte (Hours, carduri, Ziua/Săptămâna mea): semnalele unui proiect într-un interval și timpul adăugat
 * al unui proiect pe zile se citesc des, după aceste două coloane împreună.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->index(['project_id', 'occurred_at']));
        Schema::table('time_entries', fn (Blueprint $table) => $table->index(['project_id', 'date']));
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dropIndex(['project_id', 'occurred_at']));
        Schema::table('time_entries', fn (Blueprint $table) => $table->dropIndex(['project_id', 'date']));
    }
};
