<?php

declare(strict_types=1);

namespace Stockpicker\Store;

/**
 * The hardcoded ~20-ISIN development universe for Epic 1 and the idempotent
 * routine that loads it. bin/seed-instruments.php is a thin wrapper around
 * this. Epic 2's Börsdata UniverseSync replaces the list entirely.
 *
 * ISINs are best-effort (Nasdaq Stockholm Large Cap) and are confirmed against
 * live Avanza/Nordnet responses in Stories 1.4/1.5, where a wrong ISIN shows
 * up as NotFound.
 */
final class InstrumentSeeder
{
    /** @var list<array{0: string, 1: string, 2: string}> [isin, name, list] */
    public const LIST = [
        ['SE0015811963', 'Investor B', 'LC'],
        ['SE0000115446', 'Volvo B', 'LC'],
        ['SE0017486889', 'Atlas Copco A', 'LC'],
        ['SE0000108656', 'Ericsson B', 'LC'],
        ['SE0015961909', 'Hexagon B', 'LC'],
        ['SE0007100581', 'Assa Abloy B', 'LC'],
        ['SE0000148884', 'SEB A', 'LC'],
        ['SE0007100599', 'Svenska Handelsbanken A', 'LC'],
        ['SE0000242455', 'Swedbank A', 'LC'],
        ['FI4000297767', 'Nordea Bank Abp', 'LC'],
        ['SE0000667891', 'Sandvik', 'LC'],
        ['SE0009922164', 'Essity B', 'LC'],
        ['SE0012853455', 'EQT', 'LC'],
        ['SE0012673267', 'Evolution', 'LC'],
        ['SE0020050417', 'Boliden', 'LC'],
        ['SE0000695876', 'Alfa Laval', 'LC'],
        ['SE0011166933', 'Epiroc A', 'LC'],
        ['SE0000667925', 'Telia Company', 'LC'],
        ['SE0000108227', 'SKF B', 'LC'],
        ['SE0000202624', 'Getinge B', 'LC'],
    ];

    /**
     * Upsert every seed row. Idempotent — a second run inserts nothing and
     * preserves resolved ids and first_seen.
     *
     * @return array{inserted: int, unchanged: int}
     */
    public static function seed(InstrumentRepository $instruments): array
    {
        $inserted = 0;
        $unchanged = 0;

        foreach (self::LIST as [$isin, $name, $list]) {
            if ($instruments->get($isin) === null) {
                ++$inserted;
            } else {
                ++$unchanged;
            }
            $instruments->upsertSeed($isin, $name, $list);
        }

        return ['inserted' => $inserted, 'unchanged' => $unchanged];
    }
}
