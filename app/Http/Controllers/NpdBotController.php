<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\NpdQueue;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NpdBotController extends Controller
{
    public function index(NpdQueue $queue): array
    {
        return ['payments' => $queue->payments()];
    }

    public function store(Request $request): mixed
    {
        $data = $request->validate([
            'shop_id' => ['required', 'string', 'max:64'],
            'payment_id' => ['required', 'string', 'max:100'],
            'amount_kopecks' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'in:RUB'],
            'receipt_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'receipt_url' => ['required', 'string', 'max:1000', 'url:https'],
        ]);
        $expectedUrl = 'https://lknpd.nalog.ru/api/v1/receipt/'.config('cards.operator_inn').'/'.$data['receipt_id'].'/print';
        abort_unless($data['receipt_url'] === $expectedUrl, 422, 'Ссылка не соответствует идентификатору чека или ИНН владельца сервиса.');
        try {
            DB::transaction(function () use ($data) {
                $payment = Payment::where('npd_shop_id', $data['shop_id'])->where('provider_id', $data['payment_id'])->lockForUpdate()->firstOrFail();
                $snapshot = $payment->npd_payload;
                abort_unless($snapshot && $payment->status === 'succeeded' && $payment->test_mode === false && $payment->receipt_mode === 'npd_manual', 409, 'Платёж не выдавался боту для формирования чека.');
                abort_unless($snapshot['amount_kopecks'] === (int) $data['amount_kopecks'] && $snapshot['currency'] === $data['currency'], 409, 'Сумма или валюта платежа не совпадает.');
                if ($payment->receipt_url || $payment->receipt_recorded_at || $payment->npd_receipt_id) {
                    abort_unless($payment->receipt_url === $data['receipt_url'] && (! $payment->npd_receipt_id || $payment->npd_receipt_id === $data['receipt_id']), 409, 'К платежу уже прикреплён другой чек.');
                }
                if ($payment->npd_receipt_id === $data['receipt_id'] && $payment->receipt_recorded_at) {
                    return;
                }
                $payment->update(['npd_receipt_id' => $data['receipt_id'], 'receipt_url' => $data['receipt_url'], 'receipt_recorded_at' => $payment->receipt_recorded_at ?? now()]);
            }, 3);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                abort(409, 'Этот чек уже прикреплён к другому платежу.');
            }
            throw $e;
        }

        return response()->noContent();
    }
}
