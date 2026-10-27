<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Telegram: contul legat al fiecăruia (notificările în privat, răspunsurile devin comentarii) și ce mesaj despre ce update sau sarcină a plecat (App\Support\Telegram)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->after('notice_prefs');
            $table->string('telegram_user_id', 32)->nullable()->unique()->after('telegram_chat_id');
            $table->string('telegram_name', 100)->nullable()->after('telegram_user_id');
        });
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32);
            $table->unsignedBigInteger('message_id');
            $table->string('subject_type', 16);                 // update | task
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('created_at')->nullable();
            $table->index(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_user_id']);
            $table->dropColumn(['telegram_chat_id', 'telegram_user_id', 'telegram_name']);
        });
    }
};
