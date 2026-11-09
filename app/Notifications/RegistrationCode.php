<?php

namespace App\Notifications;

use App\Models\PendingRegistration;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit code a new sign-up types in to create their account. Sent to an on-demand address (no User yet). */
class RegistrationCode extends Notification
{
    public function __construct(public string $code, public string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->code.' is your PartyRent verification code')
            ->greeting('Hi '.strtok($this->name, ' ').'!')
            ->line('Enter this code on the PartyRent sign-up page to create your account:')
            ->line('# '.$this->code)
            ->line('The code expires in '.PendingRegistration::TTL_MINUTES.' minutes.')
            ->line("If you didn't try to sign up for PartyRent, you can ignore this email. No account will be created.");
    }
}
