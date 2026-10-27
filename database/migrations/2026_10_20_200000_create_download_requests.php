<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descărcările de pe pagina publică (rt.md/cemainou): cine și-a lăsat datele ca să primească linkul, ce versiune a primit și dacă vrea vești
 * despre versiunile noi (wants_updates, alegerea lui, nebifată implicit). Doar pe instanța RT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('company', 160)->nullable();
            $table->string('team', 40)->nullable();
            $table->boolean('wants_updates')->default(false);
            $table->string('lang', 2)->default('ro');
            $table->string('version', 20)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_requests');
    }
};
