<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\YooKassa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private YooKassa $gateway) {}

    public function packages(): array
    {
        return ['enabled' => config('cards.payments_enabled'), 'test' => config('cards.yookassa.test'), 'packages' => collect(config('cards.packages'))->map(fn ($p, $key) => $p + ['key' => $key])->values()];
    }

    public function index(Request $request): mixed
    {
        return Payment::where('user_id', $request->user()->id)->latest()->paginate(30);
    }

    public function store(Request $request): mixed
    {
        $request->validate(['package' => ['required', Rule::in(array_keys(config('cards.packages')))], 'request_key' => 'required|uuid', 'buyer_type' => 'required|in:person,company', 'buyer_name' => 'required|string|max:180', 'buyer_inn' => ['nullable', 'required_if:buyer_type,company', 'regex:/^(?:[0-9]{10}|[0-9]{12})$/']]);
        abort_unless(config('cards.payments_enabled'), 503, 'Приём платежей ещё не настроен.');
        $buyer = ['type' => $request->input('buyer_type'), 'name' => $request->input('buyer_name'), 'inn' => $request->input('buyer_type') === 'company' ? $request->input('buyer_inn') : null, 'email' => $request->user()->email];
        $payment = DB::transaction(function () use ($request, $buyer) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $p = Payment::where('user_id', $request->user()->id)->where('request_key', $request->input('request_key'))->first();
            if ($p) {
                abort_unless($p->package === $request->input('package') && $p->buyer === $buyer && $p->test_mode === config('cards.yookassa.test'), 409, 'Запрос уже использован.');

                return $p;
            }
            $pack = config('cards.packages.'.$request->input('package'));

            return Payment::create(['buyer' => $buyer, 'test_mode' => config('cards.yookassa.test'), 'receipt_mode' => config('cards.receipt_mode'), 'id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'request_key' => $request->input('request_key'), 'package' => $request->input('package'), 'amount' => $pack['amount'], 'credits' => $pack['credits']]);
        }, 3);
        if (! $payment->provider_id) {
            abort_if($payment->created_at->lt(now()->subHours(23)), 409, 'Срок подтверждения платежа истёк. Обратитесь в поддержку до повторной оплаты.');
            $result = $this->gateway->create($payment, $request->user()->email);
            abort_unless(isset($result['id'],$result['confirmation']['confirmation_url']), 502, 'Некорректный ответ платёжной системы.');
            abort_unless(($result['test'] ?? null) === $payment->test_mode, 422, 'Режим магазина ЮKassa не совпадает с настройкой YOOKASSA_TEST. Проверьте ключи и режим до оплаты.');
            $payment->update(['provider_id' => $result['id'], 'confirmation_url' => $result['confirmation']['confirmation_url']]);
        }

        return $payment;
    }

    public function refresh(Request $request, string $id): mixed
    {
        $p = Payment::where('user_id', $request->user()->id)->findOrFail($id);
        if ($p->provider_id) {
            $this->reconcile($p);
        }

        return $p->fresh();
    }

    public function webhook(Request $request): mixed
    {
        $request->validate(['object.id' => 'required|string|max:100']);
        $p = Payment::where('provider_id', $request->input('object.id'))->first();
        if ($p) {
            $this->reconcile($p);
        }

        return ['ok' => true];
    }

    public function reconcile(Payment $payment): void
    {
        // Never trust webhook payload status: retrieve the payment using merchant credentials.
        $remote = $this->gateway->fetch($payment->provider_id);
        abort_unless(($remote['id'] ?? null) === $payment->provider_id && ($remote['metadata']['order_id'] ?? null) === $payment->id && ($remote['amount']['currency'] ?? null) === 'RUB' && ($remote['amount']['value'] ?? null) === number_format($payment->amount / 100, 2, '.', '') && ($remote['test'] ?? null) === ($payment->test_mode ?? config('cards.yookassa.test')), 422, 'Платёж не прошёл проверку.');
        DB::transaction(function () use ($payment, $remote) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'succeeded') {
                return;
            }
            if (($remote['status'] ?? '') === 'succeeded' && ($remote['paid'] ?? false) === true) {
                $user = User::whereKey($p->user_id)->lockForUpdate()->firstOrFail();
                $user->increment('credits', $p->credits);
                LedgerEntry::create(['user_id' => $user->id, 'amount' => $p->credits, 'balance' => $user->credits, 'reason' => 'purchase', 'reference' => 'payment:'.$p->id, 'created_at' => now()]);
                $p->update(['status' => 'succeeded', 'paid_at' => now()]);
            } elseif (($remote['status'] ?? '') === 'canceled') {
                $p->update(['status' => 'canceled']);
            }
        }, 3);
    }
}
