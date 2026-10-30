<?php

namespace App\Services;

use App\Models\LandlordSetting;
use App\Models\MpesaTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * M-Pesa (Safaricom Daraja) STK-push collection, with two possible
 * destinations per landlord:
 *
 * - "direct": the landlord has registered their own shortcode + Daraja
 *   passkey (see LandlordSetting payout_shortcode/payout_shortcode_type/
 *   mpesa_passkey) — rent settles straight into their account, Kats never
 *   touches it, nothing to disburse.
 * - "platform": the landlord hasn't (the common case) — rent settles into
 *   Kats' own paybill (config('services.mpesa')) and is disbursed out to
 *   them separately (see DisbursementService).
 *
 * Either way, the Consumer Key/Secret identifying the calling Daraja *app*
 * are always Kats' own shared ones — only the receiving shortcode (and its
 * passkey, which Safaricom ties to that one shortcode) ever needs to be the
 * landlord's.
 */
class PaymentGatewayService
{
    public function isDirectlyConfigured(int $landlordId): bool
    {
        return (bool) LandlordSetting::get($landlordId, 'payout_shortcode')
            && (bool) LandlordSetting::getEncrypted($landlordId, 'mpesa_passkey');
    }

    public function isPlatformConfigured(): bool
    {
        return (bool) $this->platformSetting('shortcode') && (bool) $this->platformEncryptedSetting('passkey');
    }

    /**
     * Kats' own paybill credentials — editable live from Admin \> Settings
     * \> Payments (see Admin\Settings\Index), stored in the global Setting
     * store so an admin can change them without touching .env or
     * restarting anything. Falls back to config('services.mpesa.*') (i.e.
     * the .env values) until an admin actually sets one via that panel,
     * so a fresh install keeps working exactly as before.
     */
    private function platformSetting(string $key): mixed
    {
        return Setting::get("mpesa_{$key}", config("services.mpesa.{$key}"));
    }

    private function platformEncryptedSetting(string $key): mixed
    {
        return Setting::getEncrypted("mpesa_{$key}", config("services.mpesa.{$key}"));
    }

    /** Which shortcode a push for this landlord will actually settle into. */
    public function collectionModeFor(int $landlordId): string
    {
        return $this->isDirectlyConfigured($landlordId) ? 'direct' : 'platform';
    }

    /**
     * The secret path segment callbackUrl() embeds when initiating a push —
     * MpesaCallbackController checks every inbound callback against this
     * same value, so a secret saved live via Admin > Settings > Payments is
     * honored on both ends without needing to also be set in .env.
     */
    public function callbackSecret(): ?string
    {
        return $this->platformEncryptedSetting('callback_secret');
    }

    /**
     * The platform's own M-Pesa credentials (not any one landlord's) are
     * missing — every tenant not covered by their landlord's own direct
     * setup can't pay at all, and nothing ever told an admin that before.
     * Debounced to once a day via cache rather than a DB query, since this
     * runs on every failed STK push attempt while it stays broken.
     */
    private function alertAdminsOfMisconfiguration(): void
    {
        if (Cache::has('mpesa_platform_misconfigured_alert_sent')) {
            return;
        }

        Cache::put('mpesa_platform_misconfigured_alert_sent', true, now()->addDay());

        foreach (User::role('admin')->get() as $admin) {
            app(NotificationService::class)->notify(
                $admin,
                'mpesa_gateway_misconfigured',
                'M-Pesa Platform Payments Are Not Configured',
                'A tenant just tried to pay via M-Pesa, but the platform\'s own M-Pesa credentials are not configured (MPESA_CONSUMER_KEY/SECRET/SHORTCODE/PASSKEY). Every tenant whose landlord hasn\'t set up direct collection cannot pay via M-Pesa until this is fixed.',
                ['in_app', 'email'],
            );
        }
    }

