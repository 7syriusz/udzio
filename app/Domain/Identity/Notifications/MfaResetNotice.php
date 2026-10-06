<?php

namespace App\Domain\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the account holder that their MFA was reset by someone else (E3.8d): what happened and what to do. */
class MfaResetNotice extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.mfa_reset.subject'))
            ->line(__('notifications.mfa_reset.done'))
            ->line(__('notifications.mfa_reset.next'))
            ->line(__('notifications.mfa_reset.not_you'));
    }
}
