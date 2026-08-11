<?php

namespace App\Services;

use App\Models\LandlordSetting;
use RuntimeException;

/**
 * Scaffold for M-Pesa (Safaricom Daraja) STK-push collection.
 *
 * Not wired to a live endpoint — this environment has no real Daraja API
 * credentials. The shape here matches what Daraja's STK-push request/response
 * looks like, so plugging in real credentials via Landlord Settings later is a
 * drop-in: fill in the HTTP call in initiateStkPush() once credentials exist.
 */
class PaymentGatewayService
{
    public function isConfigured(int $landlordId): bool
    {
        return (bool) LandlordSetting::get($landlordId, 'mpesa_shortcode')
            && (bool) LandlordSetting::getEncrypted($landlordId, 'mpesa_consumer_key');
    }

    /**
     * @param  int  $landlordId
     * @param  string  $phone  MSISDN to prompt, e.g. 2547XXXXXXXX
     * @param  float  $amount
     * @param  string  $accountReference  e.g. the rent charge/tenancy reference
     * @return array{merchant_request_id: string, checkout_request_id: string, status: string}
     *
     * @throws RuntimeException when the landlord hasn't configured a till/paybill + credentials yet.
     */
    public function initiateStkPush(int $landlordId, string $phone, float $amount, string $accountReference): array
    {
        if (! $this->isConfigured($landlordId)) {
            throw new RuntimeException(
                'M-Pesa is not configured for this account yet. Add your till/paybill number and API '.
                'credentials under Settings → Payment Gateway before requesting a payment.'
            );
        }

        // Real integration point: POST to Daraja's /mpesa/stkpush/v1/processrequest
        // using the shortcode/passkey/consumer key+secret stored in LandlordSetting,
        // then return Safaricom's MerchantRequestID/CheckoutRequestID for polling.
        throw new RuntimeException('M-Pesa STK-push is not connected to a live gateway in this environment.');
    }
}
