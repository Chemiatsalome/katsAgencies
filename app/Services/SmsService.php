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
    // G-1A: both bounded explicitly — previously unbounded, which let a
    // stalled provider connection (see project memory: a real sandbox hang
    // observed during development) block indefinitely. Kept short: this is
    // a synchronous call inside a web request/scheduled command today, not
    // a background job with room for a long wait.
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 15;

    private function baseUrl(): string
    {
        return config('services.africastalking.sandbox')
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';
    }

    /**
     * @param  string  $phone  Any local or international format — normalized to +254... before sending.
     * @return string One of:
     *                 - 'accepted': the provider's response confirmed this recipient as sent. Billable.
     *                 - 'rejected': we have a definite "no" — either the provider's response said so, or
     *                   the request was never attempted at all (e.g. missing API key). Never billable,
     *                   and never ambiguous: nothing was left in flight either way.
     *                 - 'unknown': the HTTP call itself failed (timeout, connection reset, TLS error, ...).
     *                 G-1A investigation finding: cURL/Guzzle's own error classification cannot reliably
     *                 tell "never reached the provider" apart from "reached it, but the response never
     *                 arrived" (a generic operation-timeout error code covers both cases identically), so
     *                 every exception here is treated as genuinely unknown — never downgraded to a
     *                 safe-to-retry failure just because the exception happens to be a ConnectException.
     */
    public function send(string $phone, string $message): string
    {
        $apiKey = config('services.africastalking.api_key');
        $username = config('services.africastalking.username');

        if (! $apiKey) {
            Log::warning('SMS not sent — Africa\'s Talking API key is not configured.');

            return 'rejected';
        }

        $to = '+'.app(MpesaService::class)->normalizePhone($phone);

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->asForm()
                ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->post($this->baseUrl(), array_filter([
                    'username' => $username,
                    'to' => $to,
                    'message' => $message,
                    'from' => config('services.africastalking.sender_id'),
                ]));

            if (! $response->successful()) {
                Log::warning('SMS send failed', ['phone' => $to, 'status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);

                // A non-2xx HTTP response is still a definite answer from
                // the provider, not an ambiguous one — confirmed rejection.
                return 'rejected';
            }

            $recipients = $response->json('SMSMessageData.Recipients', []);
            $delivered = collect($recipients)->contains(fn ($r) => str_starts_with((string) ($r['status'] ?? ''), 'Success'));

            if (! $delivered) {
                // Just the delivery status, not the full provider response —
                // that already repeats the destination number back verbatim
                // with no added debugging value.
                Log::warning('SMS rejected by provider', ['phone' => $to, 'status' => collect($recipients)->pluck('status')->implode(', ')]);
            }

            return $delivered ? 'accepted' : 'rejected';
        } catch (\Throwable $e) {
            Log::warning('SMS send threw an exception — outcome unknown, not treated as a safe-to-retry failure', ['phone' => $to, 'error' => $e->getMessage()]);

            return 'unknown';
        }
    }
}
