{{--
    LE GUIDE DE L'ÉCRAN — ouvert la PREMIÈRE fois, replié ensuite.

    Le premier jet le repliait toujours, en s'appuyant sur un raisonnement qui
    n'en couvrait que la moitié : « un panneau ouvert d'office est un panneau
    qu'on ferme sans lire ». C'est vrai du dixième passage. Ce l'est faux du
    premier, où le guide reste alors invisible à celui-là même pour qui il est
    écrit — relevé le 29 août : « le guide reste replié dès la première visite ».

    LA RÈGLE TIENT DONC LES DEUX BOUTS. Il s'ouvre tant que la personne ne l'a
    jamais refermé sur CET écran ; dès qu'elle le referme, il reste replié.
    C'est elle qui décide, et une seule fois.

    LE REPLI EST UNE PRÉFÉRENCE, PAS UNE DONNÉE. Il vit dans le navigateur, et
    ne vaut pas un aller-retour au serveur ni une colonne en base : perdre ce
    réglage en changeant de poste rouvre un guide, ce qui n'a jamais nui à
    personne.

    SANS JAVASCRIPT, LE GUIDE EST OUVERT. C'est le bon défaut : l'état qu'on
    ne peut pas connaître est traité comme une première visite. `<details>`
    garde par ailleurs tout ce qu'il apportait — ouverture au clavier, annonce
    aux lecteurs d'écran, survie à un script en panne.

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

@php
    /* La clé du repli est l'écran, pas le panneau : on referme le guide des
       questions sans refermer celui de la couverture. */
    $cleDeRepli = 'naja7i.guide.'.md5($guide->titre);
@endphp

<details
    open
    data-guide="{{ $cleDeRepli }}"
    class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6 group"
>
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

<script>
    /*
     * On referme AVANT la peinture pour les écrans déjà connus : appliquer
     * l'état après coup ferait clignoter le guide ouvert puis refermé à chaque
     * chargement. Le script est inline et sans dépendance — il tourne au
     * moment où l'élément vient d'être analysé, donc juste après lui.
     */
    (function () {
        var panneaux = document.querySelectorAll('details[data-guide]')

        for (var i = 0; i < panneaux.length; i++) {
            (function (panneau) {
                var cle = panneau.getAttribute('data-guide')

                try {
                    if (window.localStorage.getItem(cle) === 'replie') panneau.open = false
                } catch (e) {
                    /* Stockage refusé — navigation privée, réglage strict. Le
                       guide reste ouvert : c'est le défaut sûr. */
                }

                panneau.addEventListener('toggle', function () {
                    try {
                        window.localStorage.setItem(cle, panneau.open ? 'ouvert' : 'replie')
                    } catch (e) { /* sans stockage, le choix ne survit pas à la page */ }
                })
            })(panneaux[i])
        }
    })()
</script>
