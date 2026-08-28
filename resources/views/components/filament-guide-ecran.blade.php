{{--
    LE GUIDE DE L'ÉCRAN — replié par défaut, et entièrement traduit.

    Un panneau ouvert d'office est un panneau qu'on ferme sans lire, puis qu'on
    ne rouvre jamais. Replié, il ne coûte rien à qui connaît son poste et reste
    à un clic de qui débute. `<details>` porte cela sans une ligne de script :
    il s'ouvre au clavier, il s'annonce aux lecteurs d'écran, et il survit à un
    JavaScript en panne.

    LE CADRE SUIT LA LANGUE DU COMPTE, ET C'EST UNE CORRECTION.
    Le premier jet écrivait « À quoi sert cet écran ? », « Ce qu'on y fait »,
    « Quand la liste est vide » et « Où aller ensuite » EN DUR, en français. Le
    corps du guide était traduit, son cadre non : un expert arabophone lisait
    donc un guide arabe entouré de libellés français. Relevé par l'audit du
    28 août 2026, et invisible à mes propres tests, qui vérifiaient le corps
    sans jamais regarder le cadre.

    LE DÉCLENCHEUR PORTE LE NOM DE L'ÉCRAN. Le commentaire de la première
    version l'affirmait déjà — et le code rendait toujours la même phrase. On ne
    clique pas sur « Aide » ; on clique sur une question qu'on se pose.
--}}
@props(['guide'])

<details class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6 group">
    <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 text-sm font-medium text-gray-700 dark:text-gray-200 select-none">
        <x-filament::icon
            icon="heroicon-o-light-bulb"
            class="h-5 w-5 flex-none text-primary-500"
        />
        <span>{{ $guide->titre }}</span>
        <x-filament::icon
            icon="heroicon-m-chevron-down"
            class="ms-auto h-4 w-4 flex-none text-gray-400 transition group-open:rotate-180"
        />
    </summary>

    <div class="border-t border-gray-100 px-4 py-4 dark:border-white/10">
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $guide->role }}</p>

        @if ($guide->gestes !== [])
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                {{ __('guides.commun.gestes') }}
            </p>
            <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($guide->gestes as $geste)
                    <li class="flex gap-2">
                        <span class="mt-2 h-1 w-1 flex-none rounded-full bg-primary-500"></span>
                        <span>{{ $geste }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($guide->quandCEstVide !== [])
            {{--
                LE CAS LE PLUS UTILE ET LE PLUS OUBLIÉ, et il est en LISTE.
                Une liste vide a presque toujours deux causes opposées ; les
                fondre en un paragraphe oblige le lecteur à les démêler
                lui-même, ce que le guide devait lui épargner.
            --}}
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                {{ __('guides.commun.vide') }}
            </p>
            <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($guide->quandCEstVide as $cas)
                    <li class="flex gap-2">
                        <span class="mt-2 h-1 w-1 flex-none rounded-full bg-gray-300 dark:bg-gray-600"></span>
                        <span>{{ $cas }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($guide->ensuite !== [])
            <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                {{ __('guides.commun.ensuite') }}
            </p>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                @foreach ($guide->ensuite as $porte)
                    <a
                        href="{{ $porte['url'] }}"
                        class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >{{ $porte['libelle'] }}</a>
                @endforeach
            </div>
        @endif
    </div>
</details>
