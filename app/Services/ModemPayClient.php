<?php

// app/Services/ModemPayClient.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around ModemPay's REST API. Every endpoint, field, and
 * behavior here was verified directly against docs.modempay.com this
 * session (not assumed) — see the inline notes on the few points ModemPay's
 * own documentation left genuinely ambiguous or undocumented, which are
 * handled defensively rather than guessed.
 *
 * Base: https://api.modempay.com, Bearer {secret_key} auth.
 */
class ModemPayClient
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.modempay.base_url'), '/');
        $this->secretKey = (string) config('services.modempay.secret_key');
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->acceptJson();
    }

    /**
     * POST /v1/payments — create a Payment Intent.
     *
     * CONFIRMED LIVE (Task 11, against a real sk_test_ account) — the
     * request body must be wrapped in a top-level "data" object:
     * {"data": {"amount": ..., "currency": ..., ...}}. This was never
     * consistently documented (different doc pages showed different,
     * unwrapped examples) and the original implementation sent the body
     * flat, which failed every live attempt with a generic 400 "Invalid
     * data provided" — confirmed to be this wrapping issue specifically,
     * not a field-name or currency problem, by testing every other
     * plausible variation first and finding only this fixed it.
     *
     * Response data confirmed live: payment_intent_id (a stable UUID —
     * the general-purpose correlation identifier), intent_secret (a
     * separate, longer opaque token required specifically by
     * verifyPaymentIntent() below — NOT interchangeable with
     * payment_intent_id, confirmed live), payment_link, amount,
     * transaction_fee, transaction_fee_type, currency, expires_at,
     * status. GMD confirmed accepted as currency, live.
     */
    public function createPaymentIntent(array $data): array
    {
        $response = $this->client()->post('/v1/payments', ['data' => $data]);
        $response->throw();

        return $response->json();
    }

    /**
     * GET /v1/payments/verify?intent_secret=... — retrieve a Payment
     * Intent's current state. CONFIRMED LIVE this must be the
     * intent_secret specifically — passing payment_intent_id/id in that
     * param instead fails with a 500 ("WHERE parameter intent_secret has
     * invalid undefined value"). Used by the reconciliation job and to
     * double-check a webhook's claim before trusting it.
     */
    public function verifyPaymentIntent(string $intentSecret): array
    {
        $response = $this->client()->get('/v1/payments/verify', [
            'intent_secret' => $intentSecret,
        ]);
        $response->throw();

        return $response->json();
    }

    /**
     * PATCH /v1/payments/{id} — cancel a Payment Intent (e.g. an
     * awaiting_payment order past its timeout).
     */
    public function cancelPaymentIntent(string $intentId): array
    {
        $response = $this->client()->patch("/v1/payments/{$intentId}");
        $response->throw();

        return $response->json();
    }

    /**
     * POST /v1/transfers — pay a farmer. Confirmed fields (from docs
     * only, NOT live-verified — see below): amount, currency, network,
     * account_number, beneficiary_name, narration (optional), metadata
     * (optional). The Idempotency-Key header is confirmed (in docs) as
     * required.
     *
     * ⚠️ UNVERIFIED: Task 11 confirmed live that POST /v1/payments requires
     * its body wrapped in a top-level "data" object, contradicting the
     * (inconsistent) docs. Whether POST /v1/transfers has the SAME
     * requirement has NOT been tested — live payout/transfer calls are
     * explicitly out of scope for that verification pass. This body is
     * still sent flat, unwrapped, exactly as originally implemented from
     * docs alone. Do not assume this is correct — it must be confirmed
     * against a real (non-money-moving, if possible) call, or accepted as
     * a real risk, before this method is ever used for a real transfer.
     */
    public function createTransfer(array $data, string $idempotencyKey): array
    {
        $response = $this->client()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/transfers', $data);
        $response->throw();

        return $response->json();
    }

    /**
     * GET /v1/transfers/fees — query the fee for a transfer before
     * executing it. ⚠️ UNVERIFIED — not called this session (transfer/
     * payout endpoints are explicitly out of scope for live verification).
     * Exact query parameter names still unconfirmed from docs alone.
     */
    public function getTransferFees(array $params): array
    {
        $response = $this->client()->get('/v1/transfers/fees', $params);
        $response->throw();

        return $response->json();
    }

    /**
     * GET /v1/transactions — list transactions, for the daily
     * reconciliation job. CONFIRMED LIVE — plain query params (no "data"
     * wrapping needed for GET requests, that requirement is POST/PATCH-
     * body-specific). Confirmed response fields include: id,
     * payment_intent_id, amount, currency, status, type (seen live:
     * "payment" — no "transfer"/"payout" type value observed, consistent
     * with this endpoint covering the collection side only), plus
     * refund_count/refunded_amount (present even though no dedicated
     * refund-trigger endpoint was ever found — see PaymentTransaction::
     * recordPendingRefund() docblock).
     */
    public function listTransactions(array $params = []): array
    {
        $response = $this->client()->get('/v1/transactions', $params);
        $response->throw();

        return $response->json();
    }

    /**
     * Verify an inbound webhook's x-modem-signature header.
     *
     * Confirmed verbatim from ModemPay's docs: HMAC-SHA512(webhook_secret,
     * raw_body), hex digest, timing-safe comparison. No timestamp
     * component — unlike some other providers, ModemPay's docs do not
     * describe replay protection at this layer, so WebhookEvent's own
     * dedup table (see that model) is the actual defense against a
     * replayed or duplicated delivery, not this signature check alone.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $webhookSecret = (string) config('services.modempay.webhook_secret');
        $expected = hash_hmac('sha512', $rawBody, $webhookSecret);

        if (strlen($expected) !== strlen($signature)) {
            return false;
        }

        return hash_equals($expected, $signature);
    }
}
