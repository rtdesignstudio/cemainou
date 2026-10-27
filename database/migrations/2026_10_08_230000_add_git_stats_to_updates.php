<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commit-urile omului din ziua unui update și rândurile adăugate / scoase (fără fișierele generate), numărate de agent din git la /upd. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('updates', function (Blueprint $table) {
            $table->unsignedSmallInteger('commits')->nullable()->after('minutes');
            $table->unsignedInteger('lines_added')->nullable()->after('commits');
            $table->unsignedInteger('lines_removed')->nullable()->after('lines_added');
        });
    }

    public function down(): void
    {
        Schema::table('updates', fn (Blueprint $table) => $table->dropColumn(['commits', 'lines_added', 'lines_removed']));
    }
};
