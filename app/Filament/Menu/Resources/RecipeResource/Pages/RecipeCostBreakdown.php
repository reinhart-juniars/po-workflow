<?php

namespace App\Filament\Menu\Resources\RecipeResource\Pages;

use App\Filament\Menu\Resources\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipeCostService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Analisa HPP sebuah resep.
 *
 * Dua pertanyaan dijawab di satu halaman: berapa biaya satu kali produksi, dan
 * berapa bahan yang dibutuhkan untuk sekian porsi. Keduanya memakai layanan
 * yang sama, jadi angka di kedua bagian tidak mungkin berbeda.
 *
 * Baris yang tidak bisa dihitung ditampilkan mencolok, bukan dilewati: HPP yang
 * diam-diam kekecilan akan terbawa sampai ke Laba Rugi tanpa ada yang curiga.
 */
class RecipeCostBreakdown extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = RecipeResource::class;

    protected static string $view = 'filament.resources.recipes.cost-breakdown';

    protected static ?string $title = 'Analisa HPP';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    protected ?array $cost = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill([
            'jumlah_produksi' => (float) $this->getRecipe()->yield_qty,
        ]);
    }

    public function getTitle(): string
    {
        return 'Analisa HPP — '.$this->getRecipe()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label('Edit Resep')
                ->icon('heroicon-m-pencil-square')
                ->url(fn () => RecipeResource::getUrl('edit', ['record' => $this->getRecord()])),

            Actions\Action::make('kembali')
                ->label('Daftar Resep')
                ->color('gray')
                ->url(RecipeResource::getUrl('index')),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('jumlah_produksi')
                    ->label('Hitung kebutuhan bahan untuk')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->live(onBlur: true)
                    ->suffix($this->getRecipe()->yield_unit)
                    ->helperText('Menelusuri sub-menu sampai ke bahan terdalam.'),
            ])
            ->statePath('data');
    }

    public function getRecipe(): Recipe
    {
        /** @var Recipe $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @return array<string, mixed> */
    public function getCost(): array
    {
        return $this->cost ??= app(RecipeCostService::class)->cost($this->getRecipe());
    }

    /** @return array<string, mixed> */
    public function getRequirements(): array
    {
        $quantity = (float) ($this->data['jumlah_produksi'] ?? 0);

        if ($quantity <= 0) {
            return ['rows' => collect(), 'total_cost' => 0.0, 'issues' => [], 'has_cycle' => false, 'has_unmatched' => false];
        }

        return app(RecipeCostService::class)->requirements($this->getRecipe(), $quantity);
    }

    /** Label sumber biaya sebuah baris. */
    public function sourceLabel(string $source): string
    {
        return match ($source) {
            'ingredient' => 'Bahan',
            'recipe' => 'Sub-Menu',
            'snapshot' => 'Harga Cadangan',
            default => 'Belum Tertaut',
        };
    }
}
