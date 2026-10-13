<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function index(Request $request): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);

        return Payment::where('status', 'succeeded')->where('test_mode', false)->where('receipt_mode', 'npd_manual')->orderByRaw('receipt_recorded_at IS NOT NULL')->orderBy('paid_at')->paginate(30);
    }

    public function update(Request $request, string $id): mixed
    {
        abort_unless($request->user()->is_support_admin, 403);
        $data = $request->validate(['receipt_url' => ['required', 'url:https', 'max:1000', 'regex:~^https://lknpd\.nalog\.ru/api/v1/receipt/[^\s]+$~']]);

        return DB::transaction(function () use ($id, $data) {
            $p = Payment::where('status', 'succeeded')->where('test_mode', false)->where('receipt_mode', 'npd_manual')->lockForUpdate()->findOrFail($id);
            abort_if($p->receipt_url && $p->receipt_url !== $data['receipt_url'], 409, 'К платежу уже прикреплён другой чек.');
            $p->update(['receipt_url' => $data['receipt_url'], 'receipt_recorded_at' => $p->receipt_recorded_at ?? now()]);

            return $p;
        }, 3);
    }
}
