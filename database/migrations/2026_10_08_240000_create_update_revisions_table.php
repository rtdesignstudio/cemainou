<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Istoria modificărilor unui update: cine, prin ce (agent sau web), de ce (obligatoriu) și ce s-a schimbat - un update nu se schimbă pe ascuns. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('web');
            $table->string('reason', 300);
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_revisions');
    }
};
