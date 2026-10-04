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
            ->subject(__('notifications.contact_verification.subject'))
            ->line(__('notifications.contact_verification.code', ['code' => $this->code]))
            ->line(__('notifications.contact_verification.validity', ['minutes' => $this->ttlMinutes]));
    }
}
