<?php

namespace App\Filament\Pages;

use App\Exports\RecipePullExport;
use App\Models\Recipe;
use App\Services\MaterialBreakdownService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Tarik Resep: kebutuhan bahan satu atau beberapa menu untuk jumlah berapa pun,
 * tanpa membuat SPK Produksi (revisi user Inventory, 6 Nov 2026). Padanan
 * dokumen SPK PRA di Master Menu Revamp (MMR): gudang menarik resep di sana lalu
 * menempelkan hasilnya ke berkas Excel sendiri.
 *
 * Hanya kalkulator: tidak ada baris tersimpan dan stok tidak bergerak. Pilihan
 * disimpan di properti komponen, breakdown dihitung ulang saat render.
 */
class RecipePull extends Page implements HasForms
{
    use \App\Filament\Concerns\PageInPanel;
    use InteractsWithForms;

    protected static string $ownerPanel = 'admin';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Tarik Resep';

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'tarik-resep';

    protected static string $view = 'filament.pages.recipe-pull';

    protected static ?string $title = 'Tarik Resep';

    /** Batas masuk akal per baris; menahan salah ketik, bukan aturan bisnis. */
    protected const MAX_QTY = 1000000;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var list<array{recipe_id: int, qty: float}> Pilihan terakhir yang sudah lolos validasi. */
    public array $pulled = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Hitung kebutuhan bahan tanpa membuat SPK. Tidak ada yang disimpan dan stok tidak berubah.';
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Repeater::make('lines')
                    ->label('Menu')
                    ->addActionLabel('Tambah menu')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->reorderable(false)
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        Select::make('recipe_id')
                            ->label('Menu / Sub-Menu')
                            ->options(fn () => $this->recipeOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->native(false)
                            ->columnSpan(['md' => 3]),

                        TextInput::make('qty')
                            ->label('Jumlah')
                            ->placeholder('mis. 20')
                            ->numeric()
                            ->required()
                            ->rules(['gt:0'])
                            ->maxValue(self::MAX_QTY)
                            ->suffix(fn (Get $get) => $this->yieldUnit($get('recipe_id'))),
                    ]),
            ])
            ->statePath('data');
    }

    /** Validasi form, lalu tampilkan hasilnya. */
    public function calculate(): void
    {
        $this->pulled = collect($this->form->getState()['lines'])
            ->map(fn (array $line) => ['recipe_id' => (int) $line['recipe_id'], 'qty' => (float) $line['qty']])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function getBreakdown(): ?array
    {
        return $this->pulled === [] ? null : app(MaterialBreakdownService::class)->forRecipes($this->pulled);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->pulled !== [])
                ->action(fn () => Excel::download(
                    new RecipePullExport($this->getBreakdown()),
                    'tarik-resep-'.now()->format('Ymd_His').'.xlsx'
                )),
        ];
    }

    /** @return array<string, array<int, string>> Resep aktif, dikelompokkan per jenis. */
    protected function recipeOptions(): array
    {
        $recipes = Recipe::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'jenis']);

        return [
            'Menu Utama' => $recipes->where('jenis', '!=', Recipe::JENIS_SUB)->pluck('name', 'id')->all(),
            'Sub-Menu' => $recipes->where('jenis', Recipe::JENIS_SUB)->pluck('name', 'id')->all(),
        ];
    }

    protected function yieldUnit(mixed $recipeId): ?string
    {
        return $recipeId ? Recipe::query()->whereKey($recipeId)->value('yield_unit') : null;
    }
}
