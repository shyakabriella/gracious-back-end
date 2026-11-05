<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $setupUrl,
        private readonly string $employeeName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Gracious Investment - Staff Invitation')
            ->greeting('Hello '.$this->employeeName.',')
            ->line('You have been invited to join the Gracious Investment management system.')
            ->line('To activate your account, please create your password using the secure link below.')
            ->action('Set Up My Password', $this->setupUrl)
            ->line('This invitation expires after 60 minutes.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }
}
