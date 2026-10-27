<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// timpul din aplicație (cemainou deschis și folosit): cât din ziua măsurată a fost „în app" și comutatorul din profil
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table) {
            $table->unsignedInteger('app_minutes')->nullable()->after('agent_minutes');
        });
        Schema::table('project_days', function (Blueprint $table) {
            $table->unsignedInteger('app_minutes')->default(0)->after('agent_minutes');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('track_app')->default(true)->after('time_in_days');
        });
    }

    public function down(): void
    {
        Schema::table('work_days', fn (Blueprint $table) => $table->dropColumn('app_minutes'));
        Schema::table('project_days', fn (Blueprint $table) => $table->dropColumn('app_minutes'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('track_app'));
    }
};
