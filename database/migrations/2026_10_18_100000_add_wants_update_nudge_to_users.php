<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** „Agentul îmi propune update-ul" (profil): pornit implicit; oprit, agentul nu mai propune singur, rămâne /upd. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('wants_update_nudge')->default(true));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('wants_update_nudge'));
    }
};
