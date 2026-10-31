<?php

declare(strict_types=1);

/**
 * Concurrency and replay harness for the thesis evidence chapter (T-CHK / T-PAY).
 *
 * It deliberately attacks the running HTTP API, not the service classes, so the
 * evidence covers routing, middleware, the database constraints and the state
 * machine together.
 *
 * Usage:
 *   php scripts/break-it.php [base-url[,base-url...]] [parallel] [productIdA] [productIdB]
 *
 * Example:
 *   php scripts/break-it.php http://127.0.0.1:8000 50
 *   php scripts/break-it.php http://127.0.0.1:8000,http://127.0.0.1:8001 100
 *   php scripts/break-it.php http://127.0.0.1:8000 100 1 4
 *
 * Pass several base URLs to spread the parallel requests over several PHP
 * processes. One `php artisan serve` on Windows handles one request at a time,
 * so a single URL serialises the requests and proves nothing about concurrency.
 *
 * Requirements:
 *   - The API must be running and reachable.
 *   - PostgreSQL is required for a meaningful result. SQLite serialises writers
 *     and will report "database is locked" instead of proving anything.
 *   - If MIDTRANS_SERVER_KEY is empty, the harness creates the demo payment
 *     attempt itself (same shape as `payments:demo-charge`). Notifications are
 *     still signed and sent over HTTP to the real endpoint.
 */

use App\Models\Checkout;
use App\Models\PaymentAttempt;
use App\Models\PaymentNotification;
use App\Models\Product;
use App\Models\TenantOrder;
use App\Services\MidtransGateway;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrls = array_values(array_filter(array_map(
    static fn (string $url): string => rtrim(trim($url), '/'),
    explode(',', $argv[1] ?? 'http://127.0.0.1:8000'),
)));

if ($baseUrls === []) {
    $baseUrls = ['http://127.0.0.1:8000'];
}

$parallel = max(2, (int) ($argv[2] ?? 50));
$productIds = array_values(array_filter(array_map('intval', array_slice($argv, 3))));

$failures = 0;

function line(string $text = ''): void
{
    fwrite(STDOUT, $text.PHP_EOL);
}

/**
 * Pick the base URL for a request index so the load is spread over every
 * server process.
 */
function baseUrlFor(int $index, array $baseUrls): string
{
    return $baseUrls[$index % count($baseUrls)];
}

function check(string $label, bool $passed, string $detail = ''): void
{
    global $failures;

    if (! $passed) {
        $failures++;
    }

    line(sprintf('[%s] %s%s', $passed ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' — '.$detail : ''));
}

/**
 * Fire a set of HTTP requests in parallel.
 *
 * @param  array<int, array{url: string, method?: string, headers?: array<int, string>, body?: string}>  $requests
 * @return array<int, array{status: int, body: string, error: string}>
 */
function parallelRequests(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $request) {
        $handle = curl_init($request['url']);
        $headers = $request['headers'] ?? ['Accept: application/json'];

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $request['method'] ?? 'GET',
        ]);

        if (isset($request['body'])) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']);
        }

        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $index => $handle) {
        $results[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => (string) curl_multi_getcontent($handle),
            'error' => curl_error($handle),
        ];

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $results;
}

function jsonBody(string $body): array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
}

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------

line('GMFS break-it harness');
line('targets: '.implode(', ', $baseUrls).'   parallel: '.$parallel.'   db: '.config('database.default'));
line();

if (config('database.default') !== 'pgsql') {
    line('WARNING: the database is not PostgreSQL. Parallel writes may fail with');
    line('         "database is locked" and the result is not valid thesis evidence.');
    line();
}

if (count($baseUrls) === 1) {
    line('WARNING: one target only. On Windows `php artisan serve` handles one request at a');
    line('         time, so the requests are serialised. Start several ports and pass a');
    line('         comma-separated list for real concurrency.');
    line();
}

if ($productIds === []) {
    $picked = Product::query()
        ->where('is_active', true)
        ->orderBy('tenant_id')
        ->get()
        ->unique('tenant_id')
        ->take(2)
        ->values();

    if ($picked->count() < 2) {
        line('ERROR: need at least two active products from two tenants. Run the seeder.');

        exit(1);
    }

    $productIds = $picked->pluck('id')->all();
}

