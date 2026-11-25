<?php

namespace App\Policies;

/**
 * Master Satuan dipegang gudang: siapa pun yang boleh mengelola item
 * inventaris boleh menambah satuan untuknya.
 */
class CustomUnitPolicy extends ModulePolicy
{
    protected string $module = 'inventory';
}
