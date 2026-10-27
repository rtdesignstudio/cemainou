<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Canalul unui proiect (dacă e altul decât al echipei, din Setări): un webhook Slack (criptat) și / sau un chat Telegram. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('notify_slack_webhook')->nullable()->after('color');
            $table->string('notify_telegram_chat', 64)->nullable()->after('notify_slack_webhook');
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['notify_slack_webhook', 'notify_telegram_chat']));
    }
};
