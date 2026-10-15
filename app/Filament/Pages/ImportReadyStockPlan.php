<?php

namespace App\Filament\Pages;

use App\Imports\ReadyStockPlanReader;
use App\Models\Product;
use App\Services\ReadyStockPlanImporter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Impor Excel rencana masak mingguan klien (revisi v3.1).
 *
 * Dua langkah supaya tidak ada yang tersimpan diam-diam salah: baca berkas ->
 * pratinjau per hari (menu yang belum cocok dipilihkan produknya; pilihan itu
 * diingat untuk impor berikutnya) -> simpan sebagai Rencana Masak per tanggal.
 */
class ImportReadyStockPlan extends Page
{
    use \App\Filament\Concerns\PageInPanel;
    use WithFileUploads;

    protected static string $ownerPanel = 'admin';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Impor Rencana Masak';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $slug = 'impor-rencana-masak';

    protected static string $view = 'filament.pages.import-ready-stock-plan';

    protected static ?string $title = 'Impor Rencana Masak dari Excel';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $fileName = '';

    /** @var list<array<string, mixed>> */
    public array $preview = [];

    /** @var array<string, int|string|null> kunci baris => product_id pilihan */
    public array $choices = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('plan.manage') ?? false;
    }

    public function readFile(): void
    {
        $this->validate([
            'upload' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ], [
            'upload.required' => 'Pilih berkas Excel rencana masak dulu.',
            'upload.mimes' => 'Berkas harus Excel (.xlsx atau .xls).',
        ]);

        try {
            $days = app(ReadyStockPlanReader::class)->read($this->upload->getRealPath());
        } catch (RuntimeException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->fileName = $this->upload->getClientOriginalName();
        $this->preview = app(ReadyStockPlanImporter::class)->preview($days);
        $this->choices = collect($this->preview)
            ->flatMap(fn (array $day) => collect($day['items'])->mapWithKeys(fn (array $item) => [$item['key'] => $item['product_id']]))
            ->all();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->preview === []) {
            return;
        }

        // Hanya pilihan yang berbeda dari hasil cocok otomatis yang dianggap
        // pilihan manual (dan diingat sebagai alias).
        $manual = [];
        foreach ($this->preview as $day) {
            foreach ($day['items'] as $item) {
                $chosen = $this->choices[$item['key']] ?? null;

                if (filled($chosen) && (int) $chosen !== (int) $item['product_id']) {
                    $manual[$item['key']] = (int) $chosen;
                }
            }
        }

        $result = app(ReadyStockPlanImporter::class)->apply($this->preview, $manual, auth()->user());

        $saved = collect($result)->whereIn('action', ['dibuat', 'diperbarui']);
        $skipped = collect($result)->where('action', 'dilewati');

        Notification::make()
            ->success()
            ->title($saved->count().' Rencana Masak tersimpan')
            ->body($saved->map(fn ($row) => $row['number'].' '.$row['action'].' ('.$row['menu_lines'].'/'.$row['lines'].' menu berresep)')->implode(', ')
                .($skipped->isNotEmpty() ? '. Dilewati: '.$skipped->pluck('message')->implode(' ') : '.'))
            ->persistent()
            ->send();

        $this->redirect(ReadyStockPlan::getUrl());
    }

    /** @return array<int, string> produk aktif untuk pilihan manual */
    public function getProductOptions(): array
    {
        return Product::query()->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, bool> produk => punya resep */
    public function getRecipeFlags(): array
    {
        return Product::query()->where('active', true)->pluck('recipe_id', 'id')->map(fn ($recipeId) => $recipeId !== null)->all();
    }
}
