<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback-ul centralizat: o instalare care a ales (Setări) își trimite feedback-ul despre cemainou la RT (sent_to_rt_at = când a ajuns);
 * la RT, feedback-ul primit de la o instalare nu are autor (user_id gol), doar numărul instalării (installation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('installation', 40)->nullable()->index();
            $table->timestamp('sent_to_rt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn(['installation', 'sent_to_rt_at']);
        });
    }
};
