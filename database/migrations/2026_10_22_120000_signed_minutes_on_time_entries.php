<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// „Corectează timpul" păstrează diferența, care poate fi negativă (s-a măsurat mai mult decât s-a lucrat). Coloana era fără semn:
// SQLite o accepta oricum, MySQL o refuza. Acum e un întreg cu semn, pe toate bazele.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->integer('minutes')->change();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->change();
        });
    }
};
