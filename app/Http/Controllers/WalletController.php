<?php

namespace App\Http\Controllers;

use App\Services\LedgerService;
use App\Support\RequestValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validate -> service -> JSON. No SQL here.
 */
final class WalletController
{
    /**
     * @param LedgerService $ledger
     */
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     *  
     * @param Request $request
     * @return JsonResponse
     */
    public function credit(Request $request): JsonResponse
    {
        [$userId, $amount] = RequestValidator::moneyBody($request);
        $key = RequestValidator::idempotencyKey($request);

        return self::balanceResponse($userId, $this->ledger->credit($userId, $amount, $key));
    }

    /**
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function debit(Request $request): JsonResponse
    {
        [$userId, $amount] = RequestValidator::moneyBody($request);
        $key = RequestValidator::idempotencyKey($request);

        return self::balanceResponse($userId, $this->ledger->debit($userId, $amount, $key));
    }

    /**
     *
     * @param string $userId
     * @return JsonResponse
     */
    public function balance(string $userId): JsonResponse
    {
        $userId = RequestValidator::userId($userId);

        return self::balanceResponse($userId, $this->ledger->balance($userId));
    }

    /**
     *
     * @param string $userId
     * @param integer $balance
     * @return JsonResponse
     */
    private static function balanceResponse(string $userId, int $balance): JsonResponse
    {
        return new JsonResponse(['user_id' => $userId, 'balance' => $balance]);
    }
}
