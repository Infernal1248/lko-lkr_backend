<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->text('registry')->nullable();
            $t->timestamp('rights_confirmed_at')->nullable();
        });
        Schema::table('generations', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->constrained('cards')->nullOnDelete();
            $t->text('registry')->nullable();
            $t->timestamp('rights_confirmed_at')->nullable();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_support_admin')->default(false);
            $t->timestamp('privacy_accepted_at')->nullable();
            $t->string('privacy_version', 40)->nullable();
        });
        Schema::table('printer_profiles', function (Blueprint $t) {
            $t->timestamp('calibrated_at')->nullable();
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('subject');
            $t->string('status', 20)->default('open');
            $t->timestamp('user_read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'updated_at']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users');
            $t->boolean('from_support');
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::table('printer_profiles', fn (Blueprint $t) => $t->dropColumn('calibrated_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_support_admin', 'privacy_accepted_at', 'privacy_version']));
        Schema::table('generations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('employee_id');
            $t->dropColumn(['registry', 'rights_confirmed_at']);
        });
        Schema::table('cards', fn (Blueprint $t) => $t->dropColumn(['registry', 'rights_confirmed_at']));
    }
};
