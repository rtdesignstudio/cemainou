<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** „Văzut de client": prima și ultima deschidere a unei sarcini, a unui update sau a unui document de către un om de la client. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);                  // task, update, document
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['subject_type', 'subject_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_views');
    }
};
