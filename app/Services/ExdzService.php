<?php

namespace App\Services;

use App\Models\CurrencyHistory;
use App\Models\CurrencyRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExdzService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $cacheTtl;

    private bool $useDemoFallback;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('fintrack.exdz.base_url'), '/');
        $apiKey = config('fintrack.exdz.api_key');
        $this->apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
        $this->cacheTtl = (int) config('fintrack.exdz.cache_ttl', 900);
        $this->useDemoFallback = (bool) config('fintrack.exdz.use_demo_fallback', true);
    }

    public function latestRates(array $currencies = ['EUR', 'USD']): array
    {
        $currencies = array_map('strtoupper', $currencies);
        $cacheKey = 'exdz:latest:'.implode(',', $currencies);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($currencies) {
            $pairs = [];
            $provider = $this->apiKey ? 'exdz' : 'demo';

            foreach ($currencies as $currency) {
                $official = $this->latestQuote($currency, 'official');
                $parallel = $this->latestQuote($currency, 'parallel');
                $officialRate = $this->mid($official);
                $parallelRate = $this->mid($parallel);
                $difference = round($parallelRate - $officialRate, 2);
                $premium = $officialRate > 0
                    ? round(($difference / $officialRate) * 100, 2)
                    : 0.0;

                $this->persistLatest($currency, 'official', $official, $officialRate);
                $this->persistLatest($currency, 'parallel', $parallel, $parallelRate);

                $pairs[] = [
                    'base' => $currency,
                    'quote' => 'DZD',
                    'official' => $official,
                    'parallel' => $parallel,
                    'difference' => $difference,
                    'premium_percent' => $premium,
                ];
            }

            return [
                'provider' => $provider,
                'note' => null,
                'updated_at' => now()->toIso8601String(),
                'pairs' => $pairs,
            ];
        });
    }

    public function history(string $currency, string $range = '30d'): array
    {
        $currency = strtoupper($currency);
        $range = strtolower($range);
        [$start, $end] = $this->rangeDates($range);
        $cacheKey = "exdz:history:{$currency}:{$range}";

        return Cache::remember($cacheKey, 3600, function () use ($currency, $range, $start, $end) {
            $official = $this->historyQuotes($currency, 'official', $start, $end);
            $parallel = $this->historyQuotes($currency, 'parallel', $start, $end);

            return [
                'provider' => $this->apiKey ? 'exdz' : 'demo',
                'pair' => $currency.'/DZD',
                'range' => $range,
                'start' => $start,
                'end' => $end,
                'official' => $official,
                'parallel' => $parallel,
            ];
        });
    }

    public function premium(string $currency = 'EUR'): array
    {
        $currency = strtoupper($currency);
        $rates = $this->latestRates([$currency]);
        $pair = $rates['pairs'][0] ?? null;

        if (! $pair) {
            throw new RuntimeException('Prime EXDZ indisponible.');
        }

        $history = $this->history($currency, '90d');
        $premiums = [];
        $officialByDate = [];
        foreach ($history['official'] as $point) {
            $officialByDate[$point['date']] = $point['rate'];
        }
        foreach ($history['parallel'] as $point) {
            $official = $officialByDate[$point['date']] ?? null;
            if (! $official || $official <= 0) {
                continue;
            }
            $premiums[] = (($point['rate'] - $official) / $official) * 100;
        }

        $payload = [
            'provider' => $rates['provider'],
            'currency' => $currency,
            'quote' => 'DZD',
            'difference' => $pair['difference'],
            'premium_percent' => $pair['premium_percent'],
            'average_premium_percent' => $premiums ? round(array_sum($premiums) / count($premiums), 2) : $pair['premium_percent'],
            'min_premium_percent' => $premiums ? round(min($premiums), 2) : $pair['premium_percent'],
            'max_premium_percent' => $premiums ? round(max($premiums), 2) : $pair['premium_percent'],
        ];

        if ($this->apiKey) {
            try {
                $remote = $this->request('/market/premium', ['currency' => $currency]);
                $payload['remote'] = $this->pick($remote, ['data', 'premium', 'result']) ?? $remote;
            } catch (RuntimeException) {
                // Keep the locally computed premium if EXDZ premium is unavailable.
            }
        }

        return $payload;
    }

    private function latestQuote(string $currency, string $source): array
    {
        if ($this->apiKey) {
            $payload = $this->request('/rates/latest', [
                'currency' => $currency,
                'source' => $source,
            ]);
            $row = $this->firstRateRow($payload);

            return $this->formatQuote($row, $source);
        }

        if (! $this->useDemoFallback) {
            throw new RuntimeException(
                'EXDZ_API_KEY manquante. Ajoutez-la dans backend/.env après l’onboarding EXDZ. Aucune fausse API EXDZ n’est exposée.'
            );
        }

        $cached = CurrencyRate::query()
            ->where('base', $currency)
            ->where('quote', 'DZD')
            ->where('market', $source)
            ->whereIn('provider', ['exdz', 'demo'])
            ->latest('fetched_at')
            ->first();

        if ($cached) {
            return [
                'rate' => (float) $cached->rate,
                'buy' => $cached->buy !== null ? (float) $cached->buy : null,
                'sell' => $cached->sell !== null ? (float) $cached->sell : null,
                'updated_at' => optional($cached->fetched_at)?->toIso8601String(),
                'source' => $source,
            ];
        }

        return $this->demoQuote($currency, $source);
    }

    private function historyQuotes(string $currency, string $source, string $start, string $end): array
    {
        if ($this->apiKey) {
            $payload = $this->request('/rates/history', [
                'currency' => $currency,
                'source' => $source,
                'from' => $start,
                'to' => $end,
            ]);

            $points = $this->normalizeHistory($payload);
            foreach ($points as $point) {
                CurrencyHistory::query()->updateOrCreate(
                    [
                        'base' => $currency,
                        'quote' => 'DZD',
                        'provider' => 'exdz',
                        'market' => $source,
                        'rate_date' => $point['date'],
                    ],
                    [
                        'rate' => $point['rate'],
                        'buy' => $point['buy'] ?? null,
                        'sell' => $point['sell'] ?? null,
                    ]
                );
            }

            return array_map(fn (array $point) => [
                'date' => $point['date'],
                'rate' => $point['rate'],
            ], $points);
        }

        if (! $this->useDemoFallback) {
            throw new RuntimeException('EXDZ_API_KEY manquante. Historique EXDZ non disponible.');
        }

        return $this->demoHistory($currency, $source, $start, $end);
    }

    private function request(string $path, array $query = []): array
    {
        if (! $this->apiKey) {
            throw new RuntimeException('EXDZ_API_KEY manquante.');
        }

        try {
            $request = Http::acceptJson()
                ->withToken($this->apiKey)
                ->timeout(15)
                ->retry(2, 400);

            if (app()->environment('local')) {
                $request = $request->withOptions(['verify' => false]);
            }

            $response = $request->get($this->baseUrl.$path, $query)->throw();
        } catch (\Throwable $exception) {
            throw new RuntimeException('EXDZ est indisponible: '.$exception->getMessage(), 0, $exception);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function firstRateRow(array $payload): array
    {
        if (isset($payload['data'][0]) && is_array($payload['data'][0])) {
            return $payload['data'][0];
        }
        if (isset($payload['data']) && is_array($payload['data']) && ! array_is_list($payload['data'])) {
            return $payload['data'];
        }
        if (isset($payload['currency']) || isset($payload['buy']) || isset($payload['rate'])) {
            return $payload;
        }

        return [];
    }

    private function formatQuote(array $row, string $source): array
    {
        $buy = isset($row['buy']) ? (float) $row['buy'] : null;
        $sell = isset($row['sell']) ? (float) $row['sell'] : null;
        $rate = isset($row['rate']) ? (float) $row['rate'] : $this->mid([
            'buy' => $buy,
            'sell' => $sell,
            'rate' => 0,
        ]);

        return [
            'rate' => $rate,
            'buy' => $buy,
            'sell' => $sell,
            'updated_at' => $row['updatedAt'] ?? $row['updated_at'] ?? now()->toIso8601String(),
            'source' => $row['source'] ?? $source,
        ];
    }

    private function normalizeHistory(array $payload): array
    {
        $list = $payload['data'] ?? $payload['rates'] ?? $payload;
        $points = [];

        if (! is_array($list)) {
            return [];
        }

        foreach ($list as $key => $item) {
            if (is_array($item) && (isset($item['date']) || isset($item['buy']) || isset($item['rate']))) {
                $buy = isset($item['buy']) ? (float) $item['buy'] : null;
                $sell = isset($item['sell']) ? (float) $item['sell'] : null;
                $rate = isset($item['rate']) ? (float) $item['rate'] : $this->mid([
                    'buy' => $buy,
                    'sell' => $sell,
                    'rate' => 0,
                ]);
                $points[] = [
                    'date' => $item['date'] ?? $item['rate_date'] ?? (is_string($key) ? $key : null),
                    'rate' => $rate,
                    'buy' => $buy,
                    'sell' => $sell,
                ];
            } elseif (is_numeric($item) && is_string($key)) {
                $points[] = [
                    'date' => $key,
                    'rate' => (float) $item,
                    'buy' => null,
                    'sell' => null,
                ];
            }
        }

        return array_values(array_filter($points, fn (array $point) => ! empty($point['date'])));
    }

    private function persistLatest(string $currency, string $source, array $quote, float $rate): void
    {
        CurrencyRate::query()->updateOrCreate(
            [
                'base' => $currency,
                'quote' => 'DZD',
                'provider' => $this->apiKey ? 'exdz' : 'demo',
                'market' => $source,
            ],
            [
                'rate' => $rate,
                'buy' => $quote['buy'] ?? null,
                'sell' => $quote['sell'] ?? null,
                'fetched_at' => now(),
            ]
        );
    }

    private function demoQuote(string $currency, string $source): array
    {
        $bases = [
            'EUR' => ['official' => 152.00, 'parallel' => 275.50],
            'USD' => ['official' => 138.00, 'parallel' => 236.00],
        ];
        $rate = $bases[$currency][$source] ?? 100.0;
        $spread = $source === 'parallel' ? 1.2 : 0.2;

        return [
            'rate' => $rate,
            'buy' => round($rate - $spread, 2),
            'sell' => round($rate + $spread, 2),
            'updated_at' => now()->toIso8601String(),
            'source' => $source,
        ];
    }

    private function demoHistory(string $currency, string $source, string $start, string $end): array
    {
        $startDate = Carbon::parse($start);
        $endDate = Carbon::parse($end);
        $quote = $this->demoQuote($currency, $source);
        $points = [];
        $seed = $source === 'parallel' ? 2.4 : 0.35;

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $wave = sin($date->dayOfYear / 18) * $seed;
            $rate = round($quote['rate'] + $wave, 2);
            $points[] = [
                'date' => $date->toDateString(),
                'rate' => $rate,
            ];
        }

        return $points;
    }

    private function rangeDates(string $range): array
    {
        $end = now()->toDateString();
        $days = match ($range) {
            '90d' => 90,
            '1y', '365d' => 365,
            default => 30,
        };

        return [now()->subDays($days)->toDateString(), $end];
    }

    private function mid(array $quote): float
    {
        $buy = $quote['buy'] ?? null;
        $sell = $quote['sell'] ?? null;
        if (is_numeric($buy) && is_numeric($sell)) {
            return round(((float) $buy + (float) $sell) / 2, 2);
        }

        return (float) ($quote['rate'] ?? 0);
    }

    private function pick(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }
}
