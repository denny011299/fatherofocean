<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BarangOriginalPackingList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class BarangOriginalPackingListTest extends TestCase
{
    public function test_filename_uses_no_index(): void
    {
        $this->assertSame('ORIGNAL_PL_5177145.xlsx', BarangOriginalPackingList::filename('5177145', 'xlsx'));
        $this->assertSame('ORIGNAL_PL_EKONDELM88.xls', BarangOriginalPackingList::filename('EK/ON/DELM88', 'xls'));
        $this->assertSame('ORIGNAL_PL_080505.pdf', BarangOriginalPackingList::filename('0805.05', 'pdf'));
    }

    public function test_allowed_extensions(): void
    {
        $this->assertTrue(BarangOriginalPackingList::isAllowedExtension('pdf'));
        $this->assertTrue(BarangOriginalPackingList::isAllowedExtension('PNG'));
        $this->assertFalse(BarangOriginalPackingList::isAllowedExtension('exe'));
    }

    public function test_is_excel_path(): void
    {
        $this->assertTrue(BarangOriginalPackingList::isExcelPath('invoices/original-pl/ORIGNAL_PL_1.xlsx'));
        $this->assertFalse(BarangOriginalPackingList::isExcelPath('invoices/original-pl/ORIGNAL_PL_1.pdf'));
    }

    public function test_upload_validation_accepts_xlsx_with_zip_mime(): void
    {
        $file = UploadedFile::fake()->create('PL QIANZJ.xlsx', 100, 'application/zip');

        $validator = Validator::make(
            ['invoice_file' => $file],
            ['invoice_file' => ['nullable', BarangOriginalPackingList::uploadValidationRule()]],
        );

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_upload_validation_rejects_unsupported_extension(): void
    {
        $file = UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream');

        $validator = Validator::make(
            ['invoice_file' => $file],
            ['invoice_file' => ['nullable', BarangOriginalPackingList::uploadValidationRule()]],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('invoice_file', $validator->errors()->toArray());
    }
}
