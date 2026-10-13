<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('npd_shop_id', 64)->nullable();
            $t->text('npd_payload')->nullable();
            $t->string('npd_receipt_id', 128)->nullable();
            $t->timestamp('npd_deferred_until')->nullable();
            $t->boolean('npd_excluded')->default(false);
            $t->unique(['npd_shop_id', 'npd_receipt_id']);
            $t->index(['status', 'test_mode', 'paid_at'], 'npd_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropUnique(['npd_shop_id', 'npd_receipt_id']);
            $t->dropIndex('npd_queue_index');
            $t->dropColumn(['npd_shop_id', 'npd_payload', 'npd_receipt_id', 'npd_deferred_until', 'npd_excluded']);
        });
    }
};
