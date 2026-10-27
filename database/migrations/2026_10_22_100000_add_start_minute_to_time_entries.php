<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Timpul adăugat de mână poate avea ora la care a început (ședința de la 14:00): minutele de la miezul nopții, în fusul omului.
// Fără ea, barele pe ore îl pun, hașurat, în golurile zilei.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_minute')->nullable()->after('minutes');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('start_minute');
        });
    }
};
