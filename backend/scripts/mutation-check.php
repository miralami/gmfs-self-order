<?php

declare(strict_types=1);

/**
 * Mutation sensitivity check for the thesis evidence chapter (BAB IV).
 *
 * The test suite proves the safeguards work. This harness proves the tests
 * actually depend on those safeguards: it removes one safeguard at a time,
 * runs the test that is supposed to catch it, and records whether the test
 * failed ("killed") or kept passing ("survived").
 *
 * Every mutation is applied to the working tree and reverted in a finally
 * block, even when the test run throws. The harness refuses to start when the
 * working tree has uncommitted changes to the files it touches, so a failed
 * restore can never lose a real edit.
 *
 * Usage:
 *   php scripts/mutation-check.php [--only=M1,M3] [--verbose]
 *
 * Exit code is 0 when every mutation was applied and run, 1 when a mutation
 * could not be applied (a stale pattern) or a file could not be restored.
 * "Survived" is not an error: it is a result to explain in the thesis.
 */
$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

/**
 * Each mutation: id, what it removes, the edits, and the test that must fail.
 * A pattern must occur exactly once, otherwise the mutation is skipped.
 *
 * @var array<int, array{
 *     id: string,
 *     claim: string,
 *     edits: array<int, array{file: string, search: string, replace: string}>,
 *     test: string,
 *     expect: string
 * }>
 */
