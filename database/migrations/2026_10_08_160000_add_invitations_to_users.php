<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invitațiile: adminul trimite un link (email sau copiat), omul își alege parola și intră. Tokenul se păstrează doar ca hash. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('invited_at')->nullable()->after('last_seen_at');
            $table->foreignId('invited_by')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
            $table->string('invite_token', 64)->nullable()->after('invited_by');
            $table->timestamp('invite_accepted_at')->nullable()->after('invite_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['invited_at', 'invite_token', 'invite_accepted_at']);
        });
    }
};
