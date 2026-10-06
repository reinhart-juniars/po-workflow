<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductionOrderResource\Pages;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Models\Requisition;
use App\Models\Spk;
use App\Services\ProductionOrderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * SPK Produksi: daftar menu yang harus dimasak pada satu waktu produksi.
 *
 * Barisnya lahir dari PO yang sudah ada (lewat slot SPK po-workflow) atau
 * ditambah manual. Dari sini bercabang Form Kebutuhan, Lembar Kerja, dan
 * Plating -- semuanya membaca baris yang sama.
 */
class ProductionOrderResource extends Resource
{
    protected static ?string $model = ProductionOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'SPK Produksi';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'SPK Produksi';

    protected static ?string $pluralModelLabel = 'SPK Produksi';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('SPK Produksi')
                    ->schema([
                        Forms\Components\TextInput::make('number')
                            ->label('Nomor')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Otomatis saat disimpan'),

                        Forms\Components\TextInput::make('title')
                            ->label('Judul')
                            ->maxLength(255)
                            ->placeholder('mis. SPK PRA 9 Juni'),

                        Forms\Components\DatePicker::make('production_date')
                            ->label('Tanggal Produksi')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        Forms\Components\TextInput::make('production_time')
                            ->label('Jam Mulai')
                            ->maxLength(10)
                            ->placeholder('08.30'),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options(ProductionOrder::statusOptions())
                            ->disabled()
                            ->dehydrated(false)
                            ->default(ProductionOrder::STATUS_DRAFT),

                        Forms\Components\Placeholder::make('spk')
                            ->label('Slot SPK & PO')
                            ->content(function (?ProductionOrder $record) {
                                $spk = $record?->spk;

                                if (! $spk) {
                                    return 'Disusun manual';
                                }

                                // Rantai Admin -> Produksi: nomor PO di slot ini
                                // ditautkan balik ke halaman PO di Admin App.
                                $links = $spk->purchaseOrders->map(fn ($po) => '<a href="'.e(route('adminapp.orders.show', $po)).'" class="text-primary-600 underline underline-offset-2">'.e($po->po_number).'</a>')->implode(', ');

                                return new \Illuminate\Support\HtmlString(e($spk->spk_code).($links !== '' ? ' &middot; PO: '.$links : ' &middot; tanpa PO'));
                            }),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Menu yang Dimasak')
                    ->description('Baris dari PO disegarkan lewat tombol "Segarkan dari PO"; baris manual boleh ditambah kapan saja selama SPK belum ditutup.')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->label('')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->addActionLabel('Tambah Baris')
                            ->defaultItems(0)
                            ->disabled(fn (?ProductionOrder $record) => $record !== null && ! $record->isEditable())
                            ->itemLabel(fn (array $state) => trim(($state['label'] ?? '').' — '.number_format((float) ($state['qty'] ?? 0), 0, ',', '.').' '.($state['unit'] ?? '')))
                            ->schema([
                                Forms\Components\Select::make('kind')
                                    ->label('Jenis')
                                    ->options(ProductionOrderLine::kindOptions())
                                    ->default(ProductionOrderLine::KIND_MENU)
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\Select::make('recipe_id')
                                    ->label('Resep')
                                    ->options(fn () => RecipeResource::subRecipeOptions())
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get) => $get('kind') === ProductionOrderLine::KIND_MENU)
                                    ->required(fn (Get $get) => $get('kind') === ProductionOrderLine::KIND_MENU)
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        if ($state && blank($get('label'))) {
                                            $set('label', (string) \App\Models\Recipe::query()->whereKey($state)->value('name'));
                                        }
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('label')
                                    ->label('Nama / Instruksi')
                                    ->maxLength(255)
                                    ->required(fn (Get $get) => $get('kind') === ProductionOrderLine::KIND_MANUAL)
                                    ->columnSpan(fn (Get $get) => $get('kind') === ProductionOrderLine::KIND_MENU ? 2 : 4),

                                // Jumlah porsi selalu bilangan bulat: kolom DB decimal(15,4)
                                // (warisan) jadi nilai lama "20.0000" dibulatkan saat tampil,
                                // dan input menolak pecahan (3,14 porsi tidak masuk akal).
                                Forms\Components\TextInput::make('qty')
                                    ->label('Jumlah')
                                    ->integer()
                                    ->minValue(1)
                                    ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round((float) $state))
                                    ->default(1)
                                    ->required(),

                                Forms\Components\TextInput::make('unit')
                                    ->label('Satuan')
                                    ->maxLength(30)
                                    ->default('porsi'),

                                Forms\Components\TextInput::make('remark')
                                    ->label('Keterangan')
                                    ->maxLength(255)
                                    ->columnSpan(2),

                                Forms\Components\Hidden::make('source')
                                    ->default(ProductionOrderLine::SOURCE_MANUAL),
                            ])
                            ->columns(6),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (ProductionOrder $record) => $record->title),

                TextColumn::make('production_date')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (ProductionOrder $record) => $record->production_time),

                TextColumn::make('lines_count')
                    ->label('Menu')
                    ->counts('lines')
                    ->alignRight(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ProductionOrder::statusOptions()[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        ProductionOrder::STATUS_COMPLETED => 'success',
                        ProductionOrder::STATUS_PLANNED => 'info',
                        ProductionOrder::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('requisition.status')
                    ->label('Form Kebutuhan')
                    ->badge()
                    ->placeholder('Belum disusun')
                    ->formatStateUsing(fn (?string $state) => Requisition::statusOptions()[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        Requisition::STATUS_CHECKED => 'success',
                        Requisition::STATUS_APPROVED => 'info',
                        default => 'warning',
                    }),

                TextColumn::make('spk.spk_code')
                    ->label('Slot SPK')
                    ->placeholder('Manual')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(ProductionOrder::statusOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('kebutuhan')
                    ->label('Form Kebutuhan')
                    ->icon('heroicon-m-clipboard-document-list')
                    ->color('gray')
                    ->url(fn (ProductionOrder $record) => static::getUrl('kebutuhan', ['record' => $record])),

                Tables\Actions\EditAction::make()->label('Edit'),
            ])
            ->defaultSort('production_date', 'desc');
    }

    /**
     * Aksi "Buat dari slot SPK": memilih slot jadwal lalu menyusun barisnya
     * dari item PO. Dipakai di daftar dan bisa diulang untuk menyegarkan.
     */
    public static function generateFromSpkAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('dari_spk')
            ->authorize('production.manage')
            ->label('Buat dari Slot SPK')
            ->icon('heroicon-m-arrow-path')
            ->form([
                Forms\Components\Select::make('spk_id')
                    ->label('Slot SPK po-workflow')
                    ->options(fn () => Spk::query()
                        ->orderByDesc('scheduled_at')
                        ->limit(200)
                        ->get()
                        ->mapWithKeys(fn (Spk $spk) => [$spk->id => $spk->spk_code.' — '.$spk->scheduled_at?->format('d/m/Y H:i').' ('.$spk->slot_type.')'])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Item PO pada slot ini menjadi baris menu lewat resep produknya. Produk tanpa resep tetap masuk sebagai baris manual yang ditandai.'),
            ])
            ->action(function (array $data) {
                try {
                    $order = app(ProductionOrderService::class)->generateFromSpk(Spk::query()->findOrFail($data['spk_id']), auth()->id());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Gagal menyusun SPK Produksi')->body($e->getMessage())->send();

                    return;
                }

                $manual = $order->lines->where('kind', ProductionOrderLine::KIND_MANUAL)->where('source', ProductionOrderLine::SOURCE_PO)->count();

                Notification::make()
                    ->success()
                    ->title($order->number.' tersusun')
                    ->body($order->lines->count().' baris.'.($manual > 0 ? " {$manual} produk belum punya resep dan tidak ikut dihitung kebutuhannya." : ''))
                    ->send();

                return redirect(static::getUrl('edit', ['record' => $order]));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionOrders::route('/'),
            'create' => Pages\CreateProductionOrder::route('/create'),
            'kebutuhan' => Pages\RequisitionForm::route('/{record}/kebutuhan'),
            'pekerjaan' => Pages\ProductionTasks::route('/{record}/pekerjaan'),
            'plating' => Pages\PlatingSheet::route('/{record}/plating'),
            'bahan' => Pages\MaterialBreakdown::route('/{record}/bahan'),
            'edit' => Pages\EditProductionOrder::route('/{record}/edit'),
        ];
    }
}
