<?php

namespace App\Filament\Pages;

use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Pengaturan aplikasi Inventory (resep/HPP, form kebutuhan, penomoran) --
 * bukan pengaturan seluruh sistem. Formnya dibangun dari SettingRegistry,
 * jadi pengaturan baru cukup didaftarkan di sana. Kunci disimpan sebagai state
 * form dengan titik diganti '__' karena Livewire membaca titik sebagai path.
 */
class ModuleSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Pengaturan Inventory';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'pengaturan-inventory';

    protected static string $view = 'filament.pages.module-settings';

    protected static ?string $title = 'Pengaturan Inventory';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(self::toState(app(Settings::class)->all()));
    }

    public function form(Form $form): Form
    {
        $sections = [];

        foreach (SettingRegistry::grouped() as $group => $keys) {
            $sections[] = Section::make($group)
                ->schema(array_map(fn (string $key) => self::field($key), $keys))
                ->columns(2);
        }

        return $form->schema($sections)->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('simpan')
                ->label('Simpan Pengaturan')
                ->icon('heroicon-m-check')
                ->action(fn () => $this->save()),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $values = self::fromState($this->form->getState());

        try {
            app(Settings::class)->setMany($values, auth()->id());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Pengaturan tidak tersimpan')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Pengaturan tersimpan')->send();
        $this->form->fill(self::toState(app(Settings::class)->all()));
    }

    protected static function field(string $key): TextInput|Toggle|Select|DatePicker
    {
        $definition = SettingRegistry::definition($key);
        $name = self::stateKey($key);

        $field = match ($definition['type']) {
            SettingRegistry::TYPE_BOOL => Toggle::make($name)->inline(false),
            SettingRegistry::TYPE_SELECT => Select::make($name)->options($definition['options'] ?? [])->required()->native(false),
            SettingRegistry::TYPE_PERCENT => TextInput::make($name)->numeric()->suffix('%')->required()->step('any')
                ->minValue($definition['min'] ?? 0)->maxValue($definition['max'] ?? 1000),
            SettingRegistry::TYPE_INT => TextInput::make($name)->numeric()->integer()->required()
                ->minValue($definition['min'] ?? 0)->maxValue($definition['max'] ?? PHP_INT_MAX),
            SettingRegistry::TYPE_DATE => DatePicker::make($name)->required()->native(false)->displayFormat('d/m/Y'),
            default => TextInput::make($name)->required()->maxLength(20),
        };

        return $field->label($definition['label'])->helperText($definition['help'] ?? null);
    }

    /** @param  array<string, mixed>  $values */
    public static function toState(array $values): array
    {
        $state = [];

        foreach ($values as $key => $value) {
            $state[self::stateKey($key)] = $value;
        }

        return $state;
    }

    /** @param  array<string, mixed>  $state */
    public static function fromState(array $state): array
    {
        $values = [];

        foreach ($state as $name => $value) {
            $key = str_replace('__', '.', $name);

            if (SettingRegistry::has($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    public static function stateKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
