<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ViewExcelExport implements FromView
{
    public function __construct(
        protected string $viewName,
        protected array $data = []
    ) {
    }

    public function view(): View
    {
        return view($this->viewName, $this->data);
    }
}
