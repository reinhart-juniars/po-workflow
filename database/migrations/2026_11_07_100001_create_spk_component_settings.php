<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan Lembar SPK PRA.
 *
 * Berkas yang dikirim gudang ke produksi adalah hasil kurasi: tiap komponen menu
 * ditampilkan rinci, diringkas satu baris, atau dibuang. spk_component_rules
 * menyimpan keputusan itu supaya cukup dibuat sekali (recipe_id NULL = semua
 * menu, terisi = pengecualian satu menu). production_order_component_overrides
 * menyimpan yang hanya berlaku untuk satu SPK: jumlah khusus dan catatan.
 *
 * Isi awal diambil dari berkas SPK PRA 7 November 2026; menu yang namanya tidak
 * ditemukan dilewati tanpa gagal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spk_component_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('component_key');
            $table->string('display', 20)->default('detail');
            $table->string('label')->nullable();
            $table->string('qty_basis', 20)->default('component');
            $table->string('basis_item')->nullable();
            $table->json('hidden_items')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['recipe_id', 'component_key']);
            $table->index('component_key');
        });

        Schema::create('production_order_component_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('component_key');
            $table->decimal('qty', 14, 4)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['production_order_id', 'recipe_id', 'component_key'], 'po_component_override_unique');
        });

        foreach ($this->initialRules() as [$menu, $key, $attributes]) {
            $recipeId = null;

            if ($menu !== null) {
                $recipeId = DB::table('recipes')->where('name_norm', $menu)->value('id');

                if ($recipeId === null) {
                    continue;
                }
            }

            DB::table('spk_component_rules')->insert(array_merge(
                ['display' => 'detail', 'label' => null, 'qty_basis' => 'component', 'basis_item' => null, 'hidden_items' => null],
                $attributes,
                ['recipe_id' => $recipeId, 'component_key' => $key, 'created_at' => now(), 'updated_at' => now()],
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_component_overrides');
        Schema::dropIfExists('spk_component_rules');
    }

    /** @return list<array{0: ?string, 1: string, 2: array<string, mixed>}> [nama menu ternormalisasi, kunci komponen, isian] */
    private function initialRules(): array
    {
        $hidden = ['display' => 'hidden'];
        $single = ['display' => 'single'];
        $singleBy = fn (string $item, ?string $label = null) => [
            'display' => 'single', 'qty_basis' => 'ingredient', 'basis_item' => $item, 'label' => $label,
        ];
        $hide = fn (string ...$items) => ['hidden_items' => json_encode($items)];

        return [
            // Berlaku di semua menu.
            [null, 'kemasan', $hidden],
            [null, 'nasi putih', $hidden],
            [null, 'sambal bawang', $single],
            [null, 'sambel bawang', $single],
            [null, 'sambal bawang klip', $single + ['label' => 'Sambal Bawang']],
            [null, 'bayam krispi', $single],
            [null, 'keripik pangsit', $single],
            [null, 'mayonaise', $single],
            [null, 'chicken coin', $single],
            [null, 'tahu goreng lapak', $single],
            [null, 'ayam geprek', $singleBy('dada ayam fillet', 'Ayam Geprek Fillet')],
            [null, 'chicken korea', $singleBy('ayam fillet')],
            [null, 'dori goreng tepung', $singleBy('dori')],
            [null, 'ayam suwir laos', $singleBy('ayam dada fillet')],
            [null, 'acar', $hide('gula rose brand kun', 'garam 500gr', 'cuka dixie 650ml')],
            [null, 'saos mentai', $hide('lemon', 'putih telur', 'saos tomat delm rf 1000gr', 'saos chili delm rf 1000gr', 'cuka apel 250ml', 'mustard 245gr', 'gula cair', 'garam 500gr', 'minyak goreng')],

            // Pengecualian per menu.
            ['nasi gila', 'nasi putih', $single],
            ['nasi goreng merah telur elek2 an', 'nasi goreng', $hide('minyak tropical rf 2lt')],
            ['nasi chicken coin', 'salad chicken korea', $hide('mayonaise')],
            ['nasi campur telur bali', 'telur rebus goreng', $singleBy('telur @1kg isi 16butir', 'Telur Bali')],
            ['nasi campur telur bali', 'tahu goreng lapak', $singleBy('tahu', 'Tahu Bali')],
            ['nasi goreng merah telur elek2 an', '(tanpa bagian)', $hide('minyak tropical rf 2lt')],
            ['rice bowl chicken korea', 'saos korea', $hidden],
            ['nasi ayam goreng terasi', 'lalapan lapak', $hidden],
            ['nasi ayam goreng terasi', 'tahu goreng lapak', $hidden],
            ['nasi dori saos mentai', 'tepung mix geprek', $hidden],
        ];
    }
};
