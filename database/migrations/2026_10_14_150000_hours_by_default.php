<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Totalurile în ore pentru toată lumea (fără conversie în zile de lucru); cine vrea zile le pornește din profil. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update(['time_in_days' => false]);
    }

    public function down(): void
    {
        // alegerile de dinainte nu se mai știu
    }
};
