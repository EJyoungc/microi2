<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformUserInvitation extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $temporaryPassword,
        public ?string $assignedTeam = null,
        public ?string $invitedByName = null,
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Welcome to MicroMek Telemetry Platform'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(
                $this->invitedByName
                    ? __(':inviter has created an account for you on MicroMek Telemetry Platform.', ['inviter' => $this->invitedByName])
                    : __('An account has been created for you on the MicroMek Telemetry Platform.')
            );

        if ($this->assignedTeam) {
            $message->line(__('You have been assigned to: :team', ['team' => $this->assignedTeam]));
        }

        $message->line(__('Your login email is: :email', ['email' => $notifiable->email]))
            ->line(__('Your temporary password is: :password', ['password' => $this->temporaryPassword]))
            ->action(__('Access MicroMek Platform'), route('login'))
            ->line(__('For security, please change your password upon your first sign in.'))
            ->line(__('Thank you for using MicroMek!'));

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'assigned_team' => $this->assignedTeam,
            'invited_by' => $this->invitedByName,
        ];
    }
}
