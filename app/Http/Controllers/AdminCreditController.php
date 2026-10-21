<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCreditController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $search = trim($data['search'] ?? '');

        $users = User::select('id', 'name', 'email', 'username', 'credits')
            ->addSelect([
                'employees_count' => Card::selectRaw('count(*)')->whereColumn('cards.user_id', 'users.id'),
                'registry_count' => Generation::selectRaw('count(*)')->whereColumn('generations.user_id', 'users.id')->whereNull('deleted_at'),
            ])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('username', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
            ->orderBy('id')->get();

        return ['data' => $users, 'total' => $users->count()];
    }

    public function store(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:-100000', 'max:100000', 'not_in:0'],
            'request_key' => ['required', 'uuid'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            // Serialize requests from this administrator before checking the idempotency key.
            $admin = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->is_support_admin, 403);
            $reference = 'manual:'.$admin->id.':'.strtolower($data['request_key']);
            $entry = LedgerEntry::where('reference', $reference)->first();
            if ($entry) {
                abort_unless((int) $entry->user_id === (int) $data['user_id'] && $entry->amount === (int) $data['amount'], 409, 'Этот запрос уже использован для другого изменения баланса.');
            }
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if (! $entry) {
                abort_if($user->credits > 4294967295 - (int) $data['amount'], 422, 'Достигнут предел баланса аккаунта.');
                abort_if($user->credits + (int) $data['amount'] < 0, 422, 'Нельзя списать больше генераций, чем есть на балансе.');
                $user->credits += (int) $data['amount'];
                $user->save();
                $entry = LedgerEntry::create([
                    'user_id' => $user->id, 'amount' => (int) $data['amount'],
                    'balance' => $user->credits, 'reason' => 'manual',
                    'reference' => $reference, 'created_at' => now(),
                ]);
            }

            return ['user' => $user->only('id', 'name', 'email', 'username', 'credits'), 'entry' => $entry];
        }, 3);
    }
}
