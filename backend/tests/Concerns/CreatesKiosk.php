<?php

namespace Tests\Concerns;

use App\Models\Outlet;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait CreatesKiosk
{
    protected function tenantWithProduct(string $name = 'Tenant One', string $slug = 'tenant-one', int $price = 50000): array
    {
        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);

        $outlet = Outlet::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name.' Outlet',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'name' => $name.' Signature Dish',
            'price' => $price,
            'is_active' => true,
        ]);

        return [$tenant, $outlet, $product];
    }

    /**
     * @return array{user: User, token: string}
     */
    protected function managerFor(Tenant $tenant): array
    {
        $plainToken = Str::random(40);

        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name.' Manager',
            'email' => $tenant->slug.'-manager@example.test',
            'password' => 'password',
            'role' => 'tenant_manager',
            'api_token' => hash('sha256', $plainToken),
        ]);

        return ['user' => $user, 'token' => $plainToken];
    }

    protected function operator(): array
    {
        $plainToken = Str::random(40);

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => 'password',
            'role' => 'operator',
            'api_token' => hash('sha256', $plainToken),
        ]);

        return ['user' => $user, 'token' => $plainToken];
    }

    protected function placeCheckout(array $items, ?string $idempotencyKey = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $idempotencyKey ?? (string) Str::uuid())
            ->postJson('/api/v1/checkouts', ['items' => $items]);
    }

    protected function fakeSuccessfulCharge(): void
    {
        Http::fake([
            '*/v2/charge' => Http::response($this->successfulChargeBody(), 201),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function successfulChargeBody(): array
    {
        return [
            'status_code' => '201',
            'status_message' => 'QRIS transaction is created',
            'transaction_id' => 'tx-'.Str::random(8),
            'order_id' => 'ignored',
            'gross_amount' => '0.00',
            'payment_type' => 'qris',
            'transaction_status' => 'pending',
            'fraud_status' => 'accept',
            'qr_string' => '00020101021226670016COM.GOJEK.WWW0118',
            'expiry_time' => now('Asia/Jakarta')->addMinutes(15)->format('Y-m-d H:i:s'),
            'actions' => [
                [
                    'name' => 'generate-qr-code',
                    'method' => 'GET',
                    'url' => 'https://api.sandbox.midtrans.test/v2/qris/tx-1/qr-code',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationPayload(
        PaymentAttempt $attempt,
        string $transactionStatus = 'settlement',
        string $statusCode = '200',
        ?string $grossAmount = null,
    ): array {
        $grossAmount ??= number_format($attempt->amount, 2, '.', '');

        $payload = [
            'transaction_id' => $attempt->transaction_id ?? 'tx-test',
            'order_id' => $attempt->gateway_order_id,
            'gross_amount' => $grossAmount,
            'payment_type' => 'qris',
            'transaction_status' => $transactionStatus,
            'transaction_time' => now()->format('Y-m-d H:i:s'),
            'status_code' => $statusCode,
            'fraud_status' => $transactionStatus === 'settlement' ? 'accept' : null,
            'status_message' => 'midtrans payment notification',
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$statusCode.$grossAmount.config('services.midtrans.server_key'),
        );

        return $payload;
    }
}
