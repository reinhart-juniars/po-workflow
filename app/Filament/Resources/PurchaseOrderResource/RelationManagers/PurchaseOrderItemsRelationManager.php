<?php

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Components\{Repeater, Select, TextInput};
use App\Models\Product;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\Actions\Action as FormsAction;
use Filament\Tables\Actions\Action as TableAction;

class PurchaseOrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Items';

    public function form(Form $form): Form
        {
            // helper hitung subtotal
            $recalc = function (Get $get, Set $set) {
            $qty   = (int) ($get('qty') ?? 0);
            $price = (float) ($get('unit_price') ?? 0);
            $disc  = (float) ($get('discount_percent') ?? 0);
            $set('subtotal', round($qty * $price * (1 - $disc/100), 2));
        };     
        return $form->schema([
            Select::make('product_id')
                ->label('Produk')
                ->relationship('product', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true))
                ->preload()
                ->searchable()
                ->createOptionForm([
                    TextInput::make('name')->label('Nama Produk')->required(),
                    TextInput::make('sku')->label('Kode Produk')->required(),
                    TextInput::make('unit')->label('Satuan')->default('porsi'),
                    TextInput::make('base_price')
                        ->label('Harga Dasar')
                        ->numeric()
                        ->required()
                        ->afterStateUpdated(function ($state, callable $set) {
                            $price = \App\Models\Product::find($state)?->base_price ?? 0;
                            $set('price', $price);
                         }),
                ])
                ->createOptionAction(fn (FormsAction $action) =>
                    $action
                        ->modalHeading('Tambah Produk Baru')
                        ->modalSubmitActionLabel('Simpan Produk')
                        ->modalCancelActionLabel('Batal')
                        ->visible(fn () => Auth::user()?->hasAnyRole(['owner','admin','administrator']) ?? false)
)
                    // ✅ pagar server-side juga: kalau bukan owner/admin → 403
                ->createOptionUsing(function (array $data): int {
                    abort_unless(Auth::user()?->hasAnyRole(['owner','admin','administrator']), 403);
                    return Product::create($data)->getKey();
                })
                ->live() // ⬅️ supaya afterStateUpdated jalan setiap pilih produk
                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($recalc) {
                    $price = Product::find($state)?->base_price ?? 0;
                    $set('unit_price', $price);
                    // hitung ulang subtotal
                    $recalc($get,$set);
                })
                ->helperText('Pilih produk dari daftar, atau tambahkan produk baru langsung di sini.'),

            TextInput::make('qty')
                ->numeric()
                ->label('Jumlah')
                ->minValue(1)
                ->default(1)
                ->live(onBlur: false)
                ->afterStateUpdated(fn (Get $get, Set $set) => $recalc($get, $set))
                ->required(),

            // ⬇️ PENTING: nama field HARUS 'unit_price' (bukan 'price')
            TextInput::make('unit_price')
                ->label('Harga Satuan')
                ->numeric()
                ->required()
                ->live(onBlur: false)
                ->afterStateUpdated(fn (Get $get, Set $set) => $recalc($get, $set)),

            TextInput::make('discount_percent')
                ->label('Diskon (%)')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(100)
                ->live(onBlur: false)
                ->afterStateUpdated(fn (Get $get, Set $set) => $recalc($get, $set)),

            TextInput::make('subtotal')
                ->numeric()
                ->label('Subtotal')
                ->readOnly()
                ->dehydrated(true), // kirim ke server kalau kamu simpan kolom ini
                // ->afterStateUpdated(function ($state, callable $set, callable $get) {
                //     $qty = $get('qty') ?? 1;
                //     $price = $get('price') ?? 0;
                //     $discount = $get('discount') ?? 0;
                //     $set('subtotal', ($qty * $price) * (1 - $discount / 100));
                // }),
        ]);
    }
        
    Public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->label('Product'),
                Tables\Columns\TextColumn::make('custom_name')->label('Custom'),
                Tables\Columns\TextColumn::make('qty'),
                Tables\Columns\TextColumn::make('unit'),
                Tables\Columns\TextColumn::make('unit_price'),
                Tables\Columns\TextColumn::make('line_total'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addMultiple')
                    ->label('Tambah Banyak Item')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Tambah Banyak Item')
                    ->form([
                        Repeater::make('rows')
                            ->defaultItems(1)
                            ->cloneable()
                            ->addActionLabel('Tambah Baris')
                            ->schema([
                                Select::make('product_id')
                                    ->label('Produk')
                                    ->relationship('product','name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true))
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->required()->label('Nama Produk'),
                                        TextInput::make('sku')->required()->label('Kode Produk'),
                                        TextInput::make('unit')->default('porsi')->label('Satuan'),
                                        TextInput::make('base_price')->numeric()->required()->label('Harga Dasar'),
                                    ])
                                    ->createOptionAction(fn (FormsAction $action) =>
                                        $action
                                            ->modalHeading('Tambah Produk Baru')
                                            ->modalSubmitActionLabel('Simpan Produk')
                                            ->modalCancelActionLabel('Batal')
                                            ->visible(fn () => Auth::user()?->hasAnyRole(['owner','admin','administrator']) ?? false)
                                    )
                                    ->createOptionUsing(function (array $data): int {
                                        abort_unless(Auth::user()?->hasAnyRole(['owner','admin','administrator']), 403);
                                        return Product::create($data)->getKey();
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $price = Product::find($state)?->base_price ?? 0;
                                        $set('unit_price', $price);
                                        $qty  = (int) ($get('qty') ?? 0);
                                        $disc = (float) ($get('discount_percent') ?? 0);
                                        $set('subtotal', round($qty * $price * (1 - $disc/100), 2));
                                    }),

                                TextInput::make('qty')
                                    ->label('Qty')->numeric()->minValue(1)->default(1)
                                    ->live(onBlur: false)
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $qty   = (int) ($get('qty') ?? 0);
                                        $price = (float) ($get('unit_price') ?? 0);
                                        $disc  = (float) ($get('discount_percent') ?? 0);
                                        $set('subtotal', round($qty * $price * (1 - $disc/100), 2));
                                    })
                                    ->required(),

                                TextInput::make('unit_price')
                                    ->label('Harga Satuan')->numeric()->required()
                                    ->live(onBlur: false)
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $qty   = (int) ($get('qty') ?? 0);
                                        $price = (float) ($get('unit_price') ?? 0);
                                        $disc  = (float) ($get('discount_percent') ?? 0);
                                        $set('subtotal', round($qty * $price * (1 - $disc/100), 2));
                                    }),

                                TextInput::make('discount_percent')
                                    ->label('Diskon (%)')->numeric()->default(0)->minValue(0)->maxValue(100)
                                    ->live(onBlur: false)
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $qty   = (int) ($get('qty') ?? 0);
                                        $price = (float) ($get('unit_price') ?? 0);
                                        $disc  = (float) ($get('discount_percent') ?? 0);
                                        $set('subtotal', round($qty * $price * (1 - $disc/100), 2));
                                    }),

                                TextInput::make('subtotal')
                                    ->label('Subtotal')->numeric()->readOnly()->dehydrated(true),
                            ]),
                    ])
                    ->action(function (array $data) {
                        foreach ($data['rows'] ?? [] as $row) {
                            // Model hook di PurchaseOrderItem akan hitung ulang subtotal & fallback unit_price
                            $this->ownerRecord->items()->create([
                                'product_id'       => $row['product_id'],
                                'qty'              => $row['qty'],
                                'unit_price'       => $row['unit_price'],
                                'discount_percent' => $row['discount_percent'] ?? 0,
                                'subtotal'         => $row['subtotal'] ?? null,
                            ]);
                        }
                    }),
])
            ->bulkActions([ Tables\Actions\DeleteBulkAction::make() ]);
    }
}
