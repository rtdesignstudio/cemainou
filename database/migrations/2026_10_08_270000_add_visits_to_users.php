<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „De la ultima ta vizită": când a deschis omul ultima dată o pagină (visited_at) și unde se termina vizita de dinainte (previous_visit_at);
 * ce au făcut alții după aceea apare pe „Azi" și cu un punct în liste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('visited_at')->nullable()->after('last_seen_at');
            $table->timestamp('previous_visit_at')->nullable()->after('visited_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['visited_at', 'previous_visit_at']));
    }
};
