<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An expected API error with a fixed HTTP status and error code.
 * Rendered as {"error": "<code>", "message": "<text>"}.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', 'Missing or invalid bearer token.');
    }

    public static function userNotFound(): self
    {
        return new self(404, 'user_not_found', 'User has never been credited.');
    }

    public static function insufficientFunds(): self
    {
        return new self(409, 'insufficient_funds', 'Balance is lower than the debit amount.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(422, 'idempotency_key_reused', 'Idempotency-Key was already used with a different request.');
    }

    public function render(): JsonResponse
    {
        return self::json($this->status, $this->errorCode, $this->getMessage());
    }

    /** Expected errors are not logged. */
    public function report(): void
    {
    }

    public static function json(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message], $status);
    }
}
