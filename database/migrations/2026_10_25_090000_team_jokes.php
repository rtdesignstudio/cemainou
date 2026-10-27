<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Glumele puse de echipă (scurte), alături de cele din cemainou; pe Azi apar cu inițialele celui care le-a pus. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_jokes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text', 200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_jokes');
    }
};
