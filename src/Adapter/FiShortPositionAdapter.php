<?php

declare(strict_types=1);

namespace Stockpicker\Adapter;

use DOMElement;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Stockpicker\Error\SchemaMismatch;

/**
 * spec-short-interest-data — downloads Finansinspektionen's aggregated
 * short-position file (blankningsregistret) and normalizes it to
 * ShortPosition rows. One download per night; a listing-type call, so it
 * keeps the one-shot retry (Story 2.4).
 *
 * Verified 2026-10-03 against the live file:
 *  - GET https://www.fi.se/BlankningsRegister/GetBlankningsregisterAggregat
 *    → `application/vnd.oasis.opendocument.spreadsheet` (an ODS: a zip whose
 *    `content.xml` holds one `table:table`).
 *  - a few title rows, then a header row whose cells read (Swedish label,
 *    then an English label in parentheses):
 *    "Namn på emittent" | "LEI" | "Position i procent" |
 *    "Positionsdatum senaste position"
 *  - then one row per issuer (~335): name (with a leading space), the
 *    20-character LEI, the position as a float cell (`office:value="1.61"`,
 *    displayed "1,61"), and the date as a "YYYY-MM-DD" string; then empty
 *    rows (with `number-rows-repeated` up to 700 000, and
 *    `number-columns-repeated` up to 16 384 — never expanded here).
 *  - no ISIN anywhere: instruments join on LEI only.
 *
 * Columns are located by their exact Swedish header labels (the English
 * parenthesis and whitespace ignored), never by position. A missing header
 * column, an unreadable archive, an unparseable number/date/LEI in a
 * non-empty row, or zero data rows raises SchemaMismatch — and returns
 * nothing, never a partial list (a malformed content.xml included). A
 * missing `ZipArchive`/`XMLReader`/`DOMDocument`
 * extension is reported the same way, before any download.
 */
final class FiShortPositionAdapter implements ShortPositionSource
{
    use HandlesTransientHttp;

