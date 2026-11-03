<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * MsgWay (msgway.com): the text is an approved template on the panel whose first parameter is
 * replaced by the code. Same request as the messageway/messagewayphp SDK's sendViaSMS.
 */
class MsgwaySmsSender implements SmsSender
{
    public function __construct(
        private string $apiKey,
        private int $templateId,
    ) {}

    public function sendOtp(string $mobile, string $code): void
    {
        $response = Http::withHeaders(['apiKey' => $this->apiKey, 'accept-language' => 'fa'])
            ->acceptJson()
            ->timeout(10)
            ->post('https://api.msgway.com/send', [
                'method' => 'sms',
                'mobile' => $mobile,
                'templateID' => $this->templateId,
                'params' => [$code],
            ]);

        $masked = substr($mobile, 0, 4).'***'.substr($mobile, -4);

        if ($response->failed() || $response->json('status') !== 'success') {
            $message = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("MsgWay rejected the SMS to {$masked} (HTTP {$response->status()}): {$message}");
        }

        // MsgWay accepted it; look the reference up in the panel's sent report if it never arrives
        Log::info("MsgWay accepted the SMS to {$masked}", [
            'reference_id' => $response->json('referenceID'),
            'template_id' => $this->templateId,
        ]);
    }
}
