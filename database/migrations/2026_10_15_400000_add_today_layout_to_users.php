<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pagina Azi, așezată de fiecare: ordinea secțiunilor, care se văd și cât de late sunt (App\Support\TodayLayout)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('today_layout')->nullable()->after('today_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('today_layout');
        });
    }
};
