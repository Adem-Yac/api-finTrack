<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ExdzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AlgeriaController extends Controller
{
    public function __construct(private readonly ExdzService $exdz) {}

    public function rates(Request $request): JsonResponse
    {
        $currencies = $request->filled('currencies')
            ? explode(',', strtoupper($request->string('currencies')->toString()))
            : ['EUR', 'USD'];

        try {
            return response()->json(['data' => $this->exdz->latestRates($currencies)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'range' => ['nullable', 'in:30d,90d,1y,365d'],
        ]);

        try {
            return response()->json([
                'data' => $this->exdz->history($data['currency'] ?? 'EUR', $data['range'] ?? '30d'),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function premium(Request $request): JsonResponse
    {
        $currency = strtoupper($request->string('currency', 'EUR')->toString());

        try {
            return response()->json(['data' => $this->exdz->premium($currency)]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
