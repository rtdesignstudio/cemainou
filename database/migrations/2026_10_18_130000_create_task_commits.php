<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commit-urile care pomenesc o sarcină în mesaj („#12", „fixes #12"), trimise de agent la /upd: pe pagina sarcinii, cu linkul spre commit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hash', 40);
            $table->string('subject', 300);
            $table->date('committed_on')->nullable();
            $table->boolean('closes')->default(false);           // „fixes / closes / resolves #12": spune că o încheie
            $table->timestamps();
            $table->unique(['task_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_commits');
    }
};
