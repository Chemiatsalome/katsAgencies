<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Africa's Talking's SMS API — the standard choice for
 * a Kenya-facing app, since it routes directly through local carriers
 * rather than a generic international gateway. Sandbox mode (the default
 * until real credentials are set) only ever reaches their simulator, never
 * an actual phone — nothing sent this way costs money or needs credit.
 */
class SmsService
{
    private function baseUrl(): string
    {
        return config('services.africastalking.sandbox')
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';
    }

    /** @param  string  $phone  Any local or international format — normalized to +254... before sending. */
    public function send(string $phone, string $message): bool
    {
        $apiKey = config('services.africastalking.api_key');
        $username = config('services.africastalking.username');

        if (! $apiKey) {
            Log::warning('SMS not sent — Africa\'s Talking API key is not configured.');

            return false;
        }

        $to = '+'.app(MpesaService::class)->normalizePhone($phone);

        try {
            $response = Http::asForm()
                ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->post($this->baseUrl(), array_filter([
                    'username' => $username,
                    'to' => $to,
                    'message' => $message,
                    'from' => config('services.africastalking.sender_id'),
                ]));

            if (! $response->successful()) {
                Log::warning('SMS send failed', ['phone' => $to, 'status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);

                return false;
            }

            $recipients = $response->json('SMSMessageData.Recipients', []);
            $delivered = collect($recipients)->contains(fn ($r) => str_starts_with((string) ($r['status'] ?? ''), 'Success'));

            if (! $delivered) {
                // Just the delivery status, not the full provider response —
                // that already repeats the destination number back verbatim
                // with no added debugging value.
                Log::warning('SMS rejected by provider', ['phone' => $to, 'status' => collect($recipients)->pluck('status')->implode(', ')]);
            }

            return $delivered;
        } catch (\Throwable $e) {
            Log::warning('SMS send threw an exception', ['phone' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
