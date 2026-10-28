<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pe un proiect vizibil clientului: clientul vede și comentariile echipei la update-uri (implicit doar pe ale lui). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('client_sees_comments')->default(false)->after('client_visible');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('client_sees_comments');
        });
    }
};