    public const URL = 'https://www.fi.se/BlankningsRegister/GetBlankningsregisterAggregat';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; stockpicker/1.x; personal use)';

    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    public const LABEL_NAME = 'Namn på emittent';
    public const LABEL_LEI = 'LEI';
    public const LABEL_PCT = 'Position i procent';
    public const LABEL_DATE = 'Positionsdatum senaste position';

    private const LABELS = [
        'name' => self::LABEL_NAME,
        'lei' => self::LABEL_LEI,
        'pct' => self::LABEL_PCT,
        'date' => self::LABEL_DATE,
    ];

    /** Header search reads at most this many cells per row (the file pads to 16 384). */
    private const MAX_HEADER_CELLS = 64;

    /**
     * Overridable only for the integration seam (`STOCKPICKER_FI_URL`, or an
     * explicit ctor argument) — EndpointFixture pins it to an unresolvable
     * host so `/cron/derive` tests never reach the real FI site.
     */
    private readonly string $url;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        ?string $url = null,
    ) {
        $envUrl = getenv('STOCKPICKER_FI_URL');

        $this->url = $url ?? ($envUrl !== false && $envUrl !== '' ? $envUrl : self::URL);
    }

    public function fetchAggregated(): array
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw $this->schemaMismatch('fi blankningsregister: the zip/xmlreader/dom PHP extension is missing — cannot read the ODS file');
        }

        $body = $this->withOneRetry(fn (): string => $this->requestBody(
            $this->http,
            'GET',
            $this->url,
            ['headers' => ['User-Agent' => self::USER_AGENT]],
        ));

        return $this->parseOds($body);
    }

    /**
     * Parse a raw ODS file body. Public so the format rules are testable
     * without HTTP.
     *
     * @return list<ShortPosition>
     *
     * @throws SchemaMismatch
     */
    public function parseOds(string $body): array
    {
        $contentXml = $this->extractContentXml($body);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        if (!@$reader->XML($contentXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($previous);
            throw $this->schemaMismatch('fi blankningsregister: content.xml is not readable XML');
        }

        /** @var array{name: int, lei: int, pct: int, date: int}|null $columns */
        $columns = null;
        /** @var array<string, ShortPosition> $out keyed by LEI */
        $out = [];
        $inTable = false;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType === \XMLReader::END_ELEMENT
                    && $reader->namespaceURI === self::NS_TABLE
                    && $reader->localName === 'table'
                ) {
                    break; // only the first table
                }

                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->namespaceURI !== self::NS_TABLE) {
                    continue;
                }
                if ($reader->localName === 'table') {
                    $inTable = true;

                    continue;
                }
                if (!$inTable || $reader->localName !== 'table-row') {
                    continue;
                }

                $row = @$reader->expand();
                if (!$row instanceof DOMElement) {
                    throw $this->schemaMismatch('fi blankningsregister: unreadable table row');
                }

                if ($columns === null) {
                    $columns = $this->headerColumns($row);

                    continue;
                }

                $position = $this->dataRow($row, $columns);
                if ($position !== null) {
                    if (isset($out[$position->lei])) {
                        $this->logger->warning('fi blankningsregister: duplicate LEI in the file, keeping the first row', [
                            'lei' => $position->lei,
                        ]);
                    } else {
                        $out[$position->lei] = $position;
                    }
                }
            }

            // A malformed/truncated content.xml makes read() return false and
            // end the loop early — never store that partial list.
            $xmlError = libxml_get_last_error();
            if ($xmlError !== false && $xmlError->level >= LIBXML_ERR_ERROR) {
                throw $this->schemaMismatch(sprintf(
                    'fi blankningsregister: content.xml is malformed (%s)',
                    trim($xmlError->message),
                ));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }

        if ($columns === null) {
            throw $this->schemaMismatch('fi blankningsregister: header row not found (no "Namn på emittent"/"LEI"/"Position i procent"/"Positionsdatum senaste position" cell)');
        }

        if ($out === []) {
            throw $this->schemaMismatch('fi blankningsregister: no data rows after the header');
        }

        return array_values($out);
    }

    private function extractContentXml(string $body): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockpicker-fi-');
        if ($tmp === false) {
            throw $this->schemaMismatch('fi blankningsregister: could not create a temp file for the ODS archive');
        }

        try {
            if (file_put_contents($tmp, $body) === false) {
                throw $this->schemaMismatch('fi blankningsregister: could not write the ODS archive to a temp file');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw $this->schemaMismatch('fi blankningsregister: response is not a readable ODS (zip) archive');
            }

            try {
                $xml = $zip->getFromName('content.xml');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }

        if (!is_string($xml) || $xml === '') {
            throw $this->schemaMismatch('fi blankningsregister: ODS archive has no content.xml');
        }

        return $xml;
    }

    /**
     * Returns the column index of each of the four labels when `$row` is the
     * header row, or null when `$row` carries none of them (a title row).
     * A row with some but not all of the labels is the header with a
     * missing/renamed column → SchemaMismatch.
     *
     * @return array{name: int, lei: int, pct: int, date: int}|null
     */
    private function headerColumns(DOMElement $row): ?array
    {
        $found = [];
        foreach ($this->cells($row, self::MAX_HEADER_CELLS) as $index => $cell) {
            $label = self::headerLabel($cell);
            $key = array_search($label, self::LABELS, true);
            if ($key !== false && !isset($found[$key])) {
                $found[$key] = $index;
            }
        }

        if ($found === []) {
            return null;
        }

        $missing = array_diff_key(self::LABELS, $found);
        if ($missing !== []) {
            throw $this->schemaMismatch(sprintf(
                'fi blankningsregister: header row is missing column(s) %s',
                implode(', ', array_map(static fn (string $l): string => '"' . $l . '"', $missing)),
            ));
        }

        /** @var array{name: int, lei: int, pct: int, date: int} $found */
        return $found;
    }

    /**
     * @param array{name: int, lei: int, pct: int, date: int} $columns
     */
    private function dataRow(DOMElement $row, array $columns): ?ShortPosition
    {
        $cells = $this->cells($row, max($columns) + 1);
        $get = static fn (string $key): ?DOMElement => $cells[$columns[$key]] ?? null;

        $name = self::cellText($get('name'));
        $lei = strtoupper(self::cellText($get('lei')));
        $pctCell = $get('pct');
        $dateCell = $get('date');

        if ($name === '' && $lei === '' && self::cellText($pctCell) === '' && self::cellText($dateCell) === '') {
            return null; // trailing padding rows
        }

        if (!preg_match('/^[A-Z0-9]{20}$/', $lei)) {
            throw $this->schemaMismatch(sprintf('fi blankningsregister: row "%s" has no valid LEI ("%s")', $name, $lei));
        }
        if ($name === '') {
            throw $this->schemaMismatch(sprintf('fi blankningsregister: row for LEI %s has a blank issuer name', $lei));
        }

        $pct = self::cellNumber($pctCell);
        if ($pct === null || $pct < 0 || $pct > 100) {
            throw $this->schemaMismatch(sprintf(
                'fi blankningsregister: row for LEI %s has an unparseable "Position i procent" ("%s")',
                $lei,
                self::cellText($pctCell),
            ));
        }

        $date = self::cellDate($dateCell);
        if ($date === null) {
            throw $this->schemaMismatch(sprintf(
                'fi blankningsregister: row for LEI %s has an unparseable "Positionsdatum senaste position" ("%s")',
                $lei,
                self::cellText($dateCell),
            ));
        }

        return new ShortPosition($lei, $name, $pct, $date);
    }

    /**
     * The row's cells in column order, `number-columns-repeated` expanded
     * but capped at `$limit` cells (the file pads every row to 16 384).
     * Covered (merged-away) cells count as columns.
     *
     * @return list<DOMElement>
     */
    private function cells(DOMElement $row, int $limit): array
    {
        $out = [];
        foreach ($row->childNodes as $child) {
            if (count($out) >= $limit) {
                break;
            }
            if (!$child instanceof DOMElement || $child->namespaceURI !== self::NS_TABLE) {
                continue;
            }
            if ($child->localName !== 'table-cell' && $child->localName !== 'covered-table-cell') {
                continue;
            }

            $repeat = (int) ($child->getAttributeNS(self::NS_TABLE, 'number-columns-repeated') ?: 1);
            $repeat = max(1, min($repeat, $limit - count($out)));
            for ($i = 0; $i < $repeat; ++$i) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * A header cell's Swedish label: the English "(…)" part dropped and
     * whitespace collapsed — "Namn på emittent (Name of the issuer)" →
     * "Namn på emittent".
     */
    private static function headerLabel(DOMElement $cell): string
    {
        $text = (string) preg_replace('/\([^)]*\)/u', ' ', self::cellText($cell));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function cellText(?DOMElement $cell): string
    {
        if ($cell === null) {
            return '';
        }

        $parts = [];
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $parts[] = $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * A float cell's `office:value` (a percentage-typed cell's fraction × 100),
     * else the displayed text with a decimal comma or point ("1,61" → 1.61).
     * Null when neither parses.
     */
    private static function cellNumber(?DOMElement $cell): ?float
    {
        if ($cell === null) {
            return null;
        }

        $type = $cell->getAttributeNS(self::NS_OFFICE, 'value-type');
        $value = $cell->getAttributeNS(self::NS_OFFICE, 'value');
        if (($type === 'float' || $type === 'percentage') && is_numeric($value)) {
            return $type === 'percentage' ? (float) $value * 100 : (float) $value;
        }

        $text = str_replace([' ', "\u{00A0}", '%'], '', self::cellText($cell));
        if (preg_match('/^\d+([.,]\d+)?$/', $text)) {
            return (float) str_replace(',', '.', $text);
        }

        return null;
    }

    /** `office:date-value` when the cell is date-typed, else "YYYY-MM-DD" text. */
    private static function cellDate(?DOMElement $cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        $candidate = $cell->getAttributeNS(self::NS_OFFICE, 'value-type') === 'date'
            ? substr($cell->getAttributeNS(self::NS_OFFICE, 'date-value'), 0, 10)
            : self::cellText($cell);

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $candidate, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            return null;
        }

        return $candidate;
    }

    private function schemaMismatch(string $message): SchemaMismatch
    {
        $this->logger->warning($message);

        return new SchemaMismatch($message);
    }
}
