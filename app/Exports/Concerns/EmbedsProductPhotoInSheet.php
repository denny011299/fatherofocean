<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait EmbedsProductPhotoInSheet
{
    private function embedProductPhotoInSheet(
        Worksheet $sheet,
        ?string $photoPath,
        string $cell,
        int $row,
        float $columnWidth,
        float $rowHeightPt,
        int $imageHeightPx,
        string $borderReferenceColumn = 'K',
    ): void {
        $this->formatPhotoCell($sheet, $cell, $row, $borderReferenceColumn);

        if ($photoPath === null || $photoPath === '') {
            $sheet->getRowDimension($row)->setRowHeight(-1);

            return;
        }

        $sheet->getRowDimension($row)->setRowHeight($rowHeightPt);

        $diskPath = Storage::disk('public')->path($photoPath);
        if (!is_file($diskPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Product photo');
        $drawing->setDescription('Product photo');
        $drawing->setPath($diskPath);
        $drawing->setHeight($imageHeightPx);
        $drawing->setCoordinates($cell);
        $drawing->setWorksheet($sheet);

        $cellWidthPx = $this->columnWidthToPixels($columnWidth);
        $cellHeightPx = (int) round($rowHeightPt * 96 / 72);
        $paddingPx = 4;
        $maxWidthPx = max(1, $cellWidthPx - ($paddingPx * 2));
        $maxHeightPx = max(1, $cellHeightPx - ($paddingPx * 2));

        $imageWidthPx = (int) round($drawing->getWidth());
        $imageHeightPxActual = (int) round($drawing->getHeight());
        $scale = min(1.0, $maxWidthPx / $imageWidthPx, $maxHeightPx / $imageHeightPxActual);

        if ($scale < 1.0) {
            $drawing->setHeight((int) round($imageHeightPxActual * $scale));
            $imageWidthPx = (int) round($drawing->getWidth());
            $imageHeightPxActual = (int) round($drawing->getHeight());
        }

        $drawing->setOffsetX(max(0, (int) round(($cellWidthPx - $imageWidthPx) / 2)));
        $drawing->setOffsetY(max(0, (int) round(($cellHeightPx - $imageHeightPxActual) / 2)));
    }

    private function formatPhotoColumnHeader(
        Worksheet $sheet,
        string $photoColumn,
        int $headerRow,
        int $subheaderRow,
        string $borderReferenceColumn,
        string $alignmentReferenceCell,
        ?string $headerText = null,
    ): void {
        $headerRange = "{$photoColumn}{$headerRow}:{$photoColumn}{$subheaderRow}";

        $this->unmergePhotoHeaderRangeIfPresent($sheet, $photoColumn, $headerRow, $subheaderRow);

        [$title, $subtitle] = $this->splitPhotoHeaderLines($headerText);
        $label = $title;

        if ($subtitle !== '') {
            $label = $title !== '' ? $title . "\n" . $subtitle : $subtitle;
        }

        $sheet->mergeCells($headerRange);
        $sheet->setCellValue($photoColumn . $headerRow, $label);

        $this->applyPhotoHeaderStyleFromReference(
            $sheet,
            $headerRange,
            $borderReferenceColumn . $headerRow,
            $alignmentReferenceCell,
        );
    }

    private function resolvePhotoColumnHeaderRange(
        Worksheet $sheet,
        string $column,
        int $headerRow,
        int $subheaderRow,
    ): ?string {
        $expected = "{$column}{$headerRow}:{$column}{$subheaderRow}";

        if (isset($sheet->getMergeCells()[$expected])) {
            return $expected;
        }

        foreach ($sheet->getMergeCells() as $range => $_) {
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches) !== 1) {
                continue;
            }

            if ($matches[1] !== $column || $matches[3] !== $column) {
                continue;
            }

            if ((int) $matches[2] === $headerRow && (int) $matches[4] === $subheaderRow) {
                return $range;
            }
        }

        return null;
    }

    private function applyPhotoHeaderStyleFromReference(
        Worksheet $sheet,
        string $targetRange,
        string $borderReferenceCell,
        string $alignmentReferenceCell,
    ): void {
        $borderReferenceStyle = $sheet->getStyle($borderReferenceCell);
        $alignmentReferenceStyle = $sheet->getStyle($alignmentReferenceCell);

        $this->copyCellBorders($sheet, $targetRange, $borderReferenceCell);

        $style = $sheet->getStyle($targetRange);

        $referenceFont = $borderReferenceStyle->getFont();
        $style->getFont()
            ->setName($referenceFont->getName())
            ->setSize($referenceFont->getSize())
            ->setBold(true);

        $referenceFill = $borderReferenceStyle->getFill();
        $style->getFill()->setFillType($referenceFill->getFillType());

        if ($referenceFill->getFillType() === Fill::FILL_SOLID) {
            $style->getFill()->getStartColor()->setRGB($referenceFill->getStartColor()->getRGB());
        }

        $referenceAlignment = $alignmentReferenceStyle->getAlignment();
        $style->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical($referenceAlignment->getVertical())
            ->setWrapText(true);

        $sheet->getStyle($targetRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
    }

    private function formatPhotoCell(
        Worksheet $sheet,
        string $cell,
        int $row,
        string $borderReferenceColumn,
    ): void {
        $referenceCell = $borderReferenceColumn . $row;
        $this->copyCellBorders($sheet, $cell, $referenceCell);
        $this->ensurePhotoCellHasBorders($sheet, $cell);

        $style = $sheet->getStyle($cell);
        $style->getFill()->setFillType(Fill::FILL_NONE);
        $style->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function copyCellBorders(Worksheet $sheet, string $targetCell, string $referenceCell): void
    {
        $referenceBorders = $sheet->getStyle($referenceCell)->getBorders();
        $targetBorders = $sheet->getStyle($targetCell)->getBorders();

        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            $referenceSide = $referenceBorders->{'get' . $side}();
            $targetSide = $targetBorders->{'get' . $side}();
            $targetSide->setBorderStyle($referenceSide->getBorderStyle());
            $targetSide->getColor()->setRGB($referenceSide->getColor()->getRGB());
        }
    }

    private function ensurePhotoCellHasBorders(Worksheet $sheet, string $cell): void
    {
        $borders = $sheet->getStyle($cell)->getBorders();
        $hasVisibleBorder = false;

        foreach (['Top', 'Bottom', 'Left', 'Right'] as $side) {
            if ($borders->{'get' . $side}()->getBorderStyle() !== Border::BORDER_NONE) {
                $hasVisibleBorder = true;

                break;
            }
        }

        if ($hasVisibleBorder) {
            return;
        }

        $sheet->getStyle($cell)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
    }

    private function columnWidthToPixels(float $width): int
    {
        return (int) round($width * 7 + 5);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPhotoHeaderLines(?string $headerText): array
    {
        if ($headerText === null || trim($headerText) === '') {
            return ['Photo', '品图'];
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($headerText)) ?: [];
        $title = trim((string) ($lines[0] ?? ''));
        $subtitle = trim((string) ($lines[1] ?? ''));

        return [$title, $subtitle];
    }

    private function unmergePhotoHeaderRangeIfPresent(
        Worksheet $sheet,
        string $photoColumn,
        int $headerRow,
        int $subheaderRow,
    ): void {
        foreach ($sheet->getMergeCells() as $range => $_) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $range, $matches)) {
                continue;
            }

            if ($matches[1] !== $photoColumn || $matches[3] !== $photoColumn) {
                continue;
            }

            $startRow = (int) $matches[2];
            $endRow = (int) $matches[4];

            if ($startRow <= $subheaderRow && $endRow >= $headerRow) {
                $sheet->unmergeCells($range);
            }
        }
    }
}
