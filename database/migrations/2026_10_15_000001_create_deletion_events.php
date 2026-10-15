<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Evidence must survive deletion of the originating account or document.
            $table->unsignedBigInteger('actor_id');
            $table->string('resource_type', 20);
            $table->string('resource_id', 40);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('retain_until')->index();
            $table->text('evidence');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Журнал подтверждений нельзя удалять обычным откатом миграции. Сначала обеспечьте обязательное хранение записей.');
    }
};
