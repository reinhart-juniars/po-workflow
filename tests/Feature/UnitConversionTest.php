<?php

use App\Filament\Resources\InventoryItemResource;
use App\Services\UnitConverter;
use App\Support\Units\Unit;
use App\Support\Units\UnitDimension;

beforeEach(function () {
    $this->converter = app(UnitConverter::class);
});

it('mengonversi antar satuan berat dan volume', function () {
    expect($this->converter->convert(1, Unit::Kilogram, Unit::Gram))->toBe(1000.0)
        ->and($this->converter->convert(250, Unit::Gram, Unit::Kilogram))->toBe(0.25)
        ->and($this->converter->convert(1, Unit::Liter, Unit::Mililiter))->toBe(1000.0)
        ->and($this->converter->convert(2, Unit::SendokMakan, Unit::Mililiter))->toBe(30.0);
});

it('menolak konversi antar besaran yang berbeda', function () {
    // Kilogram ke liter butuh massa jenis bahan, yang tidak dimiliki sistem ini.
    expect(fn () => $this->converter->convert(1, Unit::Kilogram, Unit::Liter))
        ->toThrow(RuntimeException::class);

    // Positive control: konversi sebesaran tetap jalan, jadi yang menolak di
    // atas memang pagar besaran, bukan konverter yang rusak.
    expect($this->converter->convert(1, Unit::Kilogram, Unit::Gram))->toBe(1000.0);
});

it('menolak konversi antar satuan hitung yang berbeda', function () {
    // Satu ikat bukan kelipatan tetap dari satu pcs.
    expect(fn () => $this->converter->convert(1, Unit::Ikat, Unit::Pcs))
        ->toThrow(RuntimeException::class);

    // Satuan hitung yang sama tetap sah.
    expect($this->converter->convert(3, Unit::Butir, Unit::Butir))->toBe(3.0);
});

it('menghitung biaya baris resep dari harga bahan bersatuan lain', function () {
    // 250 gr tepung, harga Rp 12.000 per kg -> Rp 3.000.
    expect($this->converter->lineCost(250, Unit::Gram, 12000, Unit::Kilogram))->toBe(3000.0);

    // 150 ml minyak, harga Rp 20.000 per liter -> Rp 3.000.
    expect($this->converter->lineCost(150, Unit::Mililiter, 20000, Unit::Liter))->toBe(3000.0);

    // Satuan sama: tidak ada konversi, harga langsung dikali.
    expect($this->converter->lineCost(3, Unit::Butir, 2500, Unit::Butir))->toBe(7500.0);
});

it('mengenali singkatan dan salah ketik yang ada di data resep', function () {
    $harapan = [
        'gr' => Unit::Gram, 'GR' => Unit::Gram, 'gram' => Unit::Gram, 'g' => Unit::Gram,
        'kg' => Unit::Kilogram, 'Kilo' => Unit::Kilogram,
        'ml' => Unit::Mililiter, 'cc' => Unit::Mililiter,
        'liter' => Unit::Liter, 'ltr' => Unit::Liter,
        'pcs' => Unit::Pcs, 'pc' => Unit::Pcs, 'buah' => Unit::Pcs, 'biji' => Unit::Pcs,
        'butir' => Unit::Butir, 'ikat' => Unit::Ikat,
        'lbr' => Unit::Lembar, 'lembar' => Unit::Lembar,
        'ptg' => Unit::Potong, 'potong' => Unit::Potong, 'iris' => Unit::Potong,
        'btl' => Unit::Botol, 'klg' => Unit::Kaleng,
        'kotat' => Unit::Kotak, 'box' => Unit::Kotak,
        'btg' => Unit::Batang, 'jerigen' => Unit::Jirigen,
        ' kg ' => Unit::Kilogram, 'gr.' => Unit::Gram,
    ];

    foreach ($harapan as $teks => $unit) {
        expect(Unit::tryFromAlias($teks))->toBe($unit, "Satuan '{$teks}' tidak dikenali.");
    }
});

it('tidak menebak satuan yang tidak dikenal', function () {
    // Menebak satuan berarti menebak harga, jadi yang asing dibiarkan null
    // untuk dilaporkan, bukan dipaksa jadi satuan terdekat.
    foreach (['sdb', 'bnggl', '', null, 'entah apa'] as $teks) {
        expect(Unit::tryFromAlias($teks))->toBeNull();
    }
});

it('menutup kosakata satuan metrik yang dipakai di data resep', function () {
    // Satuan metrik adalah ~76% baris resep; semuanya wajib terkonversi otomatis.
    foreach (['gr', 'gram', 'kg', 'ml', 'liter'] as $teks) {
        $unit = Unit::tryFromAlias($teks);

        expect($unit)->not->toBeNull()
            ->and($unit->dimension()->isConvertible())->toBeTrue();
    }

    // Satuan hitung terbanyak wajib dikenali juga, meski tidak terkonversi.
    foreach (['pcs', 'pc', 'butir', 'ikat', 'lbr', 'porsi', 'pack'] as $teks) {
        $unit = Unit::tryFromAlias($teks);

        expect($unit)->not->toBeNull()
            ->and($unit->dimension())->toBe(UnitDimension::Count);
    }
});

it('mempertahankan satuan lama yang belum dikenal saat item disunting', function () {
    // Tiga item yang sudah ada memakai satuan "All", "Unit", dan "Pack".
    // Menyunting item lama tidak boleh diam-diam mengganti satuannya.
    $options = InventoryItemResource::unitOptions('All');

    expect($options)->toHaveKey('Satuan Lama')
        ->and($options['Satuan Lama'])->toHaveKey('All');

    // Positive control: satuan yang sudah dikenal tidak membuat grup itu muncul.
    expect(InventoryItemResource::unitOptions('kg'))->not->toHaveKey('Satuan Lama');
});
