<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Barang;
use App\Models\Container;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Marking;
use App\Support\InvoiceConsolidation;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

final class InvoiceConsolidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mockery::mock('alias:'.Marking::class)
            ->shouldReceive('resolveCodeFromBarangMarkingOnly')
            ->andReturnUsing(static fn (string $value): string => trim($value));
    }

    public function test_shares_container_scope_when_both_unassigned(): void
    {
        $a = new Barang(['marking' => 'ABC01']);
        $b = new Barang(['marking' => 'ABC01']);
        $a->setRelation('containers', new Collection());
        $b->setRelation('containers', new Collection());

        $this->assertTrue(InvoiceConsolidation::sharesContainerScope($a, $b));
    }

    public function test_container_scope_requires_identical_container_sets(): void
    {
        $inContainer = new Barang(['marking' => 'ABC01']);
        $inContainer->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $otherContainer = new Barang(['marking' => 'ABC01']);
        $otherContainer->setRelation('containers', new Collection([
            $this->containerWithId(20),
        ]));

        $noContainer = new Barang(['marking' => 'ABC01']);
        $noContainer->setRelation('containers', new Collection());

        $sameContainer = new Barang(['marking' => 'ABC01']);
        $sameContainer->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $this->assertFalse(InvoiceConsolidation::sharesContainerScope($inContainer, $otherContainer));
        $this->assertFalse(InvoiceConsolidation::sharesContainerScope($inContainer, $noContainer));
        $this->assertFalse(InvoiceConsolidation::sharesContainerScope($noContainer, $inContainer));
        $this->assertTrue(InvoiceConsolidation::sharesContainerScope($inContainer, $sameContainer));
    }

    public function test_partial_container_overlap_does_not_share_scope(): void
    {
        $dual = new Barang(['marking' => 'ABC01']);
        $dual->setRelation('containers', new Collection([
            $this->containerWithId(10),
            $this->containerWithId(20),
        ]));

        $onlyTen = new Barang(['marking' => 'ABC01']);
        $onlyTen->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $this->assertFalse(InvoiceConsolidation::sharesContainerScope($dual, $onlyTen));
        $this->assertFalse(InvoiceConsolidation::sharesContainerScope($onlyTen, $dual));
    }

    public function test_invoice_with_mixed_containers_does_not_match_single_container_barang(): void
    {
        $inTen = new Barang(['marking' => 'ABC01', 'no_index' => 'A']);
        $inTen->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $inTwenty = new Barang(['marking' => 'ABC01', 'no_index' => 'B']);
        $inTwenty->setRelation('containers', new Collection([
            $this->containerWithId(20),
        ]));

        $invoice = new Invoice(['user_id' => 1, 'status' => 'draft']);
        $invoice->setRelation('items', new Collection([
            $this->invoiceItemFor($inTen),
            $this->invoiceItemFor($inTwenty),
        ]));

        $this->assertFalse(
            InvoiceConsolidation::invoiceMatchesBarangScope($invoice, $inTen),
        );
        $this->assertFalse(
            InvoiceConsolidation::invoiceMatchesBarangScope($invoice, $inTwenty),
        );
    }

    public function test_scope_key_distinguishes_containers_for_same_marking(): void
    {
        $containerTen = new Barang(['marking' => 'ABC01']);
        $containerTen->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $containerTwenty = new Barang(['marking' => 'ABC01']);
        $containerTwenty->setRelation('containers', new Collection([
            $this->containerWithId(20),
        ]));

        $this->assertNotSame(
            InvoiceConsolidation::scopeKey($containerTen),
            InvoiceConsolidation::scopeKey($containerTwenty),
        );
    }

    public function test_invoice_matches_scope_key_requires_same_container_scope(): void
    {
        $anchor = new Barang(['marking' => 'ABC01']);
        $anchor->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $sameScope = new Barang(['marking' => 'ABC01', 'no_index' => 'B2']);
        $sameScope->setRelation('containers', new Collection([
            $this->containerWithId(10),
        ]));

        $otherScope = new Barang(['marking' => 'ABC01', 'no_index' => 'B3']);
        $otherScope->setRelation('containers', new Collection([
            $this->containerWithId(20),
        ]));

        $invoice = new Invoice(['user_id' => 1, 'status' => 'draft']);
        $invoice->setRelation('items', new Collection([
            $this->invoiceItemFor($sameScope),
        ]));

        $this->assertTrue(
            InvoiceConsolidation::invoiceMatchesBarangScope($invoice, $anchor),
        );
        $this->assertFalse(
            InvoiceConsolidation::invoiceMatchesBarangScope($invoice, $otherScope),
        );
    }

    public function test_container_ids_for_unsaved_barang_without_loaded_relation_returns_empty(): void
    {
        $barang = new Barang(['marking' => 'ABC01']);

        $this->assertSame([], InvoiceConsolidation::containerIdsFor($barang));
    }

    public function test_group_barangs_by_scope_splits_different_containers(): void
    {
        $a = new Barang(['marking' => 'ABC01']);
        $a->setRelation('containers', new Collection([$this->containerWithId(10)]));

        $b = new Barang(['marking' => 'ABC01']);
        $b->setRelation('containers', new Collection([$this->containerWithId(20)]));

        $groups = InvoiceConsolidation::groupBarangsByScope(collect([$a, $b]));

        $this->assertCount(2, $groups);
    }

    private function containerWithId(int $id): Container
    {
        $container = new Container;
        $container->id = $id;

        return $container;
    }

    private function invoiceItemFor(Barang $barang): InvoiceItem
    {
        $item = new InvoiceItem(['barang_id' => $barang->id]);
        $item->setRelation('barang', $barang);

        return $item;
    }
}
