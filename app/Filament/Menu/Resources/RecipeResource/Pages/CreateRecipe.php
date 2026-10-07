<?php

namespace App\Filament\Menu\Resources\RecipeResource\Pages;

use App\Filament\Menu\Resources\RecipeResource;
use App\Models\Product;
use App\Services\MenuMatchSuggester;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    public function mount(): void
    {
        parent::mount();

        // Dibuka dari Pencocokan Menu ("Buat Resep"): nama resep diusulkan dari
        // nama produk tanpa token harga, dan produknya langsung terpilih.
        $product = Product::query()->find(request()->integer('produk'));

        // Ditulis langsung ke state (bukan form->fill) agar nilai bawaan
        // kolom lain yang sudah diisi parent::mount() tidak ikut hilang.
        if ($product) {
            $this->data['name'] = Str::title(app(MenuMatchSuggester::class)->normalize($product->name));
            $this->data['product_ids'] = [$product->id];
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('hpp', ['record' => $this->getRecord()]);
    }
}
