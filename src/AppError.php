<?php
declare(strict_types=1);

namespace GPC;

/**
 * An error that is safe to show to the person using the app.
 * $field points the form at the input that needs attention.
 */
class AppError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly ?string $field = null,
        public readonly ?string $errorCode = null,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message, array $extra = []): self
    {
        return new self($message, 409, null, 'conflict', $extra);
    }
}
