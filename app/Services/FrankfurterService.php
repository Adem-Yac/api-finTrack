<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FrankfurterService
{
    private string $baseUrl;

    private int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('fintrack.frankfurter.base_url'), '/');
        $this->cacheTtl = (int) config('fintrack.frankfurter.cache_ttl', 3600);
    }

    public function currencies(): array
    {
        return Cache::remember('frankfurter:currencies', $this->cacheTtl, function () {
            $payload = $this->get('/currencies');
            $items = [];

            foreach ($payload as $code => $meta) {
                if (! is_string($code)) {
                    continue;
                }

                $items[] = [
                    'code' => strtoupper($code),
                    'name' => is_array($meta) ? ($meta['name'] ?? $code) : (string) $meta,
                ];
            }

            usort($items, fn (array $a, array $b) => $a['code'] <=> $b['code']);

            return $items;
        });
    }

    public function latestRates(string $base = 'EUR', array $quotes = ['USD', 'GBP', 'CHF', 'JPY']): array
    {
        $base = strtoupper($base);
        $quotes = array_map('strtoupper', $quotes);
        $key = 'frankfurter:latest:'.$base.':'.implode(',', $quotes);

        return Cache::remember($key, $this->cacheTtl, function () use ($base, $quotes) {
            $payload = $this->get('/rates', [
                'base' => strtolower($base),
                'quotes' => strtolower(implode(',', $quotes)),
            ]);

            return $this->normalizeRateRows($payload, $base);
        });
    }

    public function convert(string $from, string $to, float $amount): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'rate' => 1.0,
                'result' => $amount,
                'date' => now()->toDateString(),
                'provider' => 'frankfurter',
            ];
        }

        $cacheKey = 'frankfurter:rate:'.$from.':'.$to;
        $pair = Cache::remember($cacheKey, $this->cacheTtl, function () use ($from, $to) {
            return $this->get('/rate/'.strtolower($from).'/'.strtolower($to));
        });

        $rate = (float) ($pair['rate'] ?? 0);

        if ($rate <= 0) {
            throw new RuntimeException('Taux Frankfurter indisponible pour '.$from.'/'.$to.'.');
        }

        return [
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'rate' => $rate,
            'result' => round($amount * $rate, 2),
            'date' => $pair['date'] ?? now()->toDateString(),
            'provider' => 'frankfurter',
        ];
    }

    public function history(string $from, string $to, string $start, string $end): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $key = "frankfurter:history:{$from}:{$to}:{$start}:{$end}";

        return Cache::remember($key, 86400, function () use ($from, $to, $start, $end) {
            $payload = $this->get('/rates', [
                'base' => strtolower($from),
                'quotes' => strtolower($to),
                'from' => $start,
                'to' => $end,
            ]);

            $points = [];
            foreach ($this->normalizeRateRows($payload, $from) as $row) {
                if (($row['quote'] ?? '') !== $to) {
                    continue;
                }
                $points[] = [
                    'date' => $row['date'],
                    'rate' => $row['rate'],
                ];
            }

            usort($points, fn (array $a, array $b) => $a['date'] <=> $b['date']);

            return [
                'from' => $from,
                'to' => $to,
                'start' => $start,
                'end' => $end,
                'points' => $points,
                'provider' => 'frankfurter',
            ];
        });
    }

    private function normalizeRateRows(mixed $payload, string $fallbackBase): array
    {
        $rows = [];

        if (isset($payload['rate'])) {
            $rows[] = [
                'base' => strtoupper((string) ($payload['base'] ?? $fallbackBase)),
                'quote' => strtoupper((string) ($payload['quote'] ?? '')),
                'rate' => (float) $payload['rate'],
                'date' => $payload['date'] ?? now()->toDateString(),
            ];

            return $rows;
        }

        $list = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $list = $payload['data'];
        }

        if (! is_array($list)) {
            return [];
        }

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $rows[] = [
                'base' => strtoupper((string) ($item['base'] ?? $fallbackBase)),
                'quote' => strtoupper((string) ($item['quote'] ?? $item['currency'] ?? '')),
                'rate' => (float) ($item['rate'] ?? 0),
                'date' => $item['date'] ?? now()->toDateString(),
            ];
        }

        return $rows;
    }

    private function get(string $path, array $query = []): array
    {
        try {
            $request = Http::acceptJson()
                ->timeout(15)
                ->retry(2, 250);

            if (app()->environment('local')) {
                $request = $request->withOptions(['verify' => false]);
            }

            $response = $request->get($this->baseUrl.$path, $query)->throw();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Frankfurter est indisponible: '.$exception->getMessage(), 0, $exception);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
