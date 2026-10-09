<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedInteger('credits')->default(0);
            $t->text('organization')->nullable();
        });
        Schema::create('consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('version');
            $t->text('organization');
            $t->timestamp('created_at');
        });
        Schema::create('printer_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('template_key');
            $t->json('settings');
            $t->timestamps();
        });
        Schema::create('cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('template_key');
            $t->text('data');
            $t->timestamps();
        });
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('request_key', 64);
            $t->string('fingerprint', 64);
            $t->string('template_key');
            $t->string('template_version');
            $t->text('snapshot')->nullable();
            $t->longText('pdf')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('request_key', 64);
            $t->string('package');
            $t->unsignedInteger('credits');
            $t->unsignedInteger('amount');
            $t->string('status')->default('pending');
            $t->string('provider_id')->nullable()->unique();
            $t->text('confirmation_url')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->integer('amount');
            $t->unsignedInteger('balance');
            $t->string('reason');
            $t->string('reference')->unique();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['ledger_entries', 'payments', 'generations', 'cards', 'printer_profiles', 'consents'] as $s) {
            Schema::dropIfExists($s);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['credits', 'organization']));
    }
};
