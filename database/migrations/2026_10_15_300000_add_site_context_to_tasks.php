<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// de unde a venit o sarcină trimisă din widget-ul de pe site: pagina, browserul, ecranul, erorile, elementul arătat
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->json('site_context')->nullable());
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('site_context'));
    }
};
