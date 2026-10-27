<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** „De la ultima ta vizită": reperul e ultima deschidere a paginii Azi (nu sfârșitul unei sesiuni lungi în aplicație). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('today_seen_at')->nullable();
        });
        DB::table('users')->update(['today_seen_at' => DB::raw('visited_at')]);   // pornește de la ultima pagină deschisă
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('today_seen_at');
        });
    }
};
