<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around Safaricom's Daraja M-Pesa API (STK Push / "Lipa na M-Pesa Online").
 * Sandbox and production share the same request shapes and differ only in base URL.
 */
class MpesaService
{
    private function baseUrl(): string
    {
        return config('services.mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function accessToken(): string
    {
        return Cache::remember('mpesa_access_token', 55 * 60, function () {
            $key = config('services.mpesa.consumer_key');
            $secret = config('services.mpesa.consumer_secret');

            if (! $key || ! $secret) {
                throw new RuntimeException('M-Pesa consumer key/secret are not configured. Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in .env.');
            }

            $response = Http::withBasicAuth($key, $secret)
                ->get($this->baseUrl().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            if (! $response->successful()) {
                Log::warning('M-Pesa OAuth token request failed', ['status' => $response->status(), 'body' => $response->body()]);

                throw new RuntimeException('Could not authenticate with M-Pesa. Please try again shortly.');
            }

            return $response->json('access_token');
        });
    }

    /**
     * Initiate an STK Push ("pay now" prompt on the payer's phone).
     *
     * @param  string  $phone  Kenyan MSISDN, any common format — normalized to 2547XXXXXXXX.
     * @return array{MerchantRequestID:string,CheckoutRequestID:string,ResponseCode:string,ResponseDescription:string,CustomerMessage:string}
     */
    public function stkPush(string $phone, float $amount, string $accountReference, string $description): array
    {
        $shortcode = config('services.mpesa.shortcode');
        $passkey = config('services.mpesa.passkey');
        $callbackUrl = config('services.mpesa.callback_url');

        if (! $callbackUrl) {
            throw new RuntimeException('MPESA_CALLBACK_URL is not configured — Safaricom needs a public HTTPS URL to send the payment result to.');
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode.$passkey.$timestamp);

        $response = Http::withToken($this->accessToken())
            ->post($this->baseUrl().'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) round($amount),
                'PartyA' => $this->normalizePhone($phone),
                'PartyB' => $shortcode,
                'PhoneNumber' => $this->normalizePhone($phone),
                'CallBackURL' => $callbackUrl,
                'AccountReference' => substr($accountReference, 0, 12),
                'TransactionDesc' => substr($description, 0, 13),
            ]);

        if (! $response->successful()) {
            Log::warning('M-Pesa STK push failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException($response->json('errorMessage') ?? 'M-Pesa did not accept this payment request.');
        }

        return $response->json();
    }

    /** Normalizes 07XXXXXXXX / +2547XXXXXXXX / 2547XXXXXXXX into 2547XXXXXXXX. */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            $digits = '254'.$digits;
        }

        return $digits;
    }
}
