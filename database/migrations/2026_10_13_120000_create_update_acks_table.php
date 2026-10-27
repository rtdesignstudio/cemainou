<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** „Văzut" la un update: cine din echipă l-a citit (autorul vede lista, fără comentarii de complezență). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['update_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_acks');
    }
};
