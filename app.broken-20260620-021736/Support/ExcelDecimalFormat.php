<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel custom number formats for Indonesian locale (comma decimal separator).
 *
 * Use {@see self::INTEGER} for whole numbers. For fractional values, prefer
 * {@see self::DECIMAL_2} / {@see self::DECIMAL_3} / {@see self::DECIMAL_4}
 * instead of patterns starting with `#` — optional integer digits hide
 * the leading zero (0,34 becomes ,34).
 */
final class ExcelDecimalFormat
{
    /** Whole numbers with thousands separator, e.g. 1.234 */
    public const INTEGER = '#.##0';

    /** Two decimals with mandatory leading zero, e.g. 0,34 */
    public const DECIMAL_2 = '0,00';

    /** Three decimals with mandatory leading zero, e.g. 0,340 */
    public const DECIMAL_3 = '0,000';

    /** Four decimals with mandatory leading zero, e.g. 0,3400 */
    public const DECIMAL_4 = '0,0000';

    /** Indonesian Rupiah — matches simulasi export omset template. */
    public const RUPIAH = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"??_-;_-@_-';

    /** Rupiah format for total-jual / keuntungan columns (three ? placeholders). */
    public const RUPIAH_TOTAL = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"???_-;_-@_-';

    public static function apply(Worksheet $sheet, string $range, string $formatCode): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($formatCode);
    }
}
