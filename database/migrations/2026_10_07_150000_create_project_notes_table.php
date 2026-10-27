<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ce trebuie să știe colegii despre un proiect (acces, convenții, capcane - niciodată secrete), adunat de la toți
        Schema::create('project_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('update_id')->nullable()->constrained()->nullOnDelete();   // update-ul din care vine, dacă vine dintr-unul
            $table->text('body');
            $table->string('source')->default('web');            // Source
            $table->timestamp('obsolete_at')->nullable();        // „nu mai e valabil"
            $table->foreignId('obsolete_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'obsolete_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_notes');
    }
};
