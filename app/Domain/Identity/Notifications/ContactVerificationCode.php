<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactVerificationCode extends Notification
{
    public function __construct(public readonly string $code, public readonly int $ttlMinutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Kod potwierdzenia adresu e-mail')
            ->line("Twój kod potwierdzenia: {$this->code}")
            ->line("Kod jest ważny przez {$this->ttlMinutes} minut. Jeśli to nie Ty, zignoruj tę wiadomość.");
    }
}
