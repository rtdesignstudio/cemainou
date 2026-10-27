<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fără /upd-auto nu mai există ciorne: rămân doar update-urile trimise (o ciornă neconfirmată nu devine update). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('updates')->where('is_draft', true)->delete();
        Schema::table('updates', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_draft']);
            $table->dropColumn('is_draft');
        });
    }

    public function down(): void
    {
        Schema::table('updates', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('source');
            $table->index(['user_id', 'is_draft']);
        });
    }
};
