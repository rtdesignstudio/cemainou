<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// „QR fără logare": telefonul unui vizitator (fără cont), în numele celui care a dat codul; își spune numele și, dacă vrea, emailul
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_devices', function (Blueprint $table) {
            $table->boolean('guest')->default(false)->after('device');
            $table->string('guest_name', 100)->nullable()->after('guest');
            $table->string('guest_email', 255)->nullable()->after('guest_name');
        });
    }

    public function down(): void
    {
        Schema::table('widget_devices', function (Blueprint $table) {
            $table->dropColumn(['guest', 'guest_name', 'guest_email']);
        });
    }
};