    private function baseUrl(): string
    {
        return $this->platformSetting('env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * The secret callback token (see MpesaCallbackController) is appended
     * here, automatically, rather than asking every admin to remember to
     * include it in MPESA_CALLBACK_URL by hand.
     */
    private function callbackUrl(): string
    {
        $base = $this->platformSetting('callback_url');

        if (! $base) {
            throw new RuntimeException('The M-Pesa callback URL is not configured — Safaricom needs a public HTTPS URL to send the payment result to. Set it under Admin › Settings › Payments.');
        }

        $secret = $this->platformEncryptedSetting('callback_secret');

        if (! $secret) {
            throw new RuntimeException('The M-Pesa callback secret is not configured — the callback endpoint cannot be secured without it. Set it under Admin › Settings › Payments.');
        }

        return rtrim($base, '/').'/'.$secret;
    }

    private function accessToken(): string
    {
        $key = (string) $this->platformSetting('consumer_key');
        $secret = (string) $this->platformEncryptedSetting('consumer_secret');

        if (! $key || ! $secret) {
            throw new RuntimeException('M-Pesa API credentials are not configured.');
        }

        return Cache::remember('mpesa_access_token:'.md5($key), 55 * 60, function () use ($key, $secret) {
            $response = Http::withBasicAuth($key, $secret)
                ->get($this->baseUrl().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            if (! $response->successful()) {
                // Truncated — an OAuth failure response can itself carry a
                // partial token/credential echo, and this log has no
                // rotation/retention policy of its own to bound how long it
                // persists.
                Log::warning('M-Pesa OAuth token request failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);

                throw new RuntimeException('Could not authenticate with M-Pesa. Please try again shortly.');
            }

            return $response->json('access_token');
        });
    }

    /**
     * Used by the admin Payments settings panel's "Test Connection" button
     * — fetches a fresh OAuth token (bypassing the cached one) so a
     * just-saved credential is validated immediately against Safaricom
     * rather than only discovered broken the next time a tenant pays.
     *
     * @throws RuntimeException when nothing is configured yet, or Safaricom rejects the credentials.
     */
    public function testPlatformConnection(): void
    {
        $key = (string) $this->platformSetting('consumer_key');
        $secret = (string) $this->platformEncryptedSetting('consumer_secret');

        if (! $key || ! $secret) {
            throw new RuntimeException('Enter a Consumer Key and Consumer Secret first.');
        }

        Cache::forget('mpesa_access_token:'.md5($key));

        $response = Http::withBasicAuth($key, $secret)
            ->get($this->baseUrl().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('errorMessage') ?? 'Safaricom rejected these credentials.');
        }
    }

    /**
     * @param  int  $landlordId  Whose rent this is — decides which shortcode actually receives it.
     * @param  string  $phone  MSISDN to prompt, any common Kenyan format.
     * @return array{MerchantRequestID:string,CheckoutRequestID:string,ResponseCode:string,ResponseDescription:string,CustomerMessage:string}
     *
     * @throws RuntimeException when neither the landlord's own shortcode nor Kats' platform one is configured.
     */
    public function initiateStkPush(int $landlordId, string $phone, float $amount, string $accountReference, string $description): array
    {
        $direct = $this->isDirectlyConfigured($landlordId);

        if (! $direct && ! $this->isPlatformConfigured()) {
            $this->alertAdminsOfMisconfiguration();

            throw new RuntimeException('M-Pesa is not configured yet. Please try again later or pay another way.');
        }

        $shortcode = $direct
            ? (string) LandlordSetting::get($landlordId, 'payout_shortcode')
            : (string) $this->platformSetting('shortcode');
        $passkey = $direct
            ? (string) LandlordSetting::getEncrypted($landlordId, 'mpesa_passkey')
            : (string) $this->platformEncryptedSetting('passkey');

        $callbackUrl = $this->callbackUrl();

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode.$passkey.$timestamp);
        $normalizedPhone = app(MpesaService::class)->normalizePhone($phone);

        $response = Http::withToken($this->accessToken())
            ->post($this->baseUrl().'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) round($amount),
                'PartyA' => $normalizedPhone,
                // The destination shortcode decided above — the landlord's
                // own when direct collection is configured, Kats' otherwise.
                'PartyB' => $shortcode,
                'PhoneNumber' => $normalizedPhone,
                'CallBackURL' => $callbackUrl,
                'AccountReference' => substr($accountReference, 0, 12),
                'TransactionDesc' => substr($description, 0, 13),
            ]);

        if (! $response->successful()) {
            Log::warning('M-Pesa STK push failed', ['landlord_id' => $landlordId, 'status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);

            throw new RuntimeException($response->json('errorMessage') ?? 'M-Pesa did not accept this payment request.');
        }

        return $response->json();
    }

    /**
     * Asks Safaricom directly what happened to a push that's still sitting
     * 'pending' with no callback ever received — the client-side 3-minute
     * timeout (PayRent/PayDeposit::checkStatus()) only gives up locally; it
     * never actually finds out whether the payment went through. Returns
     * null when Safaricom itself can't be reached or the request fails,
     * never throws — this always runs unattended from a scheduled command.
     *
     * @return array{ResultCode?: int|string, ResultDesc?: string}|null
     */
    public function queryStkStatus(MpesaTransaction $transaction): ?array
    {
        if (! $transaction->checkout_request_id) {
            return null;
        }

        $tenancy = $transaction->tenancy()->with('house.block.property')->first();
        $landlordId = $tenancy?->house?->block?->property?->landlord_id;

        if (! $landlordId) {
            return null;
        }

        $direct = $this->isDirectlyConfigured($landlordId);
        $shortcode = $direct
            ? (string) LandlordSetting::get($landlordId, 'payout_shortcode')
            : (string) $this->platformSetting('shortcode');
        $passkey = $direct
            ? (string) LandlordSetting::getEncrypted($landlordId, 'mpesa_passkey')
            : (string) $this->platformEncryptedSetting('passkey');

        if (! $shortcode || ! $passkey) {
            return null;
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($shortcode.$passkey.$timestamp);

        try {
            $response = Http::withToken($this->accessToken())
                ->post($this->baseUrl().'/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $transaction->checkout_request_id,
                ]);
        } catch (\Throwable $e) {
            Log::warning('M-Pesa STK status query request failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('M-Pesa STK status query was not accepted', [
                'transaction_id' => $transaction->id, 'status' => $response->status(), 'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        return $response->json();
    }
}
