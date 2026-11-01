<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Melipayamak's shared service line ("خدمات اشتراکی"): the text is an approved template (body ID)
 * whose {0} placeholder is replaced by the code, so it is delivered even to numbers that block ads.
 */
class MelipayamakSmsSender implements SmsSender
{
    public function __construct(
        private string $username,
        private string $password,
        private int $bodyId,
    ) {}

    public function sendOtp(string $mobile, string $code): void
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', [
                'username' => $this->username,
                'password' => $this->password,
                'bodyId' => $this->bodyId,
                'to' => $mobile,
                'text' => $code,
            ])
            ->throw();

        // a delivery ID (a long number) means it was accepted; short values are error codes
        $recId = (string) $response->json('Value');
        if ($response->json('RetStatus') !== 1 || strlen($recId) < 15) {
            throw new RuntimeException("Melipayamak rejected the SMS: {$response->json('StrRetStatus')} ({$recId})");
        }
    }
}
