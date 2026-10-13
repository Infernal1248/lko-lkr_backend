<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->string('name', 100);
            $t->unsignedInteger('credits');
            $t->unsignedInteger('amount');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        $order = 0;
        foreach (config('cards.packages') as $key => $package) {
            DB::table('packages')->insert($package + ['key' => $key, 'active' => true, 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
