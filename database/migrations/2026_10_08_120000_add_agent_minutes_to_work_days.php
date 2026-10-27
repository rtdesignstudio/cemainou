<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Din timpul activ al zilei, cât a lucrat agentul la prompturi (de la trimitere până termină): rămâne și după ce semnalele se șterg. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table) {
            $table->unsignedInteger('agent_minutes')->nullable()->after('active_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_days', function (Blueprint $table) {
            $table->dropColumn('agent_minutes');
        });
    }
};
