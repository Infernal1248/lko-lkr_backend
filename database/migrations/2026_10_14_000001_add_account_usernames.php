<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 40)->nullable()->unique();
            $t->string('legacy_email')->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['legacy_email' => $user->email]);
                // Preserve unexpired links sent before the deployment, including their hashes and dates.
                DB::table('password_reset_tokens')->where('email', $user->email)->update(['email' => 'account:'.$user->id]);
            }
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique('users_email_unique');
            $t->index('email');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Откат невозможен: несколько аккаунтов используют одну почту. Данные не изменены.');
        }
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                DB::table('password_reset_tokens')->where('email', 'account:'.$user->id)->update(['email' => $user->email]);
            }
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex('users_email_index');
            $t->unique('email');
            $t->dropUnique('users_username_unique');
            $t->dropUnique('users_legacy_email_unique');
            $t->dropColumn(['username', 'legacy_email']);
        });
    }
};
