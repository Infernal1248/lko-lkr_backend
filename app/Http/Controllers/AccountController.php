<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Consent;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\PrinterProfile;
use App\Models\User;
use App\Services\CardInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function me(Request $request): mixed
    {
        return ['welcome_bonus_claimed' => LedgerEntry::where('reference', 'welcome:'.$request->user()->id)->exists(), 'user' => $request->user(), 'consented' => Consent::where('user_id', $request->user()->id)->where('version', config('cards.consent_version'))->exists(), 'consent_version' => config('cards.consent_version'), 'counts' => ['cards' => Card::where('user_id', $request->user()->id)->count(), 'generations' => Generation::where('user_id', $request->user()->id)->count()]];
    }

    public function claimWelcomeBonus(Request $request): mixed
    {
        return DB::transaction(function () use ($request) {
            // Serialize bonus claims with other balance changes for this account.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $reference = 'welcome:'.$user->id;
            if (! LedgerEntry::where('reference', $reference)->exists()) {
                abort_if($user->credits > 4294967295 - 3, 422, 'Достигнут предел баланса аккаунта.');
                $user->increment('credits', 3);
                LedgerEntry::create([
                    'user_id' => $user->id, 'amount' => 3, 'balance' => $user->credits,
                    'reason' => 'welcome_bonus', 'reference' => $reference, 'created_at' => now(),
                ]);
            }

            return ['credits' => $user->credits, 'welcome_bonus_claimed' => true];
        }, 3);
    }

    public function onboarding(Request $request): mixed
    {
        $user = $request->user();
        if ($user->onboarding_seen_at === null) {
            $user->onboarding_seen_at = now();
            $user->save();
        }

        return response()->noContent();
    }

    public function organization(Request $request): mixed
    {
        $d = $request->validate(['organization_type' => 'required|string|max:60', 'organization_name' => 'required|string|max:120', 'director_position' => 'required|string|max:60', 'director_name' => 'required|string|max:100']);
        $request->user()->update(['organization' => $d]);

        return $d;
    }

    public function consent(Request $request): mixed
    {
        $request->validate(['accepted' => 'accepted', 'organization' => 'required|string|max:180', 'version' => ['required', Rule::in([config('cards.consent_version')])]]);
        Consent::create(['user_id' => $request->user()->id, 'version' => config('cards.consent_version'), 'organization' => ['name' => $request->input('organization')], 'created_at' => now()]);

        return ['accepted' => true];
    }

    public function templates(): mixed
    {
        return collect(config('cards.templates'))->map(fn ($t, $key) => ['key' => $key, 'name' => $t['name'], 'manufacturer' => $t['manufacturer'], 'series' => $t['series'], 'width' => $t['width'], 'height' => $t['height'], 'version' => $t['version']])->values();
    }

    public function profiles(Request $request): mixed
    {
        return PrinterProfile::where('user_id', $request->user()->id)->get();
    }

    public function saveProfile(Request $request): mixed
    {
        $d = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:100', 'template_key' => ['required', Rule::in(array_keys(config('cards.templates')))], 'settings' => 'required|array', 'calibrated' => 'required|boolean']);
        $settings = CardInput::settings($d['settings']);
        $p = isset($d['id']) ? PrinterProfile::where('user_id', $request->user()->id)->findOrFail($d['id']) : new PrinterProfile(['user_id' => $request->user()->id]);
        $p->fill(['name' => $d['name'], 'template_key' => $d['template_key'], 'settings' => $settings, 'calibrated_at' => $request->boolean('calibrated') ? now() : null])->save();

        return $p;
    }

    public function deleteProfile(Request $request, int $id): mixed
    {
        PrinterProfile::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function ledger(Request $request): mixed
    {
        return LedgerEntry::where('user_id', $request->user()->id)->latest('id')->paginate(30);
    }
}