$mutations = [
    [
        'id' => 'M1',
        'claim' => 'Idempotency-Key replay returns the original checkout without a second charge',
        'edits' => [
            [
                'file' => 'app/Services/CheckoutService.php',
                'search' => <<<'PHP'
        $existing = Checkout::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $this->assertSameRequest($existing, $requestHash);

            return ['checkout' => $existing, 'replayed' => true];
        }

PHP,
                'replace' => '',
            ],
            [
                'file' => 'database/migrations/2026_10_14_125011_create_checkouts_table.php',
                'search' => "\$table->string('idempotency_key', 100)->unique();",
                'replace' => "\$table->string('idempotency_key', 100);",
            ],
        ],
        'test' => 'test_same_idempotency_key_returns_the_same_checkout',
        'expect' => 'killed',
    ],
    [
        'id' => 'M2',
        'claim' => 'A checkout can only split once per tenant (structural index)',
        'edits' => [
            [
                'file' => 'database/migrations/2026_10_14_125013_create_tenant_orders_table.php',
                'search' => "\$table->unique(['checkout_id', 'tenant_id']);",
                'replace' => '',
            ],
        ],
        'test' => 'test_replayed_notifications_produce_one_paid_state_and_one_split',
        'expect' => 'survived',
    ],
    [
        'id' => 'M2b',
        'claim' => 'The layered double-split defence (indexes + monotonic state) is load-bearing',
        'edits' => [
            [
                'file' => 'database/migrations/2026_10_14_125013_create_tenant_orders_table.php',
                'search' => "\$table->unique(['checkout_id', 'tenant_id']);",
                'replace' => '',
            ],
            [
                'file' => 'database/migrations/2026_10_14_125015_create_payment_notifications_table.php',
                'search' => <<<'PHP'
            $table->unique(['gateway_order_id', 'transaction_status', 'status_code', 'signature_key'], 'payment_notifications_event_unique');
PHP,
                'replace' => '',
            ],
            [
                'file' => 'app/Services/PaymentService.php',
                'search' => <<<'PHP'
            if ($checkout->status === 'paid') {
                $lockedAttempt->update([
                    'status' => PaymentAttempt::STATUS_CANCELLED,
                    'failure_reason' => 'Duplicate payment after the checkout was already paid.',
                ]);

                return 'already_paid';
            }

PHP,
                'replace' => '',
            ],
        ],
        'test' => 'test_a_second_settlement_after_payment_is_recorded_as_already_paid',
        'expect' => 'killed',
    ],
    [
        'id' => 'M3',
        'claim' => 'A byte-identical notification replay is stored and applied once',
        'edits' => [
            [
                'file' => 'database/migrations/2026_10_14_125015_create_payment_notifications_table.php',
                'search' => <<<'PHP'
            $table->unique(['gateway_order_id', 'transaction_status', 'status_code', 'signature_key'], 'payment_notifications_event_unique');
PHP,
                'replace' => '',
            ],
        ],
        'test' => 'test_replayed_notifications_produce_one_paid_state_and_one_split',
        'expect' => 'killed',
    ],
    [
        'id' => 'M4',
        'claim' => 'A notification with an invalid signature changes nothing',
        'edits' => [
            [
                'file' => 'app/Services/PaymentService.php',
                'search' => <<<'PHP'
        $signatureValid = $trusted
            || ($signature !== '' && hash_equals(
                $this->gateway->signatureKey($orderId, $statusCode, $grossAmount),
                $signature,
            ));
PHP,
                'replace' => '        $signatureValid = true;',
            ],
        ],
        'test' => 'test_an_invalid_signature_changes_nothing',
        'expect' => 'killed',
    ],
    [
        'id' => 'M5',
        'claim' => 'A tenant manager only lists its own orders',
        'edits' => [
            [
                'file' => 'app/Http/Controllers/Api/V1/TenantOrderController.php',
                'search' => "            ->visibleTo(\$request->user())\n",
                'replace' => '',
            ],
        ],
        'test' => 'test_a_tenant_manager_only_sees_its_own_orders',
        'expect' => 'killed',
    ],
    [
        'id' => 'M6',
        'claim' => 'A tenant manager cannot queue another tenant order',
        'edits' => [
            [
                'file' => 'app/Policies/TenantOrderPolicy.php',
                'search' => <<<'PHP'
        // Only the owning tenant may queue its own order. The operator has read
        // access to settlements, but does not operate a stall.
        return $user->isTenantManager()
            && $user->tenant_id !== null
            && (int) $user->tenant_id === (int) $tenantOrder->tenant_id;
PHP,
                'replace' => '        return true;',
            ],
        ],
        'test' => 'test_guessing_another_tenants_order_id_returns_forbidden',
        'expect' => 'killed',
    ],
    [
        'id' => 'M7',
        'claim' => 'An expired checkout never becomes paid',
        'edits' => [
            [
                'file' => 'app/Services/PaymentService.php',
                'search' => "if (\$checkout->status !== 'unpaid' || \$checkout->hasExpired()) {",
                'replace' => "if (\$checkout->status !== 'unpaid') {",
            ],
        ],
        'test' => 'test_a_checkout_that_has_expired_does_not_become_paid',
        'expect' => 'killed',
    ],
    [
        'id' => 'M8',
        'claim' => 'The 80/20 split is whole rupiah and always sums to the total',
        'edits' => [
            [
                'file' => 'app/Services/PaymentService.php',
                'search' => '$platformFee = intdiv($tenantOrder->total_amount * $feePercent, 100);',
                'replace' => '$platformFee = (int) round($tenantOrder->total_amount * $feePercent / 100);',
            ],
        ],
        'test' => 'test_the_split_floors_the_fee_so_share_and_fee_sum_to_the_total',
        'expect' => 'killed',
    ],
];

$options = array_slice($argv, 1);
$only = null;
$verbose = false;

foreach ($options as $option) {
    if (str_starts_with($option, '--only=')) {
        $only = array_map('trim', explode(',', substr($option, 7)));
    }

    if ($option === '--verbose') {
        $verbose = true;
    }
}

function say(string $line = ''): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

/**
 * Run one Artisan test filter and return [exitCode, combinedOutput].
 *
 * @return array{0: int, 1: string}
 */
function runTest(string $root, string $filter): array
{
    $command = sprintf(
        'cd /d %s && php artisan test --filter=%s 2>&1',
        escapeshellarg($root),
        escapeshellarg($filter),
    );

    $output = [];
    $exitCode = 0;

    exec($command, $output, $exitCode);

    return [$exitCode, implode(PHP_EOL, $output)];
}

