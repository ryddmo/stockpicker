<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

/**
 * spec-short-interest-data — the narrow port UniverseSync's LEI pass
 * depends on: resolve an instrument's issuer LEI from its ISIN. The LEI is
 * the only key that joins an instrument to Finansinspektionen's short-position
 * file (never name matching). GleifAdapter is the only production
 * implementation.
 */
interface LeiResolver
{
    /**
     * @throws \Stockpicker\Error\NotFound       no LEI record lists this ISIN
     * @throws \Stockpicker\Error\Transient      retryable failure, already retried once
     * @throws \Stockpicker\Error\SchemaMismatch an unexpected 4xx, or a response with no usable LEI
     */
    public function resolveLei(string $isin): string;
}
