<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyBonusEmail extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Подтверждение почты — ЛКО / ЛКР')
            ->view(['html' => 'mail.verify-email', 'text' => 'mail.verify-email-text'], [
                'verificationUrl' => URL::temporarySignedRoute('bonus-email.verify', now()->addHour(), [
                    'id' => $notifiable->id, 'hash' => sha1($notifiable->email),
                ]),
                'username' => $notifiable->username,
                'homeUrl' => rtrim(config('app.url'), '/'),
            ]);
    }
}
