<x-filament-panels::page>
    @php($ligne = $this->laSuivante())

    @if ($ligne === null)
        <x-filament::section>
            <p class="text-sm">La file est vide : aucune question n’attend de qualification.</p>
        </x-filament::section>
    @else
        @php($source = $this->ceQueLaSourceDit($ligne))
        @php($observee = $this->difficulteObservee($ligne))

        <x-filament::section :heading="'Référence d’import : ' . $ligne->import_ref">
            <p class="text-sm">État : {{ __('preparation.etat_' . $ligne->state->value) }}</p>
        </x-filament::section>

        {{--
            CE QUE LA SOURCE DIT — À CÔTÉ, JAMAIS DEDANS.

            Ce bloc est en LECTURE SEULE et porte son avertissement. Aucun de
            ces champs n'alimente un formulaire : un champ pré-rempli est
            accepté sans être lu, et c'est ainsi qu'une erreur d'import devient
            une vérité éditoriale.
        --}}
        <x-filament::section heading="Ce que la source dit" icon="heroicon-o-document-magnifying-glass">
            <p class="text-sm font-medium">{{ $source['avertissement'] }}</p>

            <dl class="mt-4 space-y-2 text-sm">
                <div>
                    <dt class="font-medium">Énoncé</dt>
                    <dd>{{ $source['enonce'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Suggestion de réponse portée par l’import</dt>
                    <dd>{{ $source['suggestion_reponse'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium">Difficulté provisoire portée par l’import</dt>
                    <dd>{{ $source['difficulte_provisoire'] ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Difficulté observée">
            <p class="text-sm">{{ $observee['texte'] ?? 'Cette question n’a pas encore été servie.' }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
