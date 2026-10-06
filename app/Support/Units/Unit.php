<?php

namespace App\Support\Units;

/**
 * Satuan yang dikenal sistem.
 *
 * Daftarnya disusun dari kosakata yang benar-benar dipakai di data resep
 * (11.595 baris) dan data bahan, bukan dari daftar teoretis: sekitar 76% baris
 * resep memakai satuan metrik (gr, ml, kg) yang bisa dikonversi otomatis,
 * sisanya satuan hitung seperti pcs, butir, dan ikat.
 *
 * Alias mencakup singkatan dan salah ketik yang memang ada di data (ptg, btl,
 * klg, kotat), supaya impor resep tidak berhenti hanya karena ejaan.
 */
enum Unit: string
{
    // Berat -- basis gram
    case Gram = 'gram';
    case Kilogram = 'kg';

    // Volume -- basis mililiter
    case Mililiter = 'ml';
    case Liter = 'liter';
    case SendokMakan = 'sdm';
    case SendokTeh = 'sdt';

    // Satuan hitung -- tidak saling terkonversi
    case Pcs = 'pcs';
    case Butir = 'butir';
    case Ikat = 'ikat';
    case Lembar = 'lembar';
    case Papan = 'papan';
    case Potong = 'potong';
    case Ekor = 'ekor';
    case Bungkus = 'bungkus';
    case Kotak = 'kotak';
    case Pack = 'pack';
    case Dus = 'dus';
    case Kaleng = 'kaleng';
    case Botol = 'botol';
    case Sachet = 'sachet';
    case Batang = 'batang';
    case Keranjang = 'keranjang';
    case Porsi = 'porsi';
    case Set = 'set';
    case Renteng = 'renteng';
    case Plastik = 'plastik';
    case Jirigen = 'jirigen';
    case Kelereng = 'kelereng';

    public function dimension(): UnitDimension
    {
        return match ($this) {
            self::Gram, self::Kilogram => UnitDimension::Mass,
            self::Mililiter, self::Liter, self::SendokMakan, self::SendokTeh => UnitDimension::Volume,
            default => UnitDimension::Count,
        };
    }

    /**
     * Nilai satu satuan ini dalam satuan basis besarannya (gram atau mililiter).
     *
     * Satuan hitung memakai 1 karena tidak punya pembanding tetap; angka itu
     * tidak pernah dipakai untuk konversi silang, hanya untuk identitas.
     */
    public function factorToBase(): float
    {
        return match ($this) {
            self::Gram => 1.0,
            self::Kilogram => 1000.0,
            self::Mililiter => 1.0,
            self::Liter => 1000.0,
            self::SendokMakan => 15.0,
            self::SendokTeh => 5.0,
            default => 1.0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'Gram (gr)',
            self::Kilogram => 'Kilogram (kg)',
            self::Mililiter => 'Mililiter (ml)',
            self::Liter => 'Liter',
            self::SendokMakan => 'Sendok Makan (15 ml)',
            self::SendokTeh => 'Sendok Teh (5 ml)',
            self::Pcs => 'Pcs',
            self::Butir => 'Butir',
            self::Ikat => 'Ikat',
            self::Lembar => 'Lembar',
            self::Papan => 'Papan',
            self::Potong => 'Potong',
            self::Ekor => 'Ekor',
            self::Bungkus => 'Bungkus',
            self::Kotak => 'Kotak',
            self::Pack => 'Pack',
            self::Dus => 'Dus',
            self::Kaleng => 'Kaleng',
            self::Botol => 'Botol',
            self::Sachet => 'Sachet',
            self::Batang => 'Batang',
            self::Keranjang => 'Keranjang',
            self::Porsi => 'Porsi',
            self::Set => 'Set',
            self::Renteng => 'Renteng',
            self::Plastik => 'Plastik',
            self::Jirigen => 'Jirigen',
            self::Kelereng => 'Kelereng (ukuran, mis. bawang)',
        };
    }

    /**
     * Ejaan lain yang menunjuk satuan ini.
     *
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Gram => ['gr', 'g', 'grm', 'grams'],
            self::Kilogram => ['kilo', 'kilogram', 'kgs'],
            self::Mililiter => ['mili', 'mililiter', 'cc'],
            self::Liter => ['l', 'lt', 'ltr', 'litre'],
            self::SendokMakan => ['sendok makan'],
            self::SendokTeh => ['sendok teh'],
            self::Pcs => ['pc', 'piece', 'pieces', 'buah', 'bh', 'biji', 'bj'],
            self::Butir => ['btr'],
            self::Ikat => ['ikt'],
            self::Lembar => ['lbr', 'lmbr'],
            self::Potong => ['ptg', 'iris'],
            self::Ekor => ['ekr'],
            self::Bungkus => ['bks'],
            // 'kotat' adalah salah ketik yang benar-benar ada di data resep.
            self::Kotak => ['kotat', 'box'],
            self::Pack => ['pak', 'pck', 'ball'],
            self::Dus => ['dos'],
            self::Kaleng => ['klg'],
            self::Botol => ['btl'],
            self::Sachet => ['saset', 'sct'],
            self::Batang => ['btg'],
            self::Keranjang => ['krj'],
            self::Porsi => ['prs'],
            self::Jirigen => ['jerigen'],
            default => [],
        };
    }

    /**
     * Kenali satuan dari teks bebas, termasuk singkatan dan salah ketiknya.
     *
     * Mengembalikan null bila tidak dikenal -- teks yang tidak dikenali sengaja
     * tidak ditebak menjadi satuan terdekat, karena menebak satuan berarti
     * menebak harga.
     */
    public static function tryFromAlias(?string $raw): ?self
    {
        $needle = mb_strtolower(trim((string) $raw));
        $needle = rtrim($needle, '.');
        $needle = preg_replace('/\s+/', ' ', $needle) ?? $needle;

        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $unit) {
            if ($needle === $unit->value || in_array($needle, $unit->aliases(), true)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * Ejaan baku untuk disimpan: "gr", "g", "grm" semuanya menjadi "gram".
     * Teks yang tidak dikenali dikembalikan apa adanya (dirapikan spasinya)
     * supaya satuan lepas tidak hilang diam-diam.
     */
    public static function canonical(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return $raw;
        }

        return self::tryFromAlias($raw)?->value ?? trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    }

    /**
     * Pilihan untuk dropdown, dikelompokkan per besaran.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $options = [];

        foreach (self::cases() as $unit) {
            $options[$unit->dimension()->label()][$unit->value] = $unit->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $unit) => [$unit->value => $unit->label()])
            ->all();
    }
}
