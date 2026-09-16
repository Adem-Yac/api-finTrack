<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FrankfurterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class CurrencyController extends Controller
{
    public function __construct(private readonly FrankfurterService $frankfurter) {}

    public function index(Request $request): JsonResponse
    {
        $base = strtoupper($request->string('base', 'EUR')->toString());
        $quotes = $request->filled('quotes')
            ? explode(',', strtoupper($request->string('quotes')->toString()))
            : ['USD', 'GBP', 'CHF', 'JPY'];

        try {
            return response()->json([
                'data' => [
                    'currencies' => $this->frankfurter->currencies(),
                    'rates' => $this->frankfurter->latestRates($base, $quotes),
                    'provider' => 'frankfurter',
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            return response()->json([
                'data' => $this->frankfurter->convert($data['from'], $data['to'], (float) $data['amount']),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $end = $data['end'] ?? now()->toDateString();
        $start = $data['start'] ?? Carbon::parse($end)->subDays(30)->toDateString();

        try {
            return response()->json([
                'data' => $this->frankfurter->history($data['from'], $data['to'], $start, $end),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }
}
