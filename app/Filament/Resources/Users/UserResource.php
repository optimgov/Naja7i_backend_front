<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

final class UserResource extends Resource
{
    public const PERMISSION_REQUISE = 'members.view';

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Personnes';

    protected static ?string $modelLabel = 'personne';

    protected static ?string $pluralModelLabel = 'personnes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('memberships')
            ->with(['memberships.role']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Compte')->schema([
                TextInput::make('email')->label('E-mail')->email()->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->rules(['nullable', 'required_without:phone'])
                    ->disabled(fn (): bool => ! self::canAdministerAccount()),
                TextInput::make('phone')->label('Téléphone (E.164)')->tel()->maxLength(32)
                    ->unique(ignoreRecord: true)
                    ->rules(['nullable', 'required_without:email', 'regex:/^\+[1-9][0-9]{7,14}$/'])
                    ->disabled(fn (): bool => ! self::canAdministerAccount()),
                Select::make('locale')->label('Langue')->options(['fr' => 'Français', 'ar' => 'العربية'])->required()->disabled(fn (): bool => ! self::canAdministerAccount()),
                Select::make('status')->label('État')->options([
                    'active' => 'Actif',
                    'suspended' => 'Suspendu',
                ])->required()->disabled(fn (): bool => ! self::canAdministerAccount()),
                TextInput::make('password')
                    ->label('Mot de passe temporaire')
                    ->password()
                    ->revealable()
                    ->required(fn (?User $record): bool => $record === null)
                    ->rule(Password::min(12))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visible(fn (?User $record): bool => $record === null)
                    ->helperText('À transmettre par un canal sûr. Aucun e-mail d’invitation n’est envoyé par le socle actuel.'),
            ])->columns(2),
            Section::make('Rôles dans ce tenant')->schema([
                CheckboxList::make('role_uuids')
                    ->label('Rôles')
                    ->options(fn (?User $record): array => self::roleOptions($record === null))
                    ->required(fn (?User $record): bool => $record === null)
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): string => $record?->is(auth()->user())
                        ? 'Vos propres rôles ne peuvent pas être modifiés.'
                        : 'Les rôles candidat ne sont pas attribuables lors de la création : le candidat doit accepter lui-même les actes juridiques.'),
            ])->visible(fn (): bool => auth()->user() !== null
                && app(PermissionResolver::class)->has(auth()->user(), 'roles.assign')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email')->label('E-mail')->searchable()->placeholder('—'),
            TextColumn::make('phone')->label('Téléphone')->searchable()->placeholder('—'),
            TextColumn::make('memberships.role.label_fr')->label('Rôles')->badge(),
            TextColumn::make('status')->label('État')->badge(),
            TextColumn::make('locale')->label('Langue')->badge(),
            TextColumn::make('email_verified_at')->label('E-mail vérifié')->dateTime('d/m/Y H:i')->placeholder('Non'),
            TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('État')->options([
                'active' => 'Actif', 'suspended' => 'Suspendu',
                'deletion_requested' => 'Suppression demandée', 'anonymized' => 'Anonymisé',
            ]),
            SelectFilter::make('role_uuid')->label('Rôle')
                ->options(fn (): array => self::roleOptions())
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, string $uuid): Builder => $query->whereHas(
                        'memberships.role',
                        fn (Builder $roleQuery): Builder => $roleQuery->where('uuid', $uuid),
                    ),
                )),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    private static function roleOptions(bool $staffOnly = false): array
    {
        $tenantId = app(TenantContext::class)->id();
        $query = Role::query()->availableIn($tenantId)->orderBy('label_fr');

        if ($tenantId !== TenantContext::PLATFORM_TENANT_ID) {
            $query->where(fn (Builder $q) => $q->where('tenant_id', $tenantId)->orWhere('is_staff', false));
        }

        if ($staffOnly) {
            $query->where('is_staff', true);
        }

        return $query->pluck('label_fr', 'uuid')->all();
    }

    private static function canAdministerAccount(): bool
    {
        return auth()->user() !== null
            && app(PermissionResolver::class)->has(auth()->user(), 'members.invite');
    }
}
