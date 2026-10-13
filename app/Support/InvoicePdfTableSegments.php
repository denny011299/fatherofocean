<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pecah baris invoice PDF per halaman (A4 landscape).
 * Halaman pertama (letterhead) sedikit baris supaya tabel naik & muat;
 * halaman berikutnya hampir penuh, lalu potong ke tabel baru.
 */
final class InvoicePdfTableSegments
{
    /** Setelah letterhead dikompres: muat di halaman 1 bersama kop. */
    private const FIRST_PAGE_ROWS = 10;

    /** Halaman lanjutan: hampir penuh kertas. */
    private const NEXT_PAGE_ROWS = 22;

    /**
     * @param  list<array<string, mixed>>  $tableRows
     * @return list<list<list<array<string, mixed>>>> halaman → grup merge → baris
     */
    public static function build(array $tableRows, bool $hasLetterheadAboveTable = false): array
    {
        if ($tableRows === []) {
            return [];
        }

        $pages = [];
        $pageRows = [];
        $capacity = $hasLetterheadAboveTable ? self::FIRST_PAGE_ROWS : self::NEXT_PAGE_ROWS;

        foreach ($tableRows as $row) {
            if (count($pageRows) >= $capacity) {
                $pages[] = self::groupRows($pageRows);
                $pageRows = [];
                $capacity = self::NEXT_PAGE_ROWS;
            }

            $pageRows[] = $row;
        }

        if ($pageRows !== []) {
            $pages[] = self::groupRows($pageRows);
        }

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $tableRows
     * @return list<list<array<string, mixed>>>
     */
    private static function groupRows(array $tableRows): array
    {
        $groups = [];
        $currentGroup = [];
        $currentIndex = null;

        foreach ($tableRows as $row) {
            $indexNo = (string) ($row['index_no'] ?? '');
            if ($currentGroup !== [] && $indexNo !== $currentIndex) {
                $groups[] = $currentGroup;
                $currentGroup = [];
            }

            $currentIndex = $indexNo;
            $currentGroup[] = $row;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }
}
