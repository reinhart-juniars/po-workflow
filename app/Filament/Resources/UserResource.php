<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\{TextInput, Select, Fieldset};
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Gate;
use Filament\Forms\Components\CheckboxList;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
    return $form->schema([
            Fieldset::make('User')
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('email')->email()->required(),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->dehydrated(fn ($state) => filled($state))   // hanya simpan jika diisi
                        ->required(fn (string $context) => $context === 'create'),
                ])->columns(2),

            Fieldset::make('Roles')
                ->visible(fn($record) => Gate::allows('assignRoles', $record ?? app(\App\Models\User::class)))
                ->schema([
                    // Hanya izinkan pilih role staff operasional
                    Select::make('roles')
                        ->label('Assign Roles')
                        ->multiple()
                        ->preload()
                        ->relationship('roles', 'name')
                        ->options(fn () =>
                            Role::whereIn('name', ['admin','accounting','sales','production','delivery'])
                                ->pluck('name','id')
                        )
                        ->helperText('Hanya Owner yang dapat mengubah role.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('assign_roles')
                    ->label('Assign Roles')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn($record) => Gate::allows('assignRoles', $record))
                    ->authorize(fn ($record) => Gate::allows('assignRoles', $record))
                    ->form([
                        CheckboxList::make('roles')
                            ->options(
                                Role::whereIn('name',['admin','accounting','sales','production','delivery'])->pluck('name','name')->toArray()
                            )
                            ->columns(1)
                    ])
                    ->fillForm(function ($record) {
                        return ['roles' => $record->roles->pluck('name')->toArray()];
                    })
                    ->action(function ($record, array $data) {
                        // sinkron ke Spatie permission
                        $record->syncRoles($data['roles'] ?? []);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // -----------------------------------------------------
    // semua staff boleh lihat daftar user (atau Gate::allows('viewAny', static::getModel()))
    public static function canViewAny(): bool
    {
        return true; 
    }

    // hanya owner yang create user baru (opsional)
    public static function canCreate(): bool
    {
        return Gate::allows('assignRoles'); //, auth()->user());
    }

    // owner boleh edit siapa aja, non-owner hanya edit dirinya sendiri
    public static function canEdit(Model $record): bool
    {
        return Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('delete', $record);
    }
    // -----------------------------------------------------

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
