<?php

declare(strict_types=1);

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * OOXML format codes use {@code .} as the decimal separator; Excel displays
 * according to the user's locale (e.g. 13,5 in Indonesian Excel).
 *
 * - {@see self::DIMENSION} / {@see self::COUNT}: whole numbers without padding.
 * - {@see self::WEIGHT} / {@see self::CBM}: optional fraction, leading zero only
 *   when value is below 1 (13 not 013; 0,5 not ,5).
 * - {@see self::DECIMAL_*}: fixed decimal places with leading zero before the
 *   decimal point for values below 1.
 */
final class ExcelDecimalFormat
{
    /** Plain whole number: no padding, no decimals. e.g. 9, 30, 105 */
    public const DIMENSION = '0';

    /** Alias for {@see self::DIMENSION} — PCS, coly, ctns counts. */
    public const COUNT = '0';

    /** Grouped whole numbers (Indonesian thousands), e.g. 1.234 */
    public const RUPIAH_WHOLE = '[$-421] #,##0;[$-421] -#,##0;[$-421] 0';

    /** @see RUPIAH_WHOLE */
    public const INTEGER = self::RUPIAH_WHOLE;

    /** Two fixed decimals; leading zero only below 1 (0.34, 9.50). */
    public const DECIMAL_2 = '0.00';

    /** Three fixed decimals; leading zero only below 1 (0.456). */
    public const DECIMAL_3 = '0.000';

    /** Four fixed decimals; leading zero only below 1 (0.3400). */
    public const DECIMAL_4 = '0.0000';

    /** Indonesian Rupiah — matches simulasi export omset template. */
    public const RUPIAH = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"??_-;_-@_-';

    /** Rupiah format for total-jual / keuntungan columns (three ? placeholders). */
    public const RUPIAH_TOTAL = '_-[$Rp-421]* #,##0_-;\-[$Rp-421]* #,##0_-;_-[$Rp-421]* "-"???_-;_-@_-';

    /** Company block (AC–AI) — matches Rekapan omset kontainer template. */
    public const RUPIAH_COMPANY = '_-"Rp"* #,##0.00_-;\-"Rp"* #,##0.00_-;_-"Rp"* "-"??_-;_-@_-';

    /** CBM values with optional fraction and leading zero. */
    public const CBM = '0.###;-0.###;0';

    /** Weight values with optional fraction and leading zero. */
    public const WEIGHT = '0.##;-0.##;0';

    /** Excel percent (value is fraction: 0.025 displays as 2.50%). */
    public const PERCENT = '0.00%';

    /** Locale-aware display without forcing trailing decimals. */
    public const GENERAL = 'General';

    public static function apply(Worksheet $sheet, string $range, string $formatCode): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($formatCode);
    }

    /**
     * Write a number so whole values show without a trailing decimal/comma
     * (e.g. 500 not 500,), while fractions keep optional decimals (9,8 / 0,1).
     */
    public static function setOptionalDecimal(
        Worksheet $sheet,
        string $coordinate,
        float $value,
        int $maxDecimals = 2,
    ): void {
        $maxDecimals = max(0, $maxDecimals);
        $rounded = round($value, $maxDecimals);

        if (abs($rounded - round($rounded)) < 1e-9) {
            $sheet->setCellValue($coordinate, (int) round($rounded));
            self::apply($sheet, $coordinate, self::DIMENSION);

            return;
        }

        $sheet->setCellValue($coordinate, $rounded);
        self::apply(
            $sheet,
            $coordinate,
            $maxDecimals >= 3 ? self::CBM : self::WEIGHT,
        );
    }

    /** Re-apply format for an existing numeric cell (after range styling). */
    public static function refreshOptionalDecimal(
        Worksheet $sheet,
        string $coordinate,
        int $maxDecimals = 2,
    ): void {
        $raw = $sheet->getCell($coordinate)->getValue();
        if (! is_numeric($raw)) {
            return;
        }

        self::setOptionalDecimal($sheet, $coordinate, (float) $raw, $maxDecimals);
    }
}
