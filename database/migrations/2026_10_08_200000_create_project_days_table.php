<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timpul pe proiect al unei zile (om, proiect, dată în fusul omului), rezumat de curățenia zilnică: rămâne și după ce semnalele brute
 * se șterg (keep_raw_days), deci orele pe lună și exportul merg și pentru lunile vechi. Data e text Y-m-d (comparații simple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('date', 10);
            $table->unsignedInteger('active_minutes')->default(0);
            $table->unsignedInteger('agent_minutes')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'date']);
            $table->index(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_days');
    }
};
