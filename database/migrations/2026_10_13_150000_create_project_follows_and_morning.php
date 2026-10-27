<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Proiectele urmărite (pentru rezumatul de dimineață) și rezumatul însuși: pornit sau oprit, ziua în care a plecat ultima dată. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wants_morning')->default(true);
            $table->date('morning_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_follows');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['wants_morning', 'morning_sent_on']));
    }
};
