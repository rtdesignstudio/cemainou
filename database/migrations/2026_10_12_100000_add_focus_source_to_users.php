<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** De unde vine „Lucrează la": spus de om (said), o sarcină luată (task) sau dedus din rândurile unui update (update). Pe „Azi" apare doar ce a spus sau a luat omul azi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('focus_source', 10)->nullable()->after('focus_update_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('focus_source'));
    }
};