$products = Product::query()
    ->whereIn('id', $productIds)
    ->where('is_active', true)
    ->get();

if ($products->count() !== count($productIds)) {
    line('ERROR: one or more product ids are missing or inactive: '.implode(', ', $productIds));

    exit(1);
}

line('cart: '.$products->map(fn (Product $p): string => $p->id.' x1 ('.$p->name.')')->implode(', '));
line();

// ---------------------------------------------------------------------------
// Phase 1 — the same Idempotency-Key fired in parallel
// ---------------------------------------------------------------------------

$idempotencyKey = 'break-it-'.bin2hex(random_bytes(12));
$items = $products->map(fn (Product $p): array => ['product_id' => $p->id, 'quantity' => 1])->values()->all();
$payload = json_encode(['items' => $items], JSON_THROW_ON_ERROR);

line('Phase 1: '.$parallel.' identical POST /checkouts with one Idempotency-Key');

$requests = [];

for ($index = 0; $index < $parallel; $index++) {
    $requests[] = [
        'url' => baseUrlFor($index, $baseUrls).'/api/v1/checkouts',
        'method' => 'POST',
        'headers' => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Idempotency-Key: '.$idempotencyKey,
        ],
        'body' => $payload,
    ];
}

$started = microtime(true);
$responses = parallelRequests($requests);
$elapsedMs = (int) round((microtime(true) - $started) * 1000);

$statusCounts = [];
$references = [];
$errors = 0;

foreach ($responses as $response) {
    $statusCounts[$response['status']] = ($statusCounts[$response['status']] ?? 0) + 1;

    if ($response['status'] >= 500 || $response['status'] === 0) {
        $errors++;

        continue;
    }

    $reference = jsonBody($response['body'])['data']['reference'] ?? null;

    if (is_string($reference)) {
        $references[$reference] = true;
    }
}

ksort($statusCounts);
line('  statuses: '.json_encode($statusCounts).'   wall time: '.$elapsedMs.' ms');
line('  distinct checkout references: '.count($references));

check('all responses are 200/201', $errors === 0, $errors.' server error(s)');
check('exactly one checkout reference', count($references) === 1);

$checkout = Checkout::query()->where('idempotency_key', $idempotencyKey)->first();

check('exactly one checkout row', $checkout !== null && Checkout::query()->where('idempotency_key', $idempotencyKey)->count() === 1);

if ($checkout === null) {
    line();
    line('The checkout was not created; the remaining phases cannot run.');

    exit(1);
}

$expectedOrders = $products->pluck('tenant_id')->unique()->count();

line();
line('Phase 2: the same key with a different cart must be rejected');

$different = json_encode(['items' => [['product_id' => $productIds[0], 'quantity' => 2]]], JSON_THROW_ON_ERROR);
$conflict = parallelRequests([[
    'url' => baseUrlFor(0, $baseUrls).'/api/v1/checkouts',
    'method' => 'POST',
    'headers' => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Idempotency-Key: '.$idempotencyKey,
    ],
    'body' => $different,
]]);

check('different cart returns 409', $conflict[0]['status'] === 409, 'got '.$conflict[0]['status']);

// ---------------------------------------------------------------------------
// Phase 3 — signed notifications fired in parallel
// ---------------------------------------------------------------------------

line();
line('Phase 3: '.$parallel.' parallel replays of one signed settlement notification');

/** @var MidtransGateway $gateway */
$gateway = $app->make(MidtransGateway::class);
$serverKey = (string) config('services.midtrans.server_key');

$attempt = $checkout->paymentAttempts()->latest('attempt_no')->first();

if ($attempt === null) {
    // Offline mode: same shape as `payments:demo-charge`, no gateway call.
    $attempt = $checkout->paymentAttempts()->create([
        'attempt_no' => 1,
        'gateway' => 'midtrans',
        'payment_method' => 'qris',
        'gateway_order_id' => 'GMFS-'.str_replace('-', '', $checkout->reference).'-1',
        'status' => PaymentAttempt::STATUS_PENDING,
        'amount' => $checkout->total_amount,
        'qr_string' => 'DEMO-'.$checkout->reference,
        'expires_at' => $checkout->expires_at,
    ]);

    line('  no gateway attempt found; created an offline demo attempt');
}

