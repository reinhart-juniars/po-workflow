<?php

namespace App\Exports;

/**
 * Export halaman Tarik Resep. Sheet pertama memakai kolom yang sama dengan
 * export SPK PRA di Master Menu Revamp (No, Order, QTY, Satuan, Pelaksana) dan
 * jumlah dalam satuan takaran resep, karena gudang menempelkannya ke berkas
 * Excel sendiri; sheet kedua rekap bahan & stok seperti breakdown SPK.
 */
class RecipePullExport extends MaterialBreakdownExport
{
    public function sheets(): array
    {
        return [
            new MaterialBreakdownSheet('Tarik Resep', $this->orderRows()),
            new MaterialBreakdownSheet('Rekap Bahan', $this->recapRows()),
        ];
    }

    /** @return list<list<mixed>> */
    protected function orderRows(): array
    {
        $rows = [['', 'No', 'Order', 'QTY', 'Satuan', 'Pelaksana']];

        foreach ($this->breakdown['menus'] as $index => $menu) {
            $rows[] = ['', $index + 1, 'siapkan komponen '.$menu['label'], $menu['qty'], $menu['unit'], ''];
            array_push($rows, ...$this->branchRows($menu['tree'], 0));

            foreach ($menu['issues'] as $issue) {
                $rows[] = ['', '', 'catatan: '.$issue];
            }

            $rows[] = [];
        }

        foreach ($this->breakdown['missing'] as $menu) {
            $rows[] = ['', '', 'BELUM ADA RESEP: '.$menu['label'].' ('.$menu['qty'].' '.$menu['unit'].')'];
        }

        return $rows;
    }

    /**
     * Baris satu tingkat resep, dikelompokkan per bagian (urutan kemunculan).
     * Bahan di dalam sub-menu diberi indentasi di bawah baris sub-menunya.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<list<mixed>>
     */
    protected function branchRows(array $nodes, int $depth): array
    {
        $rows = [];
        $indent = str_repeat('    ', $depth);

        foreach (collect($nodes)->groupBy(fn ($node) => (string) ($node['section'] ?? '')) as $section => $group) {
            if ((string) $section !== '') {
                $rows[] = ['', '', $indent.$section];
            }

            foreach ($group as $node) {
                $rows[] = ['', '', $indent.$node['name'], $node['qty'], $node['unit']];
                array_push($rows, ...$this->branchRows($node['children'], $depth + 1));
            }
        }

        return $rows;
    }
}
