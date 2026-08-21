<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AccountAdministrationService;
use App\Services\PermissionResolver;
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
}
