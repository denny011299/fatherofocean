<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ExcelDecimalFormat;
use PHPUnit\Framework\TestCase;

final class ExcelDecimalFormatTest extends TestCase
{
    public function test_count_and_dimension_formats_are_plain_integers(): void
    {
        $this->assertSame('0', ExcelDecimalFormat::COUNT);
        $this->assertSame('0', ExcelDecimalFormat::DIMENSION);
        $this->assertSame(ExcelDecimalFormat::DIMENSION, ExcelDecimalFormat::COUNT);
    }

    public function test_rupiah_whole_format_uses_indonesian_locale_grouping(): void
    {
        $this->assertSame('[$-421] #,##0;[$-421] -#,##0;[$-421] 0', ExcelDecimalFormat::RUPIAH_WHOLE);
        $this->assertSame(ExcelDecimalFormat::RUPIAH_WHOLE, ExcelDecimalFormat::INTEGER);
        $this->assertStringContainsString('#,##0', ExcelDecimalFormat::RUPIAH_WHOLE);
        $this->assertStringContainsString('[$-421]', ExcelDecimalFormat::RUPIAH_WHOLE);
        $this->assertStringNotContainsString('#.##0', ExcelDecimalFormat::RUPIAH_WHOLE);
    }

    public function test_decimal_formats_use_mandatory_leading_zero(): void
    {
        $this->assertStringStartsWith('0', ExcelDecimalFormat::DECIMAL_2);
        $this->assertStringStartsWith('0', ExcelDecimalFormat::DECIMAL_3);
        $this->assertStringStartsWith('0', ExcelDecimalFormat::DECIMAL_4);
        $this->assertSame('0.00', ExcelDecimalFormat::DECIMAL_2);
        $this->assertSame('0.000', ExcelDecimalFormat::DECIMAL_3);
        $this->assertSame('0.0000', ExcelDecimalFormat::DECIMAL_4);
        $this->assertStringNotContainsString('#', ExcelDecimalFormat::DECIMAL_2);
        $this->assertStringNotContainsString('#', ExcelDecimalFormat::DECIMAL_3);
        $this->assertStringNotContainsString('#', ExcelDecimalFormat::DECIMAL_4);
    }

    public function test_weight_and_cbm_formats_use_ooxml_optional_fraction(): void
    {
        $this->assertSame('0.##;-0.##;0', ExcelDecimalFormat::WEIGHT);
        $this->assertSame('0.###;-0.###;0', ExcelDecimalFormat::CBM);
        $this->assertStringStartsWith('0.', ExcelDecimalFormat::WEIGHT);
        $this->assertStringStartsWith('0.', ExcelDecimalFormat::CBM);
        $this->assertStringEndsWith(';0', ExcelDecimalFormat::WEIGHT);
        $this->assertStringEndsWith(';0', ExcelDecimalFormat::CBM);
    }

    public function test_optional_decimal_writes_whole_numbers_without_fraction_format(): void
    {
        $sheet = (new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet();

        ExcelDecimalFormat::setOptionalDecimal($sheet, 'A1', 500.0, 0);
        ExcelDecimalFormat::setOptionalDecimal($sheet, 'A2', 11.0, 2);
        ExcelDecimalFormat::setOptionalDecimal($sheet, 'A3', 9.8, 2);
        ExcelDecimalFormat::setOptionalDecimal($sheet, 'A4', 0.1, 3);

        $this->assertSame(500, $sheet->getCell('A1')->getValue());
        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('A1')->getNumberFormat()->getFormatCode());
        $this->assertSame(11, $sheet->getCell('A2')->getValue());
        $this->assertSame(ExcelDecimalFormat::DIMENSION, $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame(9.8, $sheet->getCell('A3')->getValue());
        $this->assertSame(ExcelDecimalFormat::WEIGHT, $sheet->getStyle('A3')->getNumberFormat()->getFormatCode());
        $this->assertSame(0.1, $sheet->getCell('A4')->getValue());
        $this->assertSame(ExcelDecimalFormat::CBM, $sheet->getStyle('A4')->getNumberFormat()->getFormatCode());
    }
}
