<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;

class DeferNpdPayment extends Command
{
    protected $signature = 'npd:defer {payment_id : ID платежа ЮKassa} {--hours=24 : Часов исключения; 0 — вернуть в очередь}';

    protected $description = 'Временно исключить проблемный платёж из выдачи боту НПД';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 0 || $hours > 8760) {
            $this->error('Укажите от 0 до 8760 часов.');

            return self::FAILURE;
        }
        $payment = Payment::where('provider_id', $this->argument('payment_id'))->where('test_mode', false)->where('status', 'succeeded')->where('receipt_mode', 'npd_manual')->first();
        if (! $payment) {
            $this->error('Реальный успешный платёж не найден.');

            return self::FAILURE;
        }
        $payment->update(['npd_deferred_until' => $hours ? now()->addHours($hours) : null]);
        $this->info($hours ? 'Платёж временно исключён из очереди.' : 'Временное исключение снято.');

        return self::SUCCESS;
    }
}
