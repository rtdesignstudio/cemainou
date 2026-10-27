<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sarcinile recurente: cât de des revin și din ce sarcină a venit una (ca să nu se adauge de două ori aceeași următoare). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('repeat', 20)->nullable();
            $table->foreignId('repeated_from_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['repeated_from_id']);
            $table->dropColumn(['repeat', 'repeated_from_id']);
        });
    }
};
