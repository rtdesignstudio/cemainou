<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Muzica biroului: secunda de la care pornește piesa, din linkul pus (?t=90, &t=1m30s); 0 = de la început. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->unsignedInteger('start')->default(0)->after('author');
        });
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropColumn('start');
        });
    }
};
