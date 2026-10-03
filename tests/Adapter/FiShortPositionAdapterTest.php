<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Adapter;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Stockpicker\Adapter\FiShortPositionAdapter;
use Stockpicker\Adapter\ShortPosition;
use Stockpicker\Error\SchemaMismatch;
use Stockpicker\Error\Transient;

/**
 * spec-short-interest-data — FI's aggregated short-position ODS. The fixture
 * is built in-test with ZipArchive and mirrors the live file's shape
 * (verified 2026-10-03): title rows, a bilingual header row, float-typed
 * percentage cells displayed with a decimal comma, string dates, and padding
 * rows/columns with huge `number-*-repeated` counts.
 */
final class FiShortPositionAdapterTest extends AdapterTestCase
{
    private const HEADER = [
        'Namn på emittent (Name of the issuer)',
        'LEI',
        'Position i procent (Position in per cent)',
        'Positionsdatum senaste position (Position date of latest position)',
    ];

    private function adapter(): FiShortPositionAdapter
    {
        return new FiShortPositionAdapter($this->client(), new NullLogger(), 'https://fi.test/agg');
    }

    /** A data row as the live file has it: name (leading space), LEI, float cell, date string. */
    private static function row(string $name, string $lei, string $pctValue, string $pctDisplay, string $date): string
    {
        return '<table:table-row>'
            . self::stringCell($name)
            . self::stringCell($lei)
            . '<table:table-cell office:value-type="float" office:value="' . $pctValue . '" calcext:value-type="float"><text:p>' . $pctDisplay . '</text:p></table:table-cell>'
            . self::stringCell($date)
            . '<table:table-cell table:number-columns-repeated="16380"/>'
            . '</table:table-row>';
    }

    private static function stringCell(string $text): string
    {
        return '<table:table-cell office:value-type="string" calcext:value-type="string"><text:p>'
            . htmlspecialchars($text, ENT_XML1) . '</text:p></table:table-cell>';
    }

    /** @param list<string> $labels */
    private static function headerRow(array $labels): string
    {
        return '<table:table-row>' . implode('', array_map(self::stringCell(...), $labels)) . '</table:table-row>';
    }

    /** @param list<string> $rows raw `table:table-row` XML after the title rows */
    private static function ods(array $rows): string
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0"'
            . ' office:version="1.2"><office:body><office:spreadsheet>'
            . '<table:table table:name="Blad1">'
            . '<table:table-column table:number-columns-repeated="16384"/>'
            . '<table:table-row>' . self::stringCell('Aggregerade blankningspositioner') . '<table:table-cell table:number-columns-repeated="16383"/></table:table-row>'
            . '<table:table-row table:number-rows-repeated="2"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
            . implode('', $rows)
            . '<table:table-row table:number-rows-repeated="700000"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
            . '</table:table></office:spreadsheet></office:body></office:document-content>';

        $tmp = tempnam(sys_get_temp_dir(), 'fi-ods-test-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->addFromString('content.xml', $content);
        $zip->close();
        $body = (string) file_get_contents($tmp);
        unlink($tmp);

        return $body;
    }

