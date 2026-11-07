<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Exports\SpkPraWorkbook;
use App\Filament\Resources\ProductionOrderResource;
use App\Models\ProductionOrder;
use App\Models\ProductionTask;
use App\Models\SpkComponentRule;
use App\Services\MaterialBreakdownService;
use App\Services\ProductionDocumentService;
use App\Services\SpkPraService;
use Filament\Actions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Lembar SPK PRA: dokumen per menu yang dicetak atau dikirim gudang ke produksi.
 *
 * Tiap komponen bisa diatur langsung dari lembarnya. Setelan tampil diingat untuk
 * SPK berikutnya; jumlah khusus dan catatan hanya berlaku untuk SPK ini.
 */
class SpkPraSheet extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProductionOrderResource::class;

    protected static string $view = 'filament.resources.production-orders.spk-pra';

    protected static ?string $title = 'Lembar SPK PRA';

    /** Batas masuk akal jumlah khusus; menahan salah ketik, bukan aturan bisnis. */
    protected const MAX_QTY = 1000000;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Lembar SPK PRA';
    }

    public function getSubheading(): ?string
    {
        $order = $this->getOrder();

        return trim(($order->title ?: $order->number).' · dikerjakan '.$order->workDate()?->format('d/m/Y'));
    }

    public function getOrder(): ProductionOrder
    {
        /** @var ProductionOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @return array{order: ProductionOrder, menus: list<array<string, mixed>>} */
    public function getSheet(): array
    {
        return app(SpkPraService::class)->forProductionOrder($this->getOrder());
    }

    /**
     * Setelan lembar tidak mengubah jumlah yang dimasak di SPK, jadi gudang
     * (plan.manage) boleh mengaturnya juga pada SPK dari slot. SPK yang sudah
     * selesai atau batal terkunci.
     */
    public function canEditSheet(): bool
    {
        $user = auth()->user();

        return $this->getOrder()->isEditable()
            && ($user?->can('production.manage') || $user?->can('plan.manage'));
    }

    protected function getHeaderActions(): array
    {
        $order = $this->getOrder();

        return [
            Actions\Action::make('jadwal')
                ->authorize(fn () => $this->canEditSheet())
                ->label('Tanggal & Jam')
                ->icon('heroicon-m-calendar-days')
                ->color('gray')
                ->modalHeading('Tanggal & Jam Dikerjakan')
                ->modalSubmitActionLabel('Simpan')
                ->modalWidth('md')
                ->fillForm(fn () => [
                    'work_date' => $order->workDate()?->toDateString(),
                    'production_time' => $order->production_time,
                ])
                ->form([
                    DatePicker::make('work_date')
                        ->label('Dikerjakan Tanggal')
                        ->helperText('Tanggal SPK '.$order->production_date?->format('d/m/Y').'. Paling awal H-'.ProductionTask::MAX_DAYS_BEFORE.'.')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->minDate($order->production_date?->copy()->subDays(ProductionTask::MAX_DAYS_BEFORE))
                        ->maxDate($order->production_date),
                    TextInput::make('production_time')
                        ->label('Jam')
                        ->placeholder('08.30')
                        ->maxLength(10),
                ])
                ->action(function (array $data) use ($order) {
                    $order->update([
                        'work_date' => $data['work_date'],
                        'production_time' => $data['production_time'] ?? null,
                        'updated_by' => auth()->id(),
                    ]);

                    Notification::make()->success()->title('Tanggal dan jam tersimpan')->send();
                }),

            Actions\Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->action(fn () => Excel::download(
                    new SpkPraWorkbook($this->getSheet(), app(MaterialBreakdownService::class)->forProductionOrder($order)),
                    $this->fileBaseName().'.xlsx'
                )),

            Actions\Action::make('pdf')
                ->label('Cetak PDF')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->action(fn () => app(ProductionDocumentService::class)->spkPraPdf($this->getSheet(), $this->fileBaseName().'.pdf')),

            Actions\Action::make('kembali')
                ->label('SPK')
                ->color('gray')
                ->url(ProductionOrderResource::getUrl('edit', ['record' => $order])),
        ];
    }

    public function aturAction(): Actions\Action
    {
        return Actions\Action::make('atur')
            ->authorize(fn () => $this->canEditSheet())
            ->modalHeading(fn (array $arguments) => 'Atur Komponen: '.($this->findComponent($arguments)['default_name'] ?? ''))
            ->modalSubmitActionLabel('Simpan')
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments) => $this->componentFormData($this->findComponent($arguments)))
            ->form(fn (array $arguments) => $this->componentForm($this->findComponent($arguments)))
            ->action(function (array $data, array $arguments) {
                $component = $this->findComponent($arguments);

                if ($component === null) {
                    Notification::make()->danger()->title('Komponen sudah tidak ada di resep')->send();

                    return;
                }

                $this->saveComponent($component, $data);

                Notification::make()->success()->title('Tersimpan')->send();
            });
    }

    /**
     * @param  array<string, mixed>|null  $component
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function componentForm(?array $component): array
    {
        if ($component === null) {
            return [];
        }

        $isLoose = $component['type'] === 'loose';
        $choices = collect($component['choices']);
        $choiceLabel = fn (array $choice) => $choice['name'].($choice['qty'] !== null ? ' ('.$this->formatQty($choice['qty']).' '.$choice['unit'].')' : '');
        $componentQty = $this->formatQty((float) ($component['base_qty'] ?? 0)).' '.$component['unit'];

        return [
            Select::make('display')
                ->label('Tampilan')
                ->options(SpkComponentRule::displayOptions())
                ->required()
                ->native(false)
                ->selectablePlaceholder(false)
                ->live()
                ->hidden($isLoose),

            TextInput::make('label')
                ->label('Nama di lembar')
                ->placeholder($component['default_name'])
                ->maxLength(120)
                ->hidden(fn (Get $get) => $isLoose || $get('display') === SpkComponentRule::DISPLAY_HIDDEN),

            Select::make('basis_item')
                ->label('Jumlah yang ditampilkan')
                ->options($choices->where('qty', '!==', null)->mapWithKeys(fn ($choice) => [$choice['key'] => $choiceLabel($choice)])->all())
                ->placeholder('Jumlah komponen ('.$componentQty.')')
                ->native(false)
                ->visible(fn (Get $get) => ! $isLoose && $get('display') === SpkComponentRule::DISPLAY_SINGLE),

            CheckboxList::make('hidden_items')
                ->label('Bahan yang disembunyikan')
                ->options($choices->mapWithKeys(fn ($choice) => [$choice['key'] => $choiceLabel($choice)])->all())
                ->columns(1)
                ->visible(fn (Get $get) => $choices->isNotEmpty() && ($isLoose || $get('display') === SpkComponentRule::DISPLAY_DETAIL)),

            Radio::make('scope')
                ->label('Setelan di atas berlaku untuk')
                ->options(['global' => 'Semua menu', 'menu' => 'Menu ini saja'])
                ->required()
                ->inline()
                ->inlineLabel(false),

            TextInput::make('qty')
                ->label('Jumlah khusus SPK ini')
                ->helperText('Kosongkan untuk memakai hitungan resep ('.$componentQty.'). Isi komponen ikut menyesuaikan.')
                ->numeric()
                ->rules(['nullable', 'gt:0'])
                ->maxValue(self::MAX_QTY)
                ->suffix($component['unit'])
                ->hidden(fn (Get $get) => $isLoose || $get('display') === SpkComponentRule::DISPLAY_HIDDEN),

            TextInput::make('note')
                ->label('Catatan / Pelaksana SPK ini')
                ->placeholder('mis. 2 kotak')
                ->maxLength(255)
                ->hidden(fn (Get $get) => $isLoose || $get('display') === SpkComponentRule::DISPLAY_HIDDEN),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $component
     * @return array<string, mixed>
     */
    protected function componentFormData(?array $component): array
    {
        if ($component === null) {
            return [];
        }

        return [
            'display' => $component['display'],
            'label' => $component['label'],
            'basis_item' => $component['basis_item'] ? mb_strtolower(trim($component['basis_item'])) : null,
            'hidden_items' => collect($component['choices'])->where('hidden', true)->pluck('key')->all(),
            'scope' => $component['scope'] ?? ($component['type'] === 'loose' ? 'menu' : 'global'),
            'qty' => $component['custom'] ? (float) $component['qty'] : null,
            'note' => $component['note'],
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $data
     */
    protected function saveComponent(array $component, array $data): void
    {
        $service = app(SpkPraService::class);
        $choiceKeys = collect($component['choices'])->pluck('key')->all();
        $display = $component['type'] === 'loose' ? SpkComponentRule::DISPLAY_DETAIL : ($data['display'] ?? SpkComponentRule::DISPLAY_DETAIL);

        // Pilihan disaring lagi di sini: yang disimpan hanya nama yang memang ada di komponen.
        $service->saveRule($component['recipe_id'], $component['key'], ($data['scope'] ?? 'global') === 'global', [
            'display' => $display,
            'label' => $data['label'] ?? null,
            'basis_item' => in_array($data['basis_item'] ?? null, $choiceKeys, true) ? $data['basis_item'] : null,
            'hidden_items' => array_values(array_intersect($data['hidden_items'] ?? [], $choiceKeys)),
        ], auth()->id());

        if ($component['type'] !== 'loose') {
            $isHidden = $display === SpkComponentRule::DISPLAY_HIDDEN;

            $service->saveOverride(
                $this->getOrder(),
                $component['recipe_id'],
                $component['key'],
                $isHidden || blank($data['qty'] ?? null) ? null : (float) $data['qty'],
                $isHidden ? null : ($data['note'] ?? null),
                auth()->id(),
            );
        }
    }

    /**
     * Komponen dicari ulang dari lembar terkini, bukan dari isian browser, supaya
     * resep dan kunci yang dikirim tidak bisa menunjuk ke luar SPK ini.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    protected function findComponent(array $arguments): ?array
    {
        foreach ($this->getSheet()['menus'] as $menu) {
            if ($menu['recipe_id'] === null || (int) $menu['recipe_id'] !== (int) ($arguments['recipe'] ?? 0)) {
                continue;
            }

            foreach ([...$menu['components'], ...$menu['hidden']] as $component) {
                if ($component['key'] === ($arguments['key'] ?? null)) {
                    return $component;
                }
            }
        }

        return null;
    }

    protected function fileBaseName(): string
    {
        $order = $this->getOrder();
        $name = preg_replace('/[^\pL\pN \-_.]+/u', ' ', (string) ($order->title ?: 'SPK PRA '.$order->number));

        return Str::squish((string) $name);
    }

    protected function formatQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') ?: '0';
    }
}
