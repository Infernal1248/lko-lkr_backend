<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Consent;
use App\Models\LedgerEntry;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use Illuminate\Database\Seeder;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo seeding is restricted to local environments.');
        }
        $user = User::firstOrCreate(['email' => 'demo@example.test'], ['name' => 'Демо-пользователь', 'password' => 'DemoOnly-2026-Password', 'organization' => ['organization_type' => 'ООО ЧОО', 'organization_name' => '«Образец»', 'director_position' => 'Директор', 'director_name' => 'И. И. Образцов']]);
        if (! LedgerEntry::where('reference', 'demo:initial:'.$user->id)->exists()) {
            $user->increment('credits', 25);
            LedgerEntry::create(['user_id' => $user->id, 'amount' => 25, 'balance' => $user->credits, 'reason' => 'demo', 'reference' => 'demo:initial:'.$user->id, 'created_at' => now()]);
        }
        Consent::firstOrCreate(['user_id' => $user->id, 'version' => config('cards.consent_version')], ['organization' => ['name' => 'ДЕМОНСТРАЦИОННЫЕ ДАННЫЕ'], 'created_at' => now()]);
        if (! Card::where('user_id', $user->id)->exists()) {
            Card::create(['user_id' => $user->id, 'template_key' => 'ppd-lkr', 'data' => CardInput::demo()]);
        }
        PrinterProfile::firstOrCreate(['user_id' => $user->id, 'name' => 'Основной принтер'], ['template_key' => 'ppd-lkr', 'settings' => ['offset_x' => 0, 'offset_y' => 0, 'font_size' => 12, 'fields' => []]]);
    }
}
