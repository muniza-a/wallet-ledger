<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;
use JsonException;

/**
 * Hand-written validation. Laravel's built-in "integer" rule accepts the string "100",
 * and its JSON parsing silently turns malformed JSON into an empty array; the brief
 * requires both to be rejected, so we parse and check types ourselves.
 */
final class RequestValidator
{
    public const MAX_AMOUNT = 1_000_000_000;

    /** @return array{0: string, 1: int} [user_id, amount] */
    public static function moneyBody(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw ApiException::invalidRequest('Body must be valid JSON.');
        }

        // Must be a JSON object. "{}" decodes to [] and is reported as missing fields below.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::invalidRequest('Body must be a JSON object.');
        }

        if (!array_key_exists('user_id', $data)) {
            throw ApiException::invalidRequest('user_id is required.');
        }
        $userId = self::userId($data['user_id']);

        if (!array_key_exists('amount', $data)) {
            throw ApiException::invalidRequest('amount is required.');
        }
        $amount = $data['amount'];

        // json_decode yields int only for plain JSON integers: 10.0, 1e3 -> float,
        // "100" -> string, true -> bool, huge numbers -> string (JSON_BIGINT_AS_STRING).
        if (!is_int($amount) || $amount < 1 || $amount > self::MAX_AMOUNT) {
            throw ApiException::invalidRequest('amount must be an integer from 1 to 1000000000.');
        }

        return [$userId, $amount];
    }

    /**
     *
     * @param mixed $value
     * @return string
     */
    public static function userId(mixed $value): string
    {
        // \A and \z, not ^ and $: "$" also matches before a trailing newline.
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) !== 1) {
            throw ApiException::invalidRequest('user_id must be 1-64 characters of A-Z, a-z, 0-9, _ or -.');
        }

        return $value;
    }

    /**
     *
     * @param Request $request
     * @return string|null
     */
    public static function idempotencyKey(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key');
        if ($key === null) {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $key) !== 1) {
            throw ApiException::invalidRequest('Idempotency-Key must be 1-128 characters of A-Z, a-z, 0-9, _ . : or -.');
        }

        return $key;
    }
}