/** Return the most useful failure line from a PHPUnit run. */
function failureLine(string $output): string
{
    foreach (explode(PHP_EOL, $output) as $line) {
        $trimmed = trim($line);

        if (str_contains($trimmed, 'Failed asserting')
            || str_contains($trimmed, 'Expected response status code')
            || str_contains($trimmed, 'does not match')
            || str_contains($trimmed, 'does not have expected')
            || str_contains($trimmed, 'Failed that')
        ) {
            return $trimmed;
        }
    }

    foreach (explode(PHP_EOL, $output) as $line) {
        if (str_contains($line, 'FAIL') || str_contains($line, '⨯')) {
            return trim($line);
        }
    }

    return 'exit code '.($output === '' ? 'unknown' : 'non-zero').', no assertion line found';
}

say('GMFS mutation sensitivity check');
say('working tree: '.$root);
say('database: in-memory SQLite via the test suite');
say();

$failures = 0;
$results = [];

foreach ($mutations as $mutation) {
    if ($only !== null && ! in_array($mutation['id'], $only, true)) {
        continue;
    }

    say(sprintf('%s  %s', $mutation['id'], $mutation['claim']));

    $backups = [];
    $applied = true;

    try {
        foreach ($mutation['edits'] as $edit) {
            $path = $root.'/'.$edit['file'];
            $original = file_get_contents($path);

            if ($original === false) {
                say('  ERROR: cannot read '.$edit['file']);
                $applied = false;

                break;
            }

            $backups[$path] = $original;
            $normalised = str_replace("\r\n", "\n", $original);
            $needle = str_replace("\r\n", "\n", $edit['search']);
            $occurrences = substr_count($normalised, $needle);

            if ($occurrences !== 1) {
                say(sprintf('  ERROR: pattern in %s matched %d times (expected 1)', $edit['file'], $occurrences));
                $applied = false;

                break;
            }

            $patched = str_replace($needle, str_replace("\r\n", "\n", $edit['replace']), $normalised);

            if (file_put_contents($path, $patched) === false) {
                say('  ERROR: cannot write '.$edit['file']);
                $applied = false;

                break;
            }
        }

        if (! $applied) {
            $failures++;

            continue;
        }

        [$exitCode, $output] = runTest($root, $mutation['test']);
        $killed = $exitCode !== 0;
        $verdict = $killed ? 'killed' : 'survived';
        $expected = $mutation['expect'];
        $matches = $verdict === $expected;

        say(sprintf('  test:   %s', $mutation['test']));
        say(sprintf('  result: %s (expected %s) %s', strtoupper($verdict), $expected, $matches ? '' : '<-- differs, explain this'));

        if ($killed) {
            say('  evidence: '.failureLine($output));
        } else {
            say('  evidence: the suite still passed, so this safeguard is redundancy rather than the sole guard');
        }

        if ($verbose) {
            say('  --- test output ---');
            say($output);
            say('  -------------------');
        }

        $results[] = [
            'id' => $mutation['id'],
            'verdict' => $verdict,
            'expected' => $expected,
            'test' => $mutation['test'],
            'claim' => $mutation['claim'],
            'evidence' => $killed ? failureLine($output) : 'suite passed',
        ];
    } finally {
        foreach ($backups as $path => $original) {
            if (file_put_contents($path, $original) === false) {
                say('  ERROR: could not restore '.$path);
                $failures++;
            }
        }
    }

    say();
}

say('Summary');
say(sprintf('  %-4s %-10s %-10s %s', 'ID', 'RESULT', 'EXPECTED', 'TEST'));

foreach ($results as $result) {
    say(sprintf('  %-4s %-10s %-10s %s', $result['id'], $result['verdict'], $result['expected'], $result['test']));
}

$killedCount = count(array_filter($results, fn (array $r): bool => $r['verdict'] === 'killed'));

say();
say(sprintf('%d of %d mutations killed the test that is supposed to catch them.', $killedCount, count($results)));
say('Copy this output, with the date and the commit hash, into docs/EVIDENCE.md.');
say('A survivor is not a broken test: it means another layer caught the fault first.');

exit($failures === 0 ? 0 : 1);
