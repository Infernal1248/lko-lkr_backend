<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationCode extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Код регистрации — ЛКО / ЛКР')
            ->view(['html' => 'mail.registration-code', 'text' => 'mail.registration-code-text'], ['code' => $this->code, 'homeUrl' => rtrim(config('app.url'), '/')]);
    }
}
