<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// emailurile despre sarcini așteaptă aici: pleacă grupate, după o oră (echipa) sau o dată pe zi (clienții), și doar dacă omul n-a văzut deja
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('comment_id')->nullable();
            $table->string('kind', 20);
            $table->string('url', 500);
            $table->timestamp('due_at')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('result', 10)->nullable();                     // sent | seen | gone
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_outbox');
    }
};
