<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// adresele de repo: până la 500 de caractere, ca în validare (pe MySQL, una mai lungă de 255 oprea salvarea; SQLite o primea oricum)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repo_url', 500)->nullable()->change();
            $table->string('repo_key', 500)->nullable()->change();       // indexul unic rămâne: 500 × 4 octeți încap în limita MySQL (3072)
        });
        Schema::table('project_repos', function (Blueprint $table) {
            $table->string('repo_key', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repo_url')->nullable()->change();
            $table->string('repo_key')->nullable()->change();
        });
        Schema::table('project_repos', function (Blueprint $table) {
            $table->string('repo_key')->change();
        });
    }
};
