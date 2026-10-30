<?php

namespace App\Services;

/**
 * Kenyan phone number normalization — used wherever an MSISDN needs to be
 * in Safaricom's 2547XXXXXXXX form, which is more than just M-Pesa: SMS
 * (SmsService) and WhatsApp click-to-chat links (Tenancies\Index) both
 * reuse this rather than duplicating the same digit-shuffling logic.
 *
 * The actual STK-push integration lives in PaymentGatewayService, which is
 * landlord-aware (each landlord's own shortcode/passkey) rather than a
 * single shared merchant account.
 */
class MpesaService
{
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
