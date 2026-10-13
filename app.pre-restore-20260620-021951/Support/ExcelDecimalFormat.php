<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel custom number formats for Indonesian locale (comma decimal separator).
 *
 * - {@see self::DIMENSION} / {@see self::COUNT}: whole numbers without leading-zero
 *   padding (9, 30 — not 09, 030).
 * - {@see self::INTEGER}: grouped whole numbers for currency (1.234).
 * - {@see self::DECIMAL_*}: fractional values with mandatory leading zero before
 *   the comma (0,456 — not ,456). Do not use `#` before the comma for values
 *   that can be below 1.
 */
final class ExcelDecimalFormat
{
    /** Plain whole number: no padding, no decimals. e.g. 9, 30, 105 */
    public const DIMENSION = '0';

    /** Alias for {@see self::DIMENSION} — PCS, coly, ctns counts. */
    public const COUNT = '0';

    /** Grouped whole numbers (Indonesian thousands), e.g. 1.234 */
    public const INTEGER = '#.##0';

    /** Two decimals with mandatory leading zero, e.g. 0,34 or 9,50 */
    public const DECIMAL_2 = '0,00';

    /** Three decimals with mandatory leading zero, e.g. 0,456 */
    public const DECIMAL_3 = '0,000';

    /** Four decimals with mandatory leading zero, e.g. 0,3400 */
    public const DECIMAL_4 = '0,0000';

    /** Indonesian Rupiah — matches simulasi export omset template. */
    public const RUPIAH = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"??_-;_-@_-';

    /** Rupiah format for total-jual / keuntungan columns (three ? placeholders). */
    public const RUPIAH_TOTAL = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"???_-;_-@_-';

    /** Alias used by finance exports. */
    public const RUPIAH_WHOLE = '[$-421] #,##0;[$-421] -#,##0;[$-421] 0';

    /** CBM values with optional fraction and leading zero. */
    public const CBM = '0.###;-0.###;0';

    /** Weight values with optional fraction and leading zero. */
    public const WEIGHT = '0.##;-0.##;0';

    public static function apply(Worksheet $sheet, string $range, string $formatCode): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($formatCode);
    }
}
