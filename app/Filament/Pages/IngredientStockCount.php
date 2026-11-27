<?php

namespace App\Filament\Pages;

use App\Exports\IngredientStockCountExport;
use App\Imports\IngredientStockCountImport;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\IngredientStockCountService;
use App\Services\InventoryLedgerService;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Opname Bahan: hitung fisik per bahan dalam kuantitas.
 *
 * Selisih terhadap saldo kartu stok diposting sebagai Barang Hilang / Barang
 * Temuan (IngredientStockCountService) sehingga muncul di Susut Bahan dan di
 * rincian HPP Laba Rugi. Berbeda dengan Stock Opname (nilai rupiah per
 * kategori) yang tetap dipakai untuk Neraca.
 */
class IngredientStockCount extends Page implements HasForms
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'admin';

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Opname Bahan';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'opname-bahan';

    protected static string $view = 'filament.pages.ingredient-stock-count';

    protected static ?string $title = 'Opname Bahan (Hitung Fisik per Bahan)';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<int|string, mixed> id bahan => hasil hitung fisik */
    public array $counts = [];

    /** Naik tiap import supaya baris grid dirender ulang dengan hitungan dari berkas. */
    public int $importVersion = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('inventory.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'count_date' => now()->toDateString(),
            'bucket_id' => null,
            'search' => null,
            'notes' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(4)->schema([
                    DatePicker::make('count_date')->label('Tanggal opname')->required()->native(false)
                        ->displayFormat('d/m/Y')->maxDate(now())->live(),
                    Select::make('bucket_id')->label('Kategori')->native(false)->placeholder('Semua')
                        ->options(fn () => InventoryItem::query()->buckets()->whereIn('category', InventoryItem::stockCategories())->orderBy('name')->pluck('name', 'id')->all())
                        ->live(),
                    TextInput::make('search')->label('Cari bahan')->live(debounce: 400),
                    TextInput::make('notes')->label('Catatan')->maxLength(150)->placeholder('mis. opname akhir bulan'),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * Bahan di bawah kategori stok beserta saldo kartu stok pada tanggal opname.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getRows(): Collection
    {
        $date = $this->countDate()->toDateString();
        $search = trim((string) ($this->data['search'] ?? ''));

        $items = static::stockIngredients()
            ->with('parent:id,name')
            ->when($this->data['bucket_id'] ?? null, fn ($query, $bucketId) => $query->where('parent_id', $bucketId))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'unit', 'unit_price']);

        $ledger = app(InventoryLedgerService::class);
        $balances = $ledger->balances($items->pluck('id')->all(), $date);
        $tracked = InventoryMovement::query()->whereIn('inventory_item_id', $items->pluck('id'))->distinct()->pluck('inventory_item_id')->flip();

        return $items->map(fn (InventoryItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->parent?->name,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price === null ? null : (float) $item->unit_price,
            'tracked' => $tracked->has($item->id),
            'balance' => (float) ($balances[$item->id] ?? 0),
        ]);
    }

    /** Bahan yang bisa diopname: bahan di bawah kategori stok (bahan baku & kemasan). */
    public static function stockIngredients(): Builder
    {
        return InventoryItem::query()
            ->ingredients()
            ->whereHas('parent', fn ($query) => $query->whereIn('category', InventoryItem::stockCategories()));
    }

    /**
     * Export: lembar cetak sesuai tanggal & filter yang sedang dibuka.
     * Import: hitung fisik dari lembar itu mengisi grid; simpannya tetap lewat
     * Simpan Opname supaya selisihnya dilihat dulu sebelum masuk kartu stok.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $date = $this->countDate();
                    $bucketId = $this->data['bucket_id'] ?? null;
                    $category = $bucketId ? (InventoryItem::query()->whereKey($bucketId)->value('name') ?? 'Semua') : 'Semua';
                    // Lembar cetak diurutkan per kategori supaya gudang menghitung per rak/area.
                    $rows = $this->getRows()->sortBy(fn (array $row) => mb_strtolower(($row['category'] ?? '').' '.$row['name']))->values();

                    return Excel::download(
                        new IngredientStockCountExport($rows, $date, $category),
                        'opname-bahan_'.$date->format('Ymd').'.xlsx',
                    );
                }),

            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import hasil hitung fisik')
                ->modalSubmitActionLabel('Isi ke form opname')
                ->form([
                    FileUpload::make('berkas')
                        ->label('Berkas Excel')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(5120)
                        ->helperText('Gunakan berkas hasil Export yang kolom Hitung Fisik-nya sudah diisi. Hasilnya mengisi form di halaman ini; '
                            .'periksa selisihnya lalu klik Simpan Opname. Belum ada yang tersimpan sampai tombol itu diklik.')
                        ->storeFiles(false),
                ])
                ->action(fn (array $data) => $this->importCounts($data['berkas'])),
        ];
    }

    /** @param  \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|string  $file */
    public function importCounts(mixed $file): void
    {
        abort_unless(static::canAccess(), 403);

        $path = is_string($file) ? $file : $file->getRealPath();
        $import = (new IngredientStockCountImport(static::stockIngredients()->pluck('id')->all()))->read($path);

        if ($import->hasErrors()) {
            $errors = $import->errors();
            Notification::make()
                ->danger()
                ->title('Import dibatalkan, form tidak diubah')
                ->body(implode("\n", array_slice($errors, 0, 6)).(count($errors) > 6 ? "\n…dan ".(count($errors) - 6).' masalah lain.' : ''))
                ->persistent()
                ->send();

            return;
        }

        // Tanggal opname ikut berkas (hari dihitung), asal tidak di masa depan.
        $date = $import->date();
        $dateNote = '';
        if ($date && $date->lte(today())) {
            $this->data['count_date'] = $date->toDateString();
            $dateNote = ' Tanggal opname diambil dari berkas: '.$date->format('d/m/Y').'.';
        } elseif ($date) {
            $dateNote = ' Tanggal di berkas ('.$date->format('d/m/Y').') di masa depan, jadi tanggal di form tidak diubah.';
        }

        // Filter dilepas supaya semua bahan yang terisi kelihatan di grid.
        $this->data['bucket_id'] = null;
        $this->data['search'] = null;
        $this->counts = $import->counts();
        $this->importVersion++;

        Notification::make()
            ->success()
            ->title(count($this->counts).' bahan terisi dari berkas')
            ->body('Periksa kolom Selisih, lalu klik Simpan Opname untuk mencatatnya ke kartu stok. '
                .$import->blank().' bahan dibiarkan kosong (tidak diubah).'.$dateNote)
            ->persistent()
            ->send();
    }

    public function save(): void
    {
        $this->form->validate();

        try {
            $summary = app(IngredientStockCountService::class)->record(
                $this->countDate(),
                $this->counts,
                $this->data['notes'] ?? null,
                auth()->id(),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Opname belum disimpan')->body($exception->getMessage())->send();

            return;
        }

        $rupiah = fn (float $value) => 'Rp '.number_format($value, 0, ',', '.');

        Notification::make()
            ->success()
            ->title('Opname bahan tersimpan')
            ->body(sprintf(
                '%d bahan hilang (%s), %d bahan temuan (%s), %d saldo awal baru, %d sesuai kartu stok.',
                $summary['hilang'],
                $rupiah($summary['hilang_value']),
                $summary['temuan'],
                $rupiah($summary['temuan_value']),
                $summary['saldo_awal'],
                $summary['sama'],
            ))
            ->send();

        $this->counts = [];
        $this->dispatch('opname-saved');
    }

    protected function countDate(): Carbon
    {
        return Carbon::parse($this->data['count_date'] ?? now());
    }
}
