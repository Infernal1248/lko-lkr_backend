<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class YooKassa
{
    public static function ready(): bool
    {
        return (bool) config('cards.payments_enabled') && filled(config('cards.yookassa.shop_id')) && filled(config('cards.yookassa.secret'));
    }

    public static function unavailableMessage(): string
    {
        return config('cards.payments_enabled')
            ? 'Не заполнены реквизиты магазина ЮKassa. Обратитесь в поддержку.'
            : 'Приём платежей отключён владельцем сервиса. Обратитесь в поддержку.';
    }

    private function client(): PendingRequest
    {
        abort_unless(self::ready(), 503, self::unavailableMessage());

        return Http::withBasicAuth(config('cards.yookassa.shop_id'), config('cards.yookassa.secret'))->baseUrl('https://api.yookassa.ru/v3')->acceptJson()->timeout(20);
    }

    public function create(Payment $payment, string $email): array
    {
        $amount = ['value' => number_format($payment->amount / 100, 2, '.', ''), 'currency' => 'RUB'];

        $payload = [
            'amount' => $amount, 'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => config('app.url').'/?payment='.$payment->id],
            'description' => 'Пакет: '.$payment->credits.' генераций ЛКО/ЛКР',
            'metadata' => ['order_id' => $payment->id],
        ];
        $mode = $payment->receipt_mode ?? config('cards.receipt_mode');
        abort_unless(in_array($mode, ['54fz', 'npd_manual']), 503, 'Не настроен режим формирования чеков.');
        if ($mode === '54fz') {
            $payload['receipt'] = ['customer' => ['email' => $email], 'items' => [[
                'description' => $payment->credits.' генераций карточек ЛКО/ЛКР', 'quantity' => '1.00',
                'amount' => $amount, 'vat_code' => config('cards.yookassa.vat_code'),
                'payment_mode' => 'full_payment', 'payment_subject' => 'service',
            ]]];
        }

        return $this->client()->withHeaders(['Idempotence-Key' => $payment->id])->post('/payments', $payload)->throw()->json();
    }

    public function fetch(string $id, int $timeout = 20): array
    {
        return $this->client()->timeout($timeout)->get('/payments/'.rawurlencode($id))->throw()->json();
    }
}
