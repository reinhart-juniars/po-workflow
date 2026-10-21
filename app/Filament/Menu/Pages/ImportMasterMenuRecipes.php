<?php

namespace App\Filament\Menu\Pages;

use App\Services\MasterMenu\MasterMenuNewRecipeImporter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Tarik resep yang baru ditulis di aplikasi Master Menu Revamp ke 3S ONE.
 *
 * Tim menu mengunggah app.db dari PC mereka, melihat pratinjau, lalu
 * mengimpor. Hanya resep dan bahan yang belum ada yang ditambahkan; yang sudah
 * ada -- termasuk hasil kerja di 3S ONE -- tidak disentuh (lihat
 * MasterMenuNewRecipeImporter). Berkas disimpan di disk lokal selama
 * pratinjau dan dihapus begitu selesai atau dibatalkan.
 */
class ImportMasterMenuRecipes extends Page
{
    use \App\Filament\Concerns\PageInPanel;
    use WithFileUploads;

    protected static string $ownerPanel = 'menu';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?string $navigationGroup = 'Menu & Resep';

    protected static ?string $navigationLabel = 'Impor Resep MMR';

    protected static ?int $navigationSort = 17;

    protected static ?string $slug = 'impor-resep-mmr';

    protected static string $view = 'filament.pages.import-master-menu-recipes';

    protected static ?string $title = 'Impor Resep Baru dari Master Menu Revamp';

    /** Folder sementara di disk local untuk berkas yang sedang dipratinjau. */
    public const FOLDER = 'mmr-import';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $fileName = '';

    /** Kunci cache pratinjau; kosong = belum ada pratinjau. */
    public string $token = '';

    /** @var array<string, mixed>|null Ringkasan impor yang baru selesai. */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('recipe.manage') ?? false;
    }

    public function readFile(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate([
            'upload' => ['required', 'file', 'max:10240'],
        ], [
            'upload.required' => 'Pilih berkas app.db dulu.',
            'upload.max' => 'Berkas paling besar 10 MB.',
        ]);

        $this->discardFile();

        $stored = $this->upload->storeAs(self::FOLDER, Str::uuid().'.db', 'local');
        $path = Storage::disk('local')->path($stored);

        try {
            $preview = MasterMenuNewRecipeImporter::forFile($path)->run(dryRun: true);
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($stored);
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->token = (string) Str::uuid();
        Cache::put($this->cacheKey(), ['file' => $stored, 'preview' => $preview], now()->addHours(3));

        $this->fileName = $this->upload->getClientOriginalName();
        $this->upload = null;
        $this->result = null;
    }

    public function import(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->cached();

        if ($data === null || ! Storage::disk('local')->exists($data['file'])) {
            Notification::make()->warning()->title('Pratinjau kedaluwarsa')->body('Unggah ulang berkas app.db.')->send();
            $this->reset('token', 'fileName');

            return;
        }

        try {
            $result = MasterMenuNewRecipeImporter::forFile(Storage::disk('local')->path($data['file']))
                ->run(userId: auth()->id());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Impor gagal')->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->discardFile();
        $this->result = $result;

        Notification::make()->success()
            ->title(count($result['resep_baru']).' resep baru masuk')
            ->body(count($result['bahan_baru']).' bahan baru ikut ditambahkan. Resep yang sudah ada tidak diubah.')
            ->send();
    }

    public function cancel(): void
    {
        $this->discardFile();
    }

    /** @return array{file: string, preview: array<string, mixed>}|null */
    public function cached(): ?array
    {
        return $this->token === '' ? null : Cache::get($this->cacheKey());
    }

    protected function discardFile(): void
    {
        if ($data = $this->cached()) {
            Storage::disk('local')->delete($data['file']);
            Cache::forget($this->cacheKey());
        }

        $this->reset('token', 'fileName');
    }

    protected function cacheKey(): string
    {
        return 'mmr-import:'.auth()->id().':'.$this->token;
    }
}
