<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public array $templateData;

    public function __construct($otp, array $templateData = [])
    {
        $this->otp = $otp;
        $this->templateData = array_merge([
            'otp' => $otp,
            'otp_code' => $otp,
            'code' => $otp,
            'expires_in' => '5 minutes',
            'expiry_time' => '5',
            'app_name' => $this->brandName(),
            'brand_name' => $this->brandName(),
        ], $templateData);
    }

    public function build()
    {
        return $this->from($this->senderAddress(), config('mail.from.name', 'OTP Service'))
            ->subject('Your OTP Code')
            ->withSymfonyMessage(fn ($message) => $this->addPriorityHeaders($message))
            ->view('emails.otp');
    }

    private function brandName(): string
    {
        return 'Express Bazaar';
    }

    private function senderAddress(): string
    {
        $configuredFrom = config('mail.from.address');
        $smtpUsername = config('mail.mailers.smtp.username');

        if (
            config('mail.default') === 'smtp'
            && $smtpUsername
            && filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
        ) {
            return $smtpUsername;
        }

        return $configuredFrom;
    }

    private function addPriorityHeaders($message): void
    {
        $headers = $message->getHeaders();
        $headers->addTextHeader('X-Priority', '1');
        $headers->addTextHeader('X-MSMail-Priority', 'High');
        $headers->addTextHeader('Importance', 'High');
    }
}
