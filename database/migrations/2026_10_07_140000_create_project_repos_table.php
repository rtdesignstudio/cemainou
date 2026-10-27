<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // repo-urile în plus ale unui proiect (frontend / backend, temă / plugin, fork-uri), pe lângă cel principal din projects;
        // „pending" = propus de cineva care a lucrat din el pe proiect, până îl leagă liderul
        Schema::create('project_repos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('repo_key')->unique();
            $table->string('status')->default('active');        // active | pending | dismissed
            $table->foreignId('suggested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repos');
    }
};
