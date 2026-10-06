<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Baca seluruh sheet sebuah berkas apa adanya lewat Excel::toArray(); tafsir
 * isinya diserahkan ke layanan pemanggil (mis. RecipeTaskImporter).
 */
class SheetsToArrayImport implements ToArray
{
    public function array(array $array): void {}
}
