<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->text('card_number')->nullable();
            $table->string('card_number_hash', 64)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unique(['user_id', 'template_key', 'card_number_hash'], 'unique_physical_card');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropUnique('unique_physical_card');
            $table->dropColumn(['card_number', 'card_number_hash', 'assigned_at']);
        });
    }
};
