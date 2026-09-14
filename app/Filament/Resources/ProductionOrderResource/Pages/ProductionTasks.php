<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Models\ProductionWorker;
use App\Services\ProductionOrderService;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Lembar kerja SPK Produksi: siapa mengerjakan apa untuk menu mana.
 *
 * Diisi dari template kerja tiap menu lalu disunting; tugas yang selesai
 * dicentang. Kuantitasnya teks bebas seperti di form kertas ("8+3,4 kg").
 */
class ProductionTasks extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = ProductionOrderResource::class;

    protected static string $view = 'filament.resources.production-orders.tasks';

    protected static ?string $title = 'Lembar Kerja';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Lembar Kerja — '.$this->getOrder()->number;
    }

    public function getOrder(): ProductionOrder
    {
        /** @var ProductionOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        $order = $this->getOrder();

        $menuOptions = $order->lines()->whereNotNull('recipe_id')->with('recipe')->get()
            ->mapWithKeys(fn ($line) => [$line->recipe_id => $line->displayName()])
            ->all();

        return $form
            ->schema([
                Repeater::make('tasks')
                    ->label('')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->reorderable()
                    ->addActionLabel('Tambah Pekerjaan')
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state) => trim(($state['task'] ?? '').' '.($state['object'] ?? '').' '.($state['quantity_text'] ?? '')) ?: 'Pekerjaan baru')
                    ->schema([
                        TextInput::make('task')
                            ->label('Pekerjaan')
                            ->placeholder('potong / goreng / rebus')
                            ->maxLength(255)
                            ->required(),

                        TextInput::make('object')
                            ->label('Objek')
                            ->placeholder('ayam, wortel, ...')
                            ->maxLength(255),

                        TextInput::make('quantity_text')
                            ->label('Jumlah')
                            ->placeholder('25 gr / 8+3,4 kg')
                            ->maxLength(100),

                        Select::make('recipe_id')
                            ->label('Untuk Menu')
                            ->options($menuOptions)
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn ($state, $set) => $set('menu_label', $menuOptions[$state] ?? null)),

                        \Filament\Forms\Components\Hidden::make('menu_label'),

                        Select::make('worker_name')
                            ->label('Pelaksana')
                            ->options(fn () => ProductionWorker::options())
                            ->searchable()
                            ->native(false),

                        Toggle::make('is_done')
                            ->label('Selesai')
                            ->inline(false),
                    ])
                    ->columns(6),
            ])
            ->model($order)
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can('production.manage'), 403);

        $this->form->getState();
        $this->form->saveRelationships();

        Notification::make()->success()->title('Lembar kerja tersimpan')->send();
    }

    protected function getHeaderActions(): array
    {
        $order = $this->getOrder();

        return [
            Actions\Action::make('salin')
                ->authorize('production.manage')
                ->label('Salin dari Template Menu')
                ->icon('heroicon-m-document-duplicate')
                ->color('gray')
                ->action(function () use ($order) {
                    $count = app(ProductionOrderService::class)->copyTasksFromRecipes($order);

                    Notification::make()
                        ->success()
                        ->title($count.' pekerjaan disalin')
                        ->body($count === 0 ? 'Menu di SPK ini belum punya template kerja, atau sudah disalin.' : null)
                        ->send();

                    $this->form->fill();
                }),

            Actions\Action::make('simpan')
                ->authorize('production.manage')
                ->label('Simpan')
                ->icon('heroicon-m-check')
                ->action(fn () => $this->save()),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('edit', ['record' => $order])),
        ];
    }
}
