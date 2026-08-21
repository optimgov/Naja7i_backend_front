<x-filament-panels::page>
    <form wire:submit="saveAccount" class="space-y-6">
        {{ $this->accountForm }}

        <x-filament::button type="submit">
            {{ __('dossier.actions.save_account') }}
        </x-filament::button>
    </form>

    <form wire:submit="savePassword" class="space-y-6">
        {{ $this->passwordForm }}

        <x-filament::button type="submit">
            {{ __('dossier.actions.save_password') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
