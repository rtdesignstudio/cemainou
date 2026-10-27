<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// adresa de email a proiectului: un email trimis la tasks+{inbox_key}@... devine sarcină (App\Support\InboundMail)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('inbox_key', 16)->nullable()->unique()->after('widget_key');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['inbox_key']);
            $table->dropColumn('inbox_key');
        });
    }
};
