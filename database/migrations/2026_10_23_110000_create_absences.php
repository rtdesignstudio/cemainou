<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// concediul marcat de om („Sunt în concediu până pe..."): seria nu se rupe, „Revenire" e exactă, colegii văd pe Azi că lipsește
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('from');
            $table->date('to');
            $table->timestamps();
            $table->index(['user_id', 'to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
