<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Clienții fără culoare primesc una din paletă (nu albă), ca să se vadă în liste; o culoare aleasă deja rămâne. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->whereNull('color')->orWhere('color', '')->orderBy('id')->pluck('id')
            ->each(fn (int $id, int $i) => DB::table('clients')->where('id', $id)->update(['color' => Client::PALETTE[$i % count(Client::PALETTE)]]));
    }

    public function down(): void {}
};