    private static function normalFile(): string
    {
        return self::ods([
            self::headerRow(self::HEADER),
            self::row(' Elekta AB (publ)', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02'),
            self::row(' Example Holding AB', '549300ABCDEFGHIJ1234', '1.61', '1,61', '2026-09-30'),
        ]);
    }

    private function odsResponse(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/vnd.oasis.opendocument.spreadsheet'], $body);
    }

    public function testNormalFileYieldsOneNormalizedRowPerIssuerWithDecimalCommaHandled(): void
    {
        $this->queue([$this->odsResponse(self::normalFile())]);

        $rows = $this->adapter()->fetchAggregated();

        self::assertEquals([
            new ShortPosition('54930044O54BK617EP80', 'Elekta AB (publ)', 16.05, '2026-10-02'),
            new ShortPosition('549300ABCDEFGHIJ1234', 'Example Holding AB', 1.61, '2026-09-30'),
        ], $rows);
        self::assertQueueDrained();
        $request = $this->mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('https://fi.test/agg', (string) $request->getUri());
    }

    public function testDecimalCommaTextIsParsedWhenTheCellIsNotFloatTyped(): void
    {
        $body = self::ods([
            self::headerRow(self::HEADER),
            '<table:table-row>' . self::stringCell('Text AB') . self::stringCell('549300ABCDEFGHIJ1234')
                . self::stringCell('1,61') . self::stringCell('2026-10-02') . '</table:table-row>',
        ]);

        $rows = $this->adapter()->parseOds($body);

        self::assertCount(1, $rows);
        self::assertSame(1.61, $rows[0]->positionPct);
    }

    public function testUnparseableNumberIsASchemaMismatchForTheWholeFile(): void
    {
        $body = self::ods([
            self::headerRow(self::HEADER),
            self::row('Good AB', '549300ABCDEFGHIJ1234', '1.61', '1,61', '2026-10-02'),
            '<table:table-row>' . self::stringCell('Bad AB') . self::stringCell('549300ZZZZZZZZZZ9999')
                . self::stringCell('abc') . self::stringCell('2026-10-02') . '</table:table-row>',
        ]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->parseOds($body);
    }

    public function testUnparseableDateIsASchemaMismatch(): void
    {
        $body = self::ods([
            self::headerRow(self::HEADER),
            self::row('Good AB', '549300ABCDEFGHIJ1234', '1.61', '1,61', '2 okt 2026'),
        ]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->parseOds($body);
    }

    public function testHeaderMissingLeiColumnIsASchemaMismatch(): void
    {
        $header = self::HEADER;
        $header[1] = 'Identifierare';
        $this->queue([$this->odsResponse(self::ods([
            self::headerRow($header),
            self::row('Elekta AB (publ)', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02'),
        ]))]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetchAggregated();
    }

    public function testHeaderMissingPositionColumnIsASchemaMismatch(): void
    {
        $header = self::HEADER;
        $header[2] = 'Andel';

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->parseOds(self::ods([
            self::headerRow($header),
            self::row('Elekta AB (publ)', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02'),
        ]));
    }

    public function testNoHeaderRowAtAllIsASchemaMismatch(): void
    {
        $this->expectException(SchemaMismatch::class);
        $this->adapter()->parseOds(self::ods([
            self::row('Elekta AB (publ)', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02'),
        ]));
    }

    public function testHeaderButNoDataRowsIsASchemaMismatch(): void
    {
        $this->expectException(SchemaMismatch::class);
        $this->adapter()->parseOds(self::ods([self::headerRow(self::HEADER)]));
    }

    public function testColumnsAreLocatedByLabelNotPosition(): void
    {
        $body = self::ods([
            self::headerRow([self::HEADER[1], self::HEADER[3], self::HEADER[0], self::HEADER[2]]),
            '<table:table-row>' . self::stringCell('54930044O54BK617EP80') . self::stringCell('2026-10-02')
                . self::stringCell('Elekta AB (publ)')
                . '<table:table-cell office:value-type="float" office:value="16.05"><text:p>16,05</text:p></table:table-cell>'
                . '</table:table-row>',
        ]);

        self::assertEquals(
            [new ShortPosition('54930044O54BK617EP80', 'Elekta AB (publ)', 16.05, '2026-10-02')],
            $this->adapter()->parseOds($body),
        );
    }

    public function testPercentageTypedCellIsScaledToPercent(): void
    {
        $rows = $this->adapter()->parseOds(self::ods([
            self::headerRow(self::HEADER),
            '<table:table-row>' . self::stringCell('Elekta AB (publ)') . self::stringCell('54930044O54BK617EP80')
                . '<table:table-cell office:value-type="percentage" office:value="0.1605"><text:p>16,05 %</text:p></table:table-cell>'
                . self::stringCell('2026-10-02') . '</table:table-row>',
        ]));

        self::assertEqualsWithDelta(16.05, $rows[0]->positionPct, 1e-9);
    }

    public function testDateTypedCellUsesItsDateValue(): void
    {
        $rows = $this->adapter()->parseOds(self::ods([
            self::headerRow(self::HEADER),
            '<table:table-row>' . self::stringCell('Elekta AB (publ)') . self::stringCell('54930044O54BK617EP80')
                . '<table:table-cell office:value-type="float" office:value="16.05"><text:p>16,05</text:p></table:table-cell>'
                . '<table:table-cell office:value-type="date" office:date-value="2026-10-02T00:00:00"><text:p>2 okt 2026</text:p></table:table-cell>'
                . '</table:table-row>',
        ]));

        self::assertSame('2026-10-02', $rows[0]->positionDate);
    }

    public function testDuplicateLeiKeepsTheFirstRow(): void
    {
        $rows = $this->adapter()->parseOds(self::ods([
            self::headerRow(self::HEADER),
            self::row('First AB', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02'),
            self::row('Second AB', '54930044O54BK617EP80', '3.00', '3,00', '2026-09-01'),
        ]));

        self::assertEquals([new ShortPosition('54930044O54BK617EP80', 'First AB', 16.05, '2026-10-02')], $rows);
    }

    public function testMalformedContentXmlPartwayIsASchemaMismatchNotAPartialList(): void
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>'
            . '<table:table table:name="Blad1">'
            . self::headerRow(self::HEADER)
            . self::row('Elekta AB (publ)', '54930044O54BK617EP80', '16.05', '16,05', '2026-10-02')
            // Non-row padding so the reader's look-ahead buffer has passed the
            // last row before it hits the garbage: read() itself then fails.
            . str_repeat('<table:table-column/>', 5000)
            . '<<<'; // garbage after the last complete row; closing tags never come
        $tmp = tempnam(sys_get_temp_dir(), 'fi-ods-test-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', $content);
        $zip->close();
        $body = (string) file_get_contents($tmp);
        unlink($tmp);

        $this->expectException(SchemaMismatch::class);
        $this->expectExceptionMessage('content.xml is malformed');
        $this->adapter()->parseOds($body);
    }

    public function testNonZipBodyIsASchemaMismatch(): void
    {
        $this->queue([$this->odsResponse('<html>maintenance</html>')]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetchAggregated();
    }

    public function testFiDownTwiceIsTransientAfterOneRetry(): void
    {
        $this->queue([
            new Response(503),
            new ConnectException('timeout', new Request('GET', 'https://fi.test/agg')),
        ]);

        try {
            $this->adapter()->fetchAggregated();
            self::fail('expected Transient');
        } catch (Transient) {
            self::assertQueueDrained();
        }
    }

    public function testOneTransientFailureIsRetriedOnce(): void
    {
        $this->queue([new Response(502), $this->odsResponse(self::normalFile())]);

        self::assertCount(2, $this->adapter()->fetchAggregated());
        self::assertQueueDrained();
    }

    public function testUnexpected4xxIsASchemaMismatch(): void
    {
        $this->queue([new Response(410)]);

        $this->expectException(SchemaMismatch::class);
        $this->adapter()->fetchAggregated();
    }
}
