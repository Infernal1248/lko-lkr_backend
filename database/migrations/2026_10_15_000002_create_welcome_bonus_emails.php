<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welcome_bonus_emails', function (Blueprint $table) {
            $table->char('email_hash', 64)->primary();
            $table->timestamp('claimed_at')->nullable();
        });
        DB::table('ledger_entries')->join('users', 'users.id', '=', 'ledger_entries.user_id')
            ->where('ledger_entries.reason', 'welcome_bonus')
            ->select('ledger_entries.id', 'users.email', 'ledger_entries.created_at')
            ->orderBy('ledger_entries.id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('welcome_bonus_emails')->insertOrIgnore([
                        'email_hash' => hash('sha256', mb_strtolower(trim($row->email))),
                        'claimed_at' => $row->created_at,
                    ]);
                }
            }, 'ledger_entries.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_bonus_emails');
    }
};
