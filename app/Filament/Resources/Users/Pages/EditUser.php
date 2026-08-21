<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AccountAdministrationService;
use App\Services\PermissionResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        $data['role_uuids'] = $user->memberships()->with('role')->get()->pluck('role.uuid')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var User $record */
        $record = $record;
        $service = app(AccountAdministrationService::class);
        if (app(PermissionResolver::class)->has($actor, 'members.invite')) {
            $service->update($actor, $record, $data);
        }

        if (app(PermissionResolver::class)->has($actor, 'roles.assign') && array_key_exists('role_uuids', $data)) {
            $service->syncRoles($actor, $record, $data['role_uuids']);
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reinviter')
                ->label('Renvoyer l’invitation')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    /** @var User|null $actor */
                    $actor = auth()->user();

                    return $actor !== null
                        && app(PermissionResolver::class)->has($actor, 'members.invite')
                        && $this->record->password === null
                        && ! $this->record->identities()->where('provider', 'password')->exists();
                })
                ->action(function (): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    app(AccountAdministrationService::class)->reinvite($actor, $this->record);

                    Notification::make()
                        ->title('Une nouvelle invitation a été envoyée ; l’ancienne est révoquée.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
