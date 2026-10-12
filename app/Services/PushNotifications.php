<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PushSubscription;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PushNotifications
{
    public function __construct(private WebPushSender $sender) {}

    public static function enabled(): bool
    {
        return (bool) config('push.enabled') && filled(config('push.public_key')) && filled(config('push.private_key'));
    }

    private function recipients(array $userIds, array $payload): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            $this->send(PushSubscription::whereIn('user_id', $userIds)->get(), $payload);
        } catch (\Throwable) {
            // Push failure must never roll back a message or a credited payment.
            Log::warning('Push notification could not be prepared.');
        }
    }

    public function supportMessage(SupportTicket $ticket, bool $fromSupport, int $authorId): void
    {
        if (! self::enabled()) {
            return;
        }
        $users = $fromSupport ? [$ticket->user_id] : User::where('is_support_admin', true)->whereKeyNot($authorId)->pluck('id')->all();
        $this->recipients($users, [
            'title' => $fromSupport ? 'Поддержка ответила' : 'Новое сообщение в поддержку',
            'body' => $fromSupport ? 'Откройте обращение, чтобы прочитать ответ.' : 'Пользователь ожидает ответа. Откройте обращение.',
            'url' => '/?push=support&ticket='.$ticket->id,
            'tag' => 'support-'.$ticket->id,
        ]);
    }

    public function paymentSucceeded(Payment $payment): void
    {
        if (! self::enabled()) {
            return;
        }
        $test = (bool) ($payment->test_mode ?? config('cards.yookassa.test'));
        $this->recipients(User::where('is_support_admin', true)->pluck('id')->all(), [
            'title' => $test ? 'Тестовая оплата прошла' : 'Получена новая оплата',
            'body' => $test ? 'Это тестовый платёж. Чек НПД не нужен.' : ($payment->receipt_mode === 'npd_manual' ? 'Сформируйте чек в «Моём налоге» и прикрепите его в кабинете.' : 'Платёж подтверждён. Проверьте оформление чека.'),
            'url' => '/?push=receipts',
            'tag' => 'payment-'.$payment->id,
        ]);
    }

    public function send(Collection $subscriptions, array $payload): int
    {
        if (! self::enabled() || $subscriptions->isEmpty()) {
            return 0;
        }
        try {
            $results = $this->sender->send($subscriptions, $payload);
            $accepted = 0;
            foreach ($results as $id => $status) {
                if ($status >= 200 && $status < 300) {
                    $accepted++;
                } elseif (in_array($status, [404, 410], true)) {
                    PushSubscription::whereKey($id)->delete();
                } else {
                    Log::warning('Push service did not accept notification.', ['subscription_id' => $id, 'status' => $status]);
                }
            }

            return $accepted;
        } catch (\Throwable) {
            Log::warning('Push transport failed.');

            return 0;
        }
    }
}
