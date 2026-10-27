<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();   // liderul vede orele echipei pe proiect
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('repo_url')->nullable();                // cum l-a scris omul
            $table->string('repo_key')->nullable()->index();       // forma normalizată (host/cale), după care agentul își găsește proiectul
            $table->string('category')->nullable();                // WorkCategory
            $table->string('color', 7)->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
