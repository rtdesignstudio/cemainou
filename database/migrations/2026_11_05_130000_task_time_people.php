<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cât a lucrat fiecare om pe o sarcină, păstrat la închidere lângă total (time_minutes): [[user_id, minute], ...]. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('time_people')->nullable()->after('time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('time_people'));
    }
};
