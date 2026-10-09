<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class YooKassa
{
    private function client(): PendingRequest
    {
        abort_unless(config('cards.payments_enabled') && config('cards.yookassa.shop_id') && config('cards.yookassa.secret'), 503, 'Приём платежей ещё не настроен.');

        return Http::withBasicAuth(config('cards.yookassa.shop_id'), config('cards.yookassa.secret'))->baseUrl('https://api.yookassa.ru/v3')->acceptJson()->timeout(20);
    }

    public function create(Payment $payment, string $email): array
    {
        $amount = ['value' => number_format($payment->amount / 100, 2, '.', ''), 'currency' => 'RUB'];

        return $this->client()->withHeaders(['Idempotence-Key' => $payment->id])->post('/payments', [
            'amount' => $amount, 'capture' => true, 'confirmation' => ['type' => 'redirect', 'return_url' => config('app.url').'/?payment='.$payment->id],
            'description' => 'Пакет: '.$payment->credits.' генераций ЛКР', 'metadata' => ['order_id' => $payment->id],
            'receipt' => ['customer' => ['email' => $email], 'items' => [['description' => $payment->credits.' генераций карточек ЛКР', 'quantity' => '1.00', 'amount' => $amount, 'vat_code' => config('cards.yookassa.vat_code'), 'payment_mode' => 'full_payment', 'payment_subject' => 'service']]],
        ])->throw()->json();
    }

    public function fetch(string $id): array
    {
        return $this->client()->get('/payments/'.rawurlencode($id))->throw()->json();
    }
}
