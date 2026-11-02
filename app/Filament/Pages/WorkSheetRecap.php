<?php

namespace App\Filament\Pages;

use App\Exports\WorkSheetRecapExport;
use App\Models\DailyChore;
use App\Models\ProductionWorker;
use App\Services\ProductionDocumentService;
use App\Services\WorkSheetRecapService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Rekap Lembar Kerja: pekerjaan dapur satu hari dari semua SPK Produksi.
 *
 * Mengganti rekap manual di Excel. Isinya baris Lembar Kerja yang tanggal
 * kerjanya (tanggal SPK - H-n) jatuh pada hari itu, digabung bila pelaksana,
 * pekerjaan, dan objeknya sama (WorkSheetRecapService), lalu tugas umum
 * harian di bawahnya.
 */
class WorkSheetRecap extends Page
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'admin';

    protected static ?string $navigationGroup = 'Produksi';

    protected static ?string $navigationLabel = 'Rekap Lembar Kerja';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'rekap-lembar-kerja';

    protected static string $view = 'filament.pages.work-sheet-recap';

    protected static ?string $title = 'Rekap Lembar Kerja';

    /** Tanggal kerja (Y-m-d); ikut di URL supaya bisa dibagikan. */
    #[Url(as: 'tanggal')]
    public string $tanggal = '';

    #[Url(as: 'urut')]
    public string $urut = WorkSheetRecapService::SORT_MENU;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('production.view') ?? false;
    }

    public function mount(): void
    {
        $this->tanggal = $this->validDate($this->tanggal)?->toDateString() ?? today()->toDateString();

        if (! in_array($this->urut, [WorkSheetRecapService::SORT_MENU, WorkSheetRecapService::SORT_WORKER], true)) {
            $this->urut = WorkSheetRecapService::SORT_MENU;
        }
    }

    public function updatedTanggal(): void
    {
        $this->tanggal = $this->validDate($this->tanggal)?->toDateString() ?? today()->toDateString();
    }

    public function shiftDay(int $days): void
    {
        $this->tanggal = $this->date()->addDays(max(-1, min(1, $days)))->toDateString();
    }

    public function date(): Carbon
    {
        return $this->validDate($this->tanggal) ?? today();
    }

    /** @return array<string, mixed> */
    public function recap(): array
    {
        return app(WorkSheetRecapService::class)->forDate($this->date(), $this->urut);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new WorkSheetRecapExport($this->recap()),
                    'rekap-lembar-kerja-'.$this->date()->format('Ymd').'.xlsx'
                )),

            Action::make('cetak')
                ->label('Cetak')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->action(fn () => app(ProductionDocumentService::class)->workSheetRecapPdf($this->recap())),

            Action::make('tugasUmum')
                ->label('Tugas Umum')
                ->icon('heroicon-m-sparkles')
                ->authorize('production.manage')
                ->modalHeading('Tugas umum harian')
                ->modalDescription('Tugas yang bukan milik menu mana pun (cek stok, buang sampah, bersih-bersih). Muncul di bawah setiap rekap harian.')
                ->modalSubmitActionLabel('Simpan')
                ->fillForm(fn () => [
                    'chores' => DailyChore::query()->orderBy('sort_order')->orderBy('id')->get()
                        ->map(fn (DailyChore $chore) => $chore->only(['id', 'worker_name', 'task', 'is_active']))
                        ->all(),
                ])
                ->form([
                    Forms\Components\Repeater::make('chores')
                        ->label('')
                        ->reorderable()
                        ->addActionLabel('Tambah Tugas')
                        ->defaultItems(0)
                        ->schema([
                            Forms\Components\Hidden::make('id'),
                            Forms\Components\TextInput::make('task')
                                ->label('Tugas')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(3),
                            Forms\Components\TextInput::make('worker_name')
                                ->label('Pelaksana')
                                ->placeholder('mis. semua tim')
                                ->datalist(fn () => array_values(ProductionWorker::options()))
                                ->maxLength(255)
                                ->columnSpan(2),
                            Forms\Components\Toggle::make('is_active')
                                ->label('Aktif')
                                ->default(true)
                                ->inline(false),
                        ])
                        ->columns(6),
                ])
                ->action(function (array $data) {
                    $this->saveChores($data['chores'] ?? []);

                    Notification::make()->success()->title('Tugas umum tersimpan')->send();
                }),
        ];
    }

    /**
     * Simpan daftar tugas umum persis seperti di form: urutan mengikuti form,
     * yang dihapus dari form ikut dihapus.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    protected function saveChores(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            $keep = [];

            foreach (array_values($rows) as $i => $row) {
                $chore = DailyChore::query()->find($row['id'] ?? null) ?? new DailyChore;
                $chore->fill([
                    'sort_order' => $i + 1,
                    'task' => trim((string) $row['task']),
                    'worker_name' => filled($row['worker_name'] ?? null) ? trim((string) $row['worker_name']) : null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ])->save();

                $keep[] = $chore->id;
            }

            DailyChore::query()->whereNotIn('id', $keep)->delete();
        });
    }

    protected function validDate(?string $value): ?Carbon
    {
        if (blank($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
