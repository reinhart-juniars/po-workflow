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

    /**
     * Pratinjau import: hitung fisik dari berkas (id bahan => angka) yang
     * belum disimpan. Kosong = tidak sedang mempratinjau.
     *
     * @var array<int|string, string>
     */
    public array $importCounts = [];

    /** Tanggal opname untuk pratinjau (dari berkas, atau form bila berkas tanpa tanggal). */
    public ?string $importDate = null;

    public string $importFile = '';

    public string $importNotes = '';

    /** Bahan di berkas yang Hitung Fisik-nya dikosongkan. */
    public int $importBlank = 0;

    /** Keterangan tanggal untuk pratinjau, mis. "dari berkas". */
    public string $importDateNote = '';

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

        return static::withLedger(
            static::stockIngredients()
                ->when($this->data['bucket_id'] ?? null, fn ($query, $bucketId) => $query->where('parent_id', $bucketId))
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%')),
            $date,
        );
    }

    /**
     * Baris bahan + saldo kartu stok pada tanggal tertentu.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function withLedger(Builder $query, string $date): Collection
    {
        $items = $query->with('parent:id,name')->orderBy('name')->get(['id', 'parent_id', 'name', 'unit', 'unit_price']);

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
     * Import: hitung fisik dari lembar itu tampil dulu sebagai pratinjau
     * (selisih & nilainya); kartu stok baru berubah setelah pratinjau disimpan.
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
                ->modalSubmitActionLabel('Lihat pratinjau')
                ->form([
                    FileUpload::make('berkas')
                        ->label('Berkas Excel')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(5120)
                        ->helperText('Gunakan berkas hasil Export yang kolom Hitung Fisik-nya sudah diisi. Isinya tampil dulu sebagai pratinjau '
                            .'(selisih dan nilainya); belum ada yang tersimpan sampai pratinjau disimpan.')
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
                ->title('Import dibatalkan, tidak ada yang berubah')
                ->body(implode("\n", array_slice($errors, 0, 6)).(count($errors) > 6 ? "\n…dan ".(count($errors) - 6).' masalah lain.' : ''))
                ->persistent()
                ->send();

            return;
        }

        // Tanggal opname ikut berkas (hari dihitung), asal tidak di masa depan.
        $date = $import->date();
        $formDate = $this->countDate()->toDateString();
        [$this->importDate, $this->importDateNote] = match (true) {
            $date !== null && $date->lte(today()) => [$date->toDateString(), 'dari berkas'],
            $date !== null => [$formDate, 'tanggal di berkas ('.$date->format('d/m/Y').') di masa depan, dipakai tanggal di form'],
            default => [$formDate, 'berkas tanpa tanggal, dipakai tanggal di form'],
        };

        $this->importCounts = $import->counts();
        $this->importBlank = $import->blank();
        $this->importFile = is_string($file) ? basename($file) : $file->getClientOriginalName();
        $this->importNotes = (string) ($this->data['notes'] ?? '');
    }

    /**
     * Isi pratinjau: bahan yang diisi di berkas, dengan saldo kartu stok pada
     * tanggal opname, selisih, dan nilainya -- sama dengan yang akan dicatat
     * IngredientStockCountService. Urut: hilang, temuan, saldo awal, sesuai.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, int|float>}
     */
    public function getImportPreview(): array
    {
        $rows = static::withLedger(static::stockIngredients()->whereIn('id', array_keys($this->importCounts)), (string) $this->importDate)
            ->map(function (array $row) {
                $counted = round((float) $this->importCounts[$row['id']], 4);
                $diff = $row['tracked'] ? round($counted - $row['balance'], 4) : null;
                $status = match (true) {
                    ! $row['tracked'] => $counted > 0 ? 'saldo_awal' : 'kosong',
                    $diff < -0.00005 => 'hilang',
                    $diff > 0.00005 => 'temuan',
                    default => 'sesuai',
                };

                return $row + [
                    'counted' => $counted,
                    'diff' => $diff,
                    'status' => $status,
                    // Nilai selisih (atau nilai saldo awal) dengan harga satuan bahan; null bila harga kosong.
                    'value' => $row['unit_price'] === null ? null : round(($diff ?? $counted) * $row['unit_price'], 2),
                ];
            })
            ->sortBy(fn (array $row) => array_search($row['status'], ['hilang', 'temuan', 'saldo_awal', 'sesuai', 'kosong'], true).'|'.mb_strtolower($row['name']))
            ->values();

        $sum = fn (string $status) => (float) $rows->where('status', $status)->sum(fn (array $row) => abs((float) $row['value']));

        return [
            'rows' => $rows,
            'summary' => [
                'hilang' => $rows->where('status', 'hilang')->count(),
                'hilang_value' => $sum('hilang'),
                'temuan' => $rows->where('status', 'temuan')->count(),
                'temuan_value' => $sum('temuan'),
                'saldo_awal' => $rows->where('status', 'saldo_awal')->count(),
                'sesuai' => $rows->whereIn('status', ['sesuai', 'kosong'])->count(),
                'tanpa_harga' => $rows->whereIn('status', ['hilang', 'temuan', 'saldo_awal'])->whereNull('unit_price')->count(),
            ],
        ];
    }

    /** Simpan hasil berkas yang sudah dipratinjau ke kartu stok. */
    public function saveImport(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->importCounts === []) {
            return;
        }

        $this->validate(['importNotes' => ['nullable', 'string', 'max:150']]);

        try {
            $summary = app(IngredientStockCountService::class)->record(
                Carbon::parse($this->importDate),
                $this->importCounts,
                filled($this->importNotes) ? $this->importNotes : 'import '.$this->importFile,
                auth()->id(),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title('Opname belum disimpan')->body($exception->getMessage())->send();

            return;
        }

        $this->notifySaved($summary);
        $this->cancelImport();
        $this->counts = [];
        $this->dispatch('opname-saved');
    }

    public function cancelImport(): void
    {
        $this->importCounts = [];
        $this->importDate = null;
        $this->importFile = '';
        $this->importNotes = '';
        $this->importBlank = 0;
        $this->importDateNote = '';
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

        $this->notifySaved($summary);
        $this->counts = [];
        $this->dispatch('opname-saved');
    }

    /** @param  array<string, int|float>  $summary  hasil IngredientStockCountService::record() */
    protected function notifySaved(array $summary): void
    {
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
    }

    protected function countDate(): Carbon
    {
        return Carbon::parse($this->data['count_date'] ?? now());
    }
}
