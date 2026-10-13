<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCreditController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $search = trim($data['search'] ?? '');

        return User::select('id', 'name', 'email', 'credits')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
            ->orderBy('id')->paginate(20);
    }

    public function store(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000'],
            'request_key' => ['required', 'uuid'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            // Serialize requests from this administrator before checking the idempotency key.
            $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->is_support_admin, 403);
            $reference = 'manual:'.$admin->id.':'.strtolower($data['request_key']);
            $entry = LedgerEntry::where('reference', $reference)->first();
            if ($entry) {
                abort_unless((int) $entry->user_id === (int) $data['user_id'] && $entry->amount === (int) $data['amount'], 409, 'Этот запрос уже использован для другого начисления.');
            }
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if (! $entry) {
                abort_if($user->credits > 4294967295 - (int) $data['amount'], 422, 'Достигнут предел баланса аккаунта.');
                $user->increment('credits', (int) $data['amount']);
                $entry = LedgerEntry::create([
                    'user_id' => $user->id, 'amount' => (int) $data['amount'],
                    'balance' => $user->credits, 'reason' => 'manual',
                    'reference' => $reference, 'created_at' => now(),
                ]);
            }

            return ['user' => $user->only('id', 'name', 'email', 'credits'), 'entry' => $entry];
        }, 3);
    }
}
