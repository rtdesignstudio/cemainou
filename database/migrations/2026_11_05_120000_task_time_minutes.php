<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Timpul măsurat pe o sarcină, păstrat la închidere: semnalele din care se calculează rămân doar 90 de zile, raportul lunar îl vrea și după. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('time_minutes')->nullable()->after('done_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('time_minutes'));
    }
};
