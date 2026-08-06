<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends VerifyEmail
{
    /**
     * Build the verification email message.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject(__('Verify Your Email Address'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Thank you for registering at Rencanakan.'))
            ->line(__('To complete your registration and start using our services, please verify your email by clicking the button below:'))
            ->action(__('Verify Email'), $verificationUrl)
            ->line(__('If you did not register at Rencanakan, please ignore this email.'))
            ->salutation(__('Thanks, Rencanakan Team'));
    }

    /**
     * Generate the verification URL.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    protected function verificationUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