$grossAmount = number_format($attempt->amount, 2, '.', '');
$notification = [
    'transaction_id' => 'break-it-'.$attempt->id,
    'order_id' => $attempt->gateway_order_id,
    'gross_amount' => $grossAmount,
    'payment_type' => 'qris',
    'transaction_status' => 'settlement',
    'transaction_time' => now()->format('Y-m-d H:i:s'),
    'status_code' => '200',
    'fraud_status' => 'accept',
    'status_message' => 'break-it harness',
];
$notification['signature_key'] = $gateway->signatureKey($attempt->gateway_order_id, '200', $grossAmount);
$notificationBody = json_encode($notification, JSON_THROW_ON_ERROR);

$responses = parallelRequests(array_map(
    static fn (int $index): array => [
        'url' => baseUrlFor($index, $baseUrls).'/api/v1/payments/midtrans/notification',
        'method' => 'POST',
        'headers' => ['Accept: application/json', 'Content-Type: application/json'],
        'body' => $notificationBody,
    ],
    range(0, $parallel - 1),
));

$outcomes = [];
$errors = 0;

foreach ($responses as $response) {
    if ($response['status'] !== 200) {
        $errors++;

        continue;
    }

    $outcome = jsonBody($response['body'])['outcome'] ?? 'unknown';
    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
}

ksort($outcomes);
line('  outcomes: '.json_encode($outcomes));

check('all notification replies are 200', $errors === 0, $errors.' non-200 reply/replies');
check('exactly one delivery produced the paid state', ($outcomes['paid'] ?? 0) === 1);

$notificationRows = PaymentNotification::query()
    ->where('gateway_order_id', $attempt->gateway_order_id)
    ->count();

$checkout->refresh()->load('tenantOrders');

$childOrders = $checkout->tenantOrders;
$shareSum = (int) $childOrders->sum('tenant_share_amount');
$feeSum = (int) $childOrders->sum('platform_fee_amount');

check('one notification row for the gateway event', $notificationRows === 1, $notificationRows.' row(s)');
check('checkout is paid', $checkout->status === 'paid', $checkout->status);
check('one child order per tenant', $childOrders->count() === $expectedOrders, $childOrders->count().' of '.$expectedOrders);
check('every child order is paid', $childOrders->every(fn ($o): bool => $o->status === TenantOrder::STATUS_PAID));
check('no child order total is zero', $childOrders->every(fn ($o): bool => $o->total_amount > 0));
check('split sums back to the checkout total', $shareSum + $feeSum === $checkout->total_amount, $shareSum.' + '.$feeSum.' vs '.$checkout->total_amount);
check('each child order splits exactly', $childOrders->every(
    fn ($o): bool => $o->tenant_share_amount + $o->platform_fee_amount === $o->total_amount,
));
check('tenant orders sum to the checkout total', (int) $childOrders->sum('total_amount') === $checkout->total_amount);

line();
line('Summary');
line('  checkout: '.$checkout->reference.'  total: '.$checkout->total_amount.'  status: '.$checkout->status);
line('  gross: '.$checkout->total_amount.'  tenant share: '.$shareSum.'  platform fee: '.$feeSum);

foreach ($childOrders as $order) {
    line(sprintf(
        '  %-16s tenant %-3d total %-8d share %-8d fee %-8d %s',
        $order->public_code,
        $order->tenant_id,
        $order->total_amount,
        $order->tenant_share_amount,
        $order->platform_fee_amount,
        $order->status,
    ));
}

line();
line($failures === 0 ? 'RESULT: PASS — every invariant held under parallel load.' : 'RESULT: FAIL — '.$failures.' check(s) failed.');
line('Record this output, the database driver, the parallel count and the date in docs/EVIDENCE.md.');

exit($failures === 0 ? 0 : 1);
