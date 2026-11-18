<?php

namespace App\Notifications;

use App\Models\StaffInvite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invitation to join a shop's team. A new email gets the 6-digit code too; an existing account just accepts. */
class StaffInviteMail extends Notification
{
    public function __construct(public StaffInvite $invite, public string $url, public ?string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = $this->invite->supplier->name;
        $m = (new MailMessage)
            ->subject(($this->code ? $this->code.' · ' : '').'You are invited to join '.$shop.' on PartyRent')
            ->line(($this->invite->inviter?->name ?? $shop).' invited you to join **'.$shop.'** as **'.$this->invite->roleLabel().'**.');
        if ($this->code) {
            $m->line('Open the invitation, enter this code, and set your own password to create your PartyRent account:')
                ->line('# '.$this->code);
        } else {
            $m->line('Sign in with your PartyRent account and accept the invitation. You keep your own login and switch to Business Mode to work for the shop.');
        }

        return $m->action('Open the invitation', $this->url)
            ->line('The invitation expires in '.StaffInvite::TTL_HOURS.' hours.')
            ->line("If you don't know this shop, you can ignore this email. Nothing happens unless you accept.");
    }
}
