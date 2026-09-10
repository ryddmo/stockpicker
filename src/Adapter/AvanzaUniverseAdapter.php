<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\SchemaMismatch;

/**
 * Lists the tradable Swedish universe from Avanza's public stock-screener
 * endpoint. Not a SourceAdapter — its one job is listUniverse(): it does not
 * implement fetch()/resolveId(), does not touch owner counts, and persists
 * nothing (Story 2.2's UniverseSync consumes the returned list).
 *
 * Endpoint is unofficial (`_api/...`) and may change without notice — every
 * response is schema-checked at this boundary (AD-7). A broken shape, a missing
 * consumed field, or any one of the four target lists coming back empty raises
 * SchemaMismatch and returns nothing — never a partial list (a silently missing
 * cap tier would make UniverseSync mass-delist it).
 *
 * Verified 2026-09-10 against the live API:
 *  - POST _api/market-stock-filter/stocks
 *    body {"filter":{"sectors":[],"marketPlaces":["<one value>"]},
 *          "limit":5000,"offset":0,"sortBy":{"field":"numberOfOwners","order":"desc"}}
 *  - response { stocks: [ { orderbookId (string), type: "STOCK", name, … } ], … }
 *    No `isin`, no cap-tier field — the label comes from which query returned the row.
 *  - per-list counts: LC 163, MC 141, SC 107, First North ~330 (~741 total)
 */
final class AvanzaUniverseAdapter
{
    use HandlesTransientHttp;

    private const BASE_URI = 'https://www.avanza.se';
    private const STOCKS_PATH = '/_api/market-stock-filter/stocks';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; stockpicker/1.x; personal use)';

    /** Safety ceiling on a single target-list response. */
    private const LIMIT = 5000;

    /**
     * The four target lists — one `marketPlaces` filter value each → normalized
     * label. `se.xsto.xterna listan`, Spotlight, NGM and foreign lists are not
     * queried.
     */
    private const LISTS = [
        'se.xsto.large cap stockholm' => UniverseEntry::LIST_LC,
        'se.xsto.mid cap stockholm' => UniverseEntry::LIST_MC,
        'se.xsto.small cap stockholm' => UniverseEntry::LIST_SC,
        'se.fnse' => UniverseEntry::LIST_FIRST_NORTH,
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<UniverseEntry> deduped, sorted by avanzaOrderbookId ascending
     *
     * @throws \Stockpicker\Error\Transient      a target-list call failed twice (connect / timeout / 429 / 5xx)
     * @throws SchemaMismatch a broken response shape, an unexpected 4xx, or any empty target list
     */
    public function listUniverse(): array
    {
        /** @var array<string, UniverseEntry> $out keyed by orderbookId string */
        $out = [];

        foreach (self::LISTS as $marketPlace => $label) {
            $before = count($out);

            foreach ($this->queryList($marketPlace, $label) as $raw) {
                if (!is_array($raw) || ($raw['type'] ?? null) !== 'STOCK') {
                    continue;
                }

                $id = $raw['orderbookId'] ?? null;
                if (!is_string($id) && !is_int($id)) {
                    $this->warn('avanza screener: dropping row with non-scalar orderbookId', $label, $raw);

                    continue;
                }
                $id = (string) $id;

                $name = $raw['name'] ?? null;
                if (!is_string($name) || trim($name) === '') {
                    $this->warn('avanza screener: dropping row with blank name', $label, $raw);

                    continue;
                }

                if (isset($out[$id])) {
                    $this->warn(sprintf(
                        'avanza screener: duplicate orderbookId %s across target lists (keeping first: %s)',
                        $id,
                        $out[$id]->list,
                    ), $label, $raw);

                    continue;
                }

                $out[$id] = new UniverseEntry($id, trim($name), $label);
            }

            if (count($out) === $before) {
                throw $this->schemaMismatch(sprintf(
                    'avanza market-stock-filter/stocks (%s): non-empty response with no usable STOCK rows',
                    $label,
                ));
            }
        }

        if ($out === []) {
            throw $this->schemaMismatch('avanza screener: target universe came back empty');
        }

        uksort($out, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        return array_values($out);
    }

    /**
     * One POST for a target list, with a single retry on a transient failure.
     * An empty `stocks` array for a target list raises SchemaMismatch (never
     * "return nothing") so the all-four-lists guard is per-list.
     *
     * @return array<mixed> the raw `stocks` list
     */
    private function queryList(string $marketPlace, string $label): array
    {
        $body = $this->withOneRetry(fn (): array => $this->requestJson(
            $this->http,
            'POST',
            self::BASE_URI . self::STOCKS_PATH,
            [
                // `sortBy` and `limit` are both required (the endpoint 400s without
                // them). The result is re-sorted by orderbookId here; `name asc`
                // is only for a deterministic, easy-to-eyeball page — the
                // truncation guard below makes the ordering irrelevant to
                // correctness.
                'json' => [
                    'filter' => ['sectors' => [], 'marketPlaces' => [$marketPlace]],
                    'limit' => self::LIMIT,
                    'offset' => 0,
                    'sortBy' => ['field' => 'name', 'order' => 'asc'],
                ],
                'headers' => ['User-Agent' => self::USER_AGENT],
            ],
        ));

        $stocks = $body['stocks'] ?? null;
        if (!is_array($stocks)) {
            throw $this->schemaMismatch(sprintf(
                'avanza market-stock-filter/stocks (%s): "stocks" missing or not a list',
                $label,
            ));
        }

        if ($stocks === []) {
            throw $this->schemaMismatch(sprintf(
                'avanza market-stock-filter/stocks (%s): target list came back empty',
                $label,
            ));
        }

        if (count($stocks) >= self::LIMIT) {
            throw $this->schemaMismatch(sprintf(
                'avanza market-stock-filter/stocks (%s): %d rows hit the %d limit — request contract likely changed',
                $label,
                count($stocks),
                self::LIMIT,
            ));
        }

        // The response reports the true match count for the filter; a shorter
        // `stocks` array means the server truncated the page (a silently
        // incomplete cap tier → Story 2.2 would mass-delist the missing rows).
        $total = $body['totalNumberOfOrderbooks'] ?? null;
        if (is_int($total) || (is_string($total) && ctype_digit($total))) {
            if (count($stocks) < (int) $total) {
                throw $this->schemaMismatch(sprintf(
                    'avanza market-stock-filter/stocks (%s): got %d of %d rows — response truncated',
                    $label,
                    count($stocks),
                    (int) $total,
                ));
            }
        }

        return $stocks;
    }

    private function schemaMismatch(string $message): SchemaMismatch
    {
        $this->logger->warning($message);

        return new SchemaMismatch($message);
    }

    /**
     * @param array<mixed> $raw
     */
    private function warn(string $message, string $label, array $raw): void
    {
        $this->logger->warning($message, [
            'list' => $label,
            'orderbookId' => self::preview($raw['orderbookId'] ?? null),
            'name' => self::preview($raw['name'] ?? null),
        ]);
    }

    /**
     * A scalar as-is, otherwise its type name — so a warning about a non-scalar
     * value still records what was actually there instead of a bare null.
     */
    private static function preview(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : get_debug_type($value);
    }
}
