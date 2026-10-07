<?php

namespace App\Filament\Menu\Pages;

use App\Exports\RecipeTasksExport;
use App\Imports\SheetsToArrayImport;
use App\Models\ProductionTask;
use App\Models\ProductionWorker;
use App\Models\Recipe;
use App\Models\RecipeTask;
use App\Services\RecipeTaskImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Pekerjaan Menu: template kerja paten per menu (apa yang dikerjakan, objek,
 * jumlah, PIC bawaan) -- padanan halaman Pekerjaan Menu di Master Menu.
 * Template disalin ke Lembar Kerja tiap SPK Produksi yang memasak menunya.
 * Isinya sama dengan bagian "Template Kerja" di form resep; halaman ini
 * untuk mengurus semuanya di satu tempat dan lewat Excel.
 */
class RecipeTaskTemplates extends Page implements HasTable
{
    use \App\Filament\Concerns\PageInPanel;

    protected static string $ownerPanel = 'menu';

    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Menu & Resep';

    protected static ?string $navigationLabel = 'Pekerjaan Menu';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'pekerjaan-menu';

    protected static string $view = 'filament.pages.recipe-task-templates';

    protected static ?string $title = 'Pekerjaan Menu';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.view') ?? false;
    }

    /** @return array{menus: int, with_template: int, tasks: int, workers: int} */
    public function getSummary(): array
    {
        return [
            'menus' => Recipe::query()->where('is_active', true)->count(),
            'with_template' => Recipe::query()->where('is_active', true)->has('tasks')->count(),
            'tasks' => RecipeTask::query()->count(),
            'workers' => ProductionWorker::query()->where('is_active', true)->count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(new RecipeTasksExport, 'pekerjaan-menu-'.now()->format('Ymd_His').'.xlsx')),

            Actions\Action::make('import')
                ->label('Import Excel')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('gray')
                ->authorize('recipe.manage')
                ->form([
                    Forms\Components\FileUpload::make('berkas')
                        ->label('Berkas Excel')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->helperText(
                            'Bisa berkas Export halaman ini, export Pekerjaan Menu dari Master Menu, atau format RINCIAN PRODUKSI '
                            .'(kolom Menu + Proses/Apa yang Dikerjakan). Template menu yang ada di berkas DIGANTI seluruhnya; '
                            .'menu yang tidak ada di berkas tidak berubah.'
                        )
                        ->storeFiles(false),
                ])
                ->action(function (array $data) {
                    try {
                        $result = app(RecipeTaskImporter::class)->import(Excel::toArray(new SheetsToArrayImport, $data['berkas']));
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Import gagal')->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    $notes = array_filter([
                        $result['unmatched'] !== [] ? count($result['unmatched']).' menu tidak ditemukan: '.implode(', ', array_slice($result['unmatched'], 0, 15)).(count($result['unmatched']) > 15 ? ', …' : '') : null,
                        $result['duplicates'] !== [] ? 'Dilewati (dua nama ke resep yang sama): '.implode(', ', $result['duplicates']) : null,
                    ]);

                    Notification::make()
                        ->{$notes === [] ? 'success' : 'warning'}()
                        ->title('Import selesai')
                        ->body($result['menus_matched'].' menu diperbarui, '.$result['tasks_inserted'].' pekerjaan, '
                            .$result['workers_created'].' pelaksana baru.'.($notes ? "\n".implode("\n", $notes) : ''))
                        ->persistent($notes !== [])
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Recipe::query()->where('is_active', true)->withCount('tasks'))
            ->columns([
                TextColumn::make('name')
                    ->label('Menu')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Recipe $record) => $record->kategori),

                TextColumn::make('jenis')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (?string $state) => $state === Recipe::JENIS_SUB ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?string $state) => Recipe::jenisOptions()[$state] ?? $state),

                TextColumn::make('tasks_count')
                    ->label('Pekerjaan')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('tasks')
                    ->label('Template')
                    ->state(fn (Recipe $record) => $record->tasks
                        ->map(fn (RecipeTask $task) => (trim($task->task.' '.$task->object.' '.$task->quantity_text) ?: '(baris kosong)').($task->pic ? ' — '.$task->pic : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('Belum ada template'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')
                    ->label('Jenis')
                    ->options(Recipe::jenisOptions()),

                Tables\Filters\TernaryFilter::make('punya_template')
                    ->label('Template')
                    ->placeholder('Semua')
                    ->trueLabel('Sudah ada')
                    ->falseLabel('Belum ada')
                    ->queries(
                        true: fn ($query) => $query->has('tasks'),
                        false: fn ($query) => $query->doesntHave('tasks'),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Atur Pekerjaan')
                    ->modalHeading(fn (Recipe $record) => 'Pekerjaan Menu — '.$record->name)
                    ->form([
                        Forms\Components\Repeater::make('tasks')
                            ->label('')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->defaultItems(0)
                            ->addActionLabel('Tambah Pekerjaan')
                            ->schema([
                                Forms\Components\TextInput::make('task')->label('Apa yang Dikerjakan')->placeholder('potong / goreng')
                                    ->datalist(fn () => ProductionTask::taskOptions())->required()->maxLength(255),
                                Forms\Components\TextInput::make('object')->label('Objek')->placeholder('ayam / wortel')->maxLength(255),
                                Forms\Components\TextInput::make('quantity_text')->label('Jumlah / Porsi')->placeholder('25 gr')->maxLength(100),
                                Forms\Components\TextInput::make('pic')->label('PIC (bawaan)')->placeholder('mia / tini')
                                    ->datalist(fn () => array_values(ProductionWorker::options()))->maxLength(255),
                            ])
                            ->columns(4),
                    ]),
            ])
            ->defaultSort('name');
    }
}
