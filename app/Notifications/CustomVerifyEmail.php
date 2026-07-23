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
            ->subject('Verifikasi Alamat Email Anda')
            ->greeting('Halo '.$notifiable->name.'!')
            ->line('Terima kasih telah mendaftar di Rencanakan.')
            ->line('Untuk menyelesaikan proses pendaftaran dan mulai menggunakan layanan kami, silakan verifikasi email Anda dengan mengklik tombol di bawah ini:')
            ->action('Verifikasi Email', $verificationUrl)
            ->line('Jika Anda tidak mendaftar di Rencanakan, abaikan email ini.')
            ->salutation('Terima kasih, Tim Rencanakan');
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
