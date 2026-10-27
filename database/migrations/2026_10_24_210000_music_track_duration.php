<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Muzica biroului: durata piesei (secunde), aflată de la player prima dată când cântă; o arată biblioteca. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->unsignedInteger('duration')->nullable()->after('start');
        });
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropColumn('duration');
        });
    }
};
