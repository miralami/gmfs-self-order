<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Midtrans Core API (QRIS only).
 *
 * Facts taken from the official documentation:
 * - Charge: POST /v2/charge with HTTP Basic auth using the server key as username.
 * - Get status: GET /v2/{order_id}/status.
 * - Notification signature: SHA512(order_id + status_code + gross_amount + server_key).
 *
 * @see https://docs.midtrans.com/reference/api-headers
 * @see https://docs.midtrans.com/reference/best-practices-to-handle-notification
 */
class MidtransGateway
{
    public function __construct(
        private readonly string $serverKey,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 15,
        private readonly ?string $caBundle = null,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->serverKey) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function chargeQris(string $orderId, int $amount): array
    {
        try {
            $response = $this->client()
                ->asJson()
                ->post($this->url('/v2/charge'), [
                    'payment_type' => 'qris',
                    'transaction_details' => [
                        'order_id' => $orderId,
                        'gross_amount' => $amount,
                    ],
                    'qris' => [
                        'acquirer' => 'gopay',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException('Could not reach Midtrans: '.$exception->getMessage());
        }

        return $this->decode($response, 'charge');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $orderId): array
    {
        try {
            $response = $this->client()
                ->get($this->url('/v2/'.rawurlencode($orderId).'/status'));
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException('Could not reach Midtrans: '.$exception->getMessage());
        }

        return $this->decode($response, 'status');
    }

    public function expire(string $orderId): void
    {
        try {
            $this->client()->post($this->url('/v2/'.rawurlencode($orderId).'/expire'));
        } catch (Throwable) {
            // Expiring the previous attempt is best effort. The first confirmed
            // payment still wins, and a later duplicate is flagged as late payment.
        }
    }

    public function signatureKey(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);
    }

    /**
     * The charge response carries the QR payload in `qr_string` and/or an
     * action URL that renders the QR image. Accept both shapes.
     *
     * @param  array<string, mixed>  $response
     */
    public function qrImageUrl(array $response): ?string
    {
        foreach ($response['actions'] ?? [] as $action) {
            if (($action['name'] ?? null) === 'generate-qr-code' && isset($action['url'])) {
                return (string) $action['url'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function expiryFromResponse(array $response): ?Carbon
    {
        $expiry = $response['expiry_time'] ?? null;

        if (! is_string($expiry) || $expiry === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $expiry, 'Asia/Jakarta');
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): PendingRequest
    {
        $client = Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()
            ->timeout($this->timeoutSeconds);

        if ($this->caBundle !== null && $this->caBundle !== '' && is_file($this->caBundle)) {
            $client = $client->withOptions(['verify' => $this->caBundle]);
        }

        return $client;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $operation): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            $payload = [];
        }

        // Midtrans returns 200/201 on success and 4xx with status_code/messages on failure.
        if ($response->failed()) {
            $message = $payload['status_message']
                ?? $payload['error_messages'][0]
                ?? 'Midtrans rejected the '.$operation.' request.';

            throw new PaymentGatewayException((string) $message, $response->status(), $payload);
        }

        return $payload;
    }
}
