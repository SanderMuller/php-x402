<?php

declare(strict_types=1);

namespace X402\Exceptions;

use Throwable;
use X402\Errors\ErrorReason;

final class InvalidPaymentException extends X402Exception
{
    public function __construct(
        string $message,
        public readonly ?ErrorReason $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function with(ErrorReason $reason, string $message): self
    {
        return new self($message, $reason);
    }
}
