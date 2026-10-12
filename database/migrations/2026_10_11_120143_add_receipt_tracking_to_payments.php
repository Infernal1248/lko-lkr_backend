<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->text('buyer')->nullable();
            $t->boolean('test_mode')->nullable();
            $t->string('receipt_mode', 20)->nullable();
            $t->text('receipt_url')->nullable();
            $t->timestamp('receipt_recorded_at')->nullable();
            $t->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['buyer', 'test_mode', 'receipt_mode', 'receipt_url', 'receipt_recorded_at', 'paid_at']));
    }
};
