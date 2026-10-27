<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // o raportare: ce a făcut cineva pe un proiect, scris de agent și confirmat de om
        Schema::create('updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary', 500);
            $table->string('source')->default('web');            // Source
            $table->timestamp('started_at')->nullable();         // când a început lucrul în sesiune (pentru jurnalul de program)
            $table->timestamp('period_from')->nullable();        // intervalul acoperit: de la ultima raportare...
            $table->timestamp('period_to')->nullable();          // ...până acum
            $table->unsignedInteger('minutes')->nullable();      // durata propusă de agent, corectabilă
            $table->json('links')->nullable();                   // commit-uri, PR-uri, adrese
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('update_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_id')->constrained()->cascadeOnDelete();
            $table->string('type');                              // UpdateItemType
            $table->text('text');
            $table->unsignedSmallInteger('position')->default(0);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_items');
        Schema::dropIfExists('updates');
    }
};
