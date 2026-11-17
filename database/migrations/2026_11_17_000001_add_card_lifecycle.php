<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $t) {
            $t->uuid('source_generation_id')->nullable()->index();
            $t->timestamp('inactive_at')->nullable();
            $t->longText('corrections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $t) {
            $t->dropIndex(['source_generation_id']);
            $t->dropColumn(['source_generation_id', 'inactive_at', 'corrections']);
        });
    }
};
