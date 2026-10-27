<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// rolul unui fișier sau link (Demo, Brief, Machetă, Acte...): demo-ul stă fixat sus pe proiect și pe card
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
