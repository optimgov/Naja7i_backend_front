<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\OwnAccountService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

final class MonDossier extends Page
{
    /** @var array<string, mixed> */
    public ?array $accountData = [];

    /** @var array<string, mixed> */
    public ?array $passwordData = [];

    protected string $view = 'filament.pages.mon-dossier';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 100;

    public static function getNavigationLabel(): string
    {
        return __('dossier.navigation');
    }

    public function getTitle(): string
    {
        return __('dossier.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->canAccessPanel(filament()->getCurrentOrDefaultPanel());
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->fillAccountForm();
        $this->passwordForm->fill();
    }

    public function accountForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('dossier.sections.contact'))->schema([
                    TextInput::make('email')
                        ->label(__('dossier.fields.email'))
                        ->email()
                        ->maxLength(255),
                    TextInput::make('current_password')
                        ->label(__('dossier.fields.current_password_for_email'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('current-password')
                        ->helperText(__('dossier.fields.current_password_for_email_help')),
                    TextInput::make('phone')
                        ->label(__('dossier.fields.phone'))
                        ->tel()
                        ->maxLength(32),
                    Select::make('locale')
                        ->label(__('dossier.fields.locale'))
                        ->options([
                            'fr' => __('dossier.locales.fr'),
                            'ar' => __('dossier.locales.ar'),
                        ])
                        ->required(),
                ])->columns(2),
                Section::make(__('dossier.sections.account'))->schema([
                    TextInput::make('email_verification')
                        ->label(__('dossier.fields.email_verification'))
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('phone_verification')
                        ->label(__('dossier.fields.phone_verification'))
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('status_label')
                        ->label(__('dossier.fields.status'))
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('roles_label')
                        ->label(__('dossier.fields.roles'))
                        ->disabled()
                        ->dehydrated(false),
                ])->columns(2),
            ])
            ->statePath('accountData');
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('dossier.sections.password'))->schema([
                    TextInput::make('current_password')
                        ->label(__('dossier.fields.current_password'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('current-password')
                        ->required(),
                    TextInput::make('password')
                        ->label(__('dossier.fields.password'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('new-password')
                        ->required(),
                    TextInput::make('password_confirmation')
                        ->label(__('dossier.fields.password_confirmation'))
                        ->password()
                        ->revealable(filament()->arePasswordsRevealable())
                        ->autocomplete('new-password')
                        ->required(),
                ])->columns(2),
            ])
            ->statePath('passwordData');
    }

    public function saveAccount(): void
    {
        $data = $this->accountForm->getState();

        try {
            app(OwnAccountService::class)->update($this->user(), $data);
        } catch (ValidationException $exception) {
            $this->relayErrors($exception, 'accountData');
        }

        $this->fillAccountForm();

        Notification::make()->success()->title(__('dossier.notifications.account_saved'))->send();
    }

    public function savePassword(): void
    {
        $data = $this->passwordForm->getState();

        try {
            app(OwnAccountService::class)->changePassword($this->user(), $data);
        } catch (ValidationException $exception) {
            $this->relayErrors($exception, 'passwordData');
        }

        $this->passwordForm->fill();

        Notification::make()->success()->title(__('dossier.notifications.password_saved'))->send();
    }

    private function fillAccountForm(): void
    {
        $user = $this->user()->refresh()->load('memberships.role');

        $this->accountForm->fill([
            'email' => $user->email,
            'current_password' => null,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'email_verification' => $this->verificationLabel($user->email_verified_at !== null),
            'phone_verification' => $this->verificationLabel($user->phone_verified_at !== null),
            'status_label' => __('dossier.statuses.'.$user->status),
            'roles_label' => $user->memberships
                ->pluck(app()->getLocale() === 'ar' ? 'role.label_ar' : 'role.label_fr')
                ->filter()
                ->join(', '),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && self::canAccess(), 403);

        return $user;
    }

    private function verificationLabel(bool $verified): string
    {
        return $verified ? __('dossier.verification.verified') : __('dossier.verification.unverified');
    }

    private function relayErrors(ValidationException $exception, string $statePath): never
    {
        throw ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => ["{$statePath}.{$field}" => $messages])
            ->all());
    }
}
