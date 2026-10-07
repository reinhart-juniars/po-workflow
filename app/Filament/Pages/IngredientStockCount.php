<?php

namespace App\Filament\Pages;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\IngredientStockCountService;
use App\Services\InventoryLedgerService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

        $items = InventoryItem::query()
            ->ingredients()
            ->whereHas('parent', fn ($query) => $query->whereIn('category', InventoryItem::stockCategories()))
            ->when($this->data['bucket_id'] ?? null, fn ($query, $bucketId) => $query->where('parent_id', $bucketId))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'unit_price']);

        $ledger = app(InventoryLedgerService::class);
        $balances = $ledger->balances($items->pluck('id')->all(), $date);
        $tracked = InventoryMovement::query()->whereIn('inventory_item_id', $items->pluck('id'))->distinct()->pluck('inventory_item_id')->flip();

        return $items->map(fn (InventoryItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'unit' => $item->unit,
            'unit_price' => $item->unit_price === null ? null : (float) $item->unit_price,
            'tracked' => $tracked->has($item->id),
            'balance' => (float) ($balances[$item->id] ?? 0),
        ]);
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
