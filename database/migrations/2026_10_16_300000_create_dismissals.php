<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ce a închis omul dintr-un semnal (radarul de dimineață, „Încă blocată?"): „Am răspuns", „E în regulă", „Revin peste 3 zile" (App\Models\Dismissal)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);                          // waiting:12, still:3, proof:40, stale:7
            $table->timestamp('until')->nullable();             // gol = până se schimbă ceva (sau pentru totdeauna)
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dismissals');
    }
};
