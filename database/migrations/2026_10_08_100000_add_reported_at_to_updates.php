<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Raportarea pe zile: un update pus pe ziua lui (created_at) ține minte și când a fost trimis de fapt. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('updates', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable()->after('period_to');
        });
    }

    public function down(): void
    {
        Schema::table('updates', function (Blueprint $table) {
            $table->dropColumn('reported_at');
        });
    }
};
