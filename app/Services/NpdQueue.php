<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NpdQueue
{
    public function candidates(): Builder
    {
        return Payment::where('status', 'succeeded')->where('test_mode', false)
            ->where('receipt_mode', 'npd_manual')->whereNotNull('provider_id')
            ->whereNull('receipt_recorded_at')->whereNull('receipt_url')
            ->where('npd_excluded', false)
            ->where('paid_at', '>=', CarbonImmutable::parse(config('npd.since'))->utc())
            ->where(fn ($q) => $q->whereNull('npd_deferred_until')->orWhere('npd_deferred_until', '<=', now()));
    }

    // Called only with the result of an authenticated YooKassa GET, never a webhook body.
    public function freeze(Payment $payment, array $remote): void
    {
        if ($payment->npd_payload || $payment->test_mode || $payment->receipt_mode !== 'npd_manual' || $payment->receipt_recorded_at || $payment->receipt_url) {
            return;
        }
        $shop = (string) config('cards.yookassa.shop_id');
        if ($shop === '' || ($remote['recipient']['account_id'] ?? null) !== $shop
            || ($remote['id'] ?? null) !== $payment->provider_id
            || ($remote['metadata']['order_id'] ?? null) !== $payment->id
            || ($remote['status'] ?? null) !== 'succeeded' || ($remote['paid'] ?? null) !== true
            || ($remote['test'] ?? null) !== false || ($remote['amount']['currency'] ?? null) !== 'RUB'
            || ($remote['amount']['value'] ?? null) !== number_format($payment->amount / 100, 2, '.', '')
            || ! is_string($remote['captured_at'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T.+(?:Z|[+-]\d{2}:\d{2})$/', $remote['captured_at'])) {
            throw new RuntimeException('Payment verification failed');
        }
        $paid = CarbonImmutable::parse($remote['captured_at'])->utc();
        if ($paid->lt(CarbonImmutable::parse(config('npd.since')))) {
            $payment->update(['npd_excluded' => true]);

            return;
        }
        $buyer = $payment->buyer;
        if (! in_array($buyer['type'] ?? null, ['person', 'company'], true)) {
            throw new RuntimeException('Buyer type is missing');
        }
        $customer = ['type' => 'PERSON'];
        if ($buyer['type'] === 'company') {
            if (! preg_match('/^(?:[0-9]{10}|[0-9]{12})$/', $buyer['inn'] ?? '') || empty($buyer['name'])) {
                throw new RuntimeException('Company details are missing');
            }
            $customer = ['type' => 'COMPANY', 'inn' => $buyer['inn'], 'name' => $buyer['name']];
        }
        $payment->update(['npd_shop_id' => $shop, 'paid_at' => $paid, 'npd_payload' => [
            'shop_id' => $shop, 'payment_id' => $payment->provider_id,
            'status' => 'succeeded', 'test' => false, 'amount_kopecks' => $payment->amount,
            'currency' => 'RUB', 'paid_at' => $paid->toIso8601String(),
            'description' => 'Пакет из '.$payment->credits.' генераций личных карточек ЛКО/ЛКР',
            'buyer' => $customer,
        ]]);
    }

    public function payments(): array
    {
        // Backfill payments confirmed before deployment, bounded for shared hosting.
        $pending = $this->candidates()->whereNull('npd_payload')->orderBy('paid_at')->limit(2)->get();
        foreach ($pending as $payment) {
            try {
                $remote = app(YooKassa::class)->fetch($payment->provider_id, 5);
                DB::transaction(function () use ($payment, $remote) {
                    $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    $this->freeze($locked, $remote);
                }, 3);
            } catch (\Throwable $e) {
                Payment::whereKey($payment->id)->update(['npd_deferred_until' => now()->addMinutes(15)]);
                Log::warning('NPD payment verification deferred', ['payment_id' => $payment->id]);
            }
        }

        return $this->candidates()->where('npd_shop_id', (string) config('cards.yookassa.shop_id'))
            ->whereNotNull('npd_payload')->orderBy('paid_at')->orderBy('id')->limit(100)
            ->get()->map(fn (Payment $p) => $p->npd_payload)->all();
    }
}
