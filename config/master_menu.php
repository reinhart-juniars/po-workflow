<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sumber data Master Menu Revamp
    |--------------------------------------------------------------------------
    |
    | Path absolut ke file SQLite (app.db) milik aplikasi Master Menu Revamp.
    | Dipakai read-only sebagai sumber migrasi & audit rekonsiliasi menuju
    | Modul Inventory Terpadu. Kosongkan kalau file-nya belum tersedia.
    |
    */
    'database' => env('MASTER_MENU_DB_PATH'),
];
