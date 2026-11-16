<?php

namespace App\Delivery;

final readonly class DeliveryResult
{
    private function __construct(
        public bool $successful,
        public bool $retryable,
        public ?int $statusCode,
        public ?string $messageId,
        public ?string $error,
        public ?int $retryAfter,
        public int $durationMs,
    ) {}

    /** Errors are stored in VARCHAR(255) columns; a longer one would make the insert fail. */
    private const MAX_ERROR_LENGTH = 255;

    public static function accepted(int $statusCode, ?string $messageId, int $durationMs): self
    {
        return new self(true, false, $statusCode, $messageId === null ? null : mb_substr($messageId, 0, 255), null, null, $durationMs);
    }

    /**
     * Temporary problem on the provider side (timeouts, 5xx, 429). Worth trying again later.
     */
    public static function retryable(?int $statusCode, string $error, int $durationMs, ?int $retryAfter = null): self
    {
        return new self(false, true, $statusCode, null, self::shorten($error), $retryAfter, $durationMs);
    }

    /**
     * The provider rejected the request itself (4xx). Sending it again would give the same answer.
     */
    public static function rejected(int $statusCode, string $error, int $durationMs): self
    {
        return new self(false, false, $statusCode, null, self::shorten($error), null, $durationMs);
    }

    private static function shorten(string $error): string
    {
        return mb_strlen($error) <= self::MAX_ERROR_LENGTH ? $error : mb_substr($error, 0, self::MAX_ERROR_LENGTH - 3).'...';
    }
}
