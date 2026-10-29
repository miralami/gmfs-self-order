<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentGatewayException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        string $message,
        public readonly ?int $gatewayStatus = null,
        public readonly ?array $payload = null,
    ) {
        parent::__construct($message);
    }
}
