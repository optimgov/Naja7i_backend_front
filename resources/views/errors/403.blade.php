{{--
  La page d'un refus de permission — D-13.

  ELLE NE S'EXCUSE PAS ET ELLE NE MENT PAS. La règle du dépôt distingue deux
  refus, et ils n'ont pas la même page :

    · une ressource qui appartient à AUTRUI répond 404 — le filtre par
      `user_id` fait foi, et un 403 y confirmerait une existence. Cette page
      n'est donc JAMAIS servie à un candidat pour la tentative d'un autre.
    · une PERMISSION DE PERSONNEL refusée répond 403 explicite. Le refusé sait
      déjà que la surface d'administration existe : il vient d'en demander une
      qu'il connaît. Lui répondre « introuvable » masquerait la raison sans
      rien protéger.

  D'où ce qu'elle nomme : la surface, la permission qui l'ouvre, et ce que le
  compte porte réellement. Les trois répondent aux trois questions du refusé —
  où suis-je, que me manque-t-il, et est-ce que je me suis trompé de compte.

  ELLE NE DIT RIEN DE PLUS. Ni qui détient la permission, ni la liste des
  comptes du personnel : ce serait un annuaire servi à qui n'a pas la
  permission qui le gouverne — le défaut D-04, réintroduit par la porte de
  secours.
--}}
@php
    $surface = \App\Support\SurfaceRefusee::pour(request());
@endphp

<!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Permission refusée — naja7i</title>
    <style>
        :root {
            color-scheme: light dark;
            --fond: #fbf9f5;
            --surface: #ffffff;
            --texte: #1f1c17;
            --doux: #5d574c;
            --bordure: #e4ded2;
            --accent: #1f6f4a;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --fond: #16140f;
                --surface: #1f1c17;
                --texte: #f4f0e8;
                --doux: #b3aa99;
                --bordure: #35301f;
                --accent: #6fbf94;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 2rem 1rem;
            background: var(--fond);
            color: var(--texte);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        main { width: 100%; max-width: 36rem; }

        .oeil {
            margin: 0 0 .5rem;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--doux);
        }

        h1 { margin: 0 0 .75rem; font-size: 1.75rem; line-height: 1.2; }

        p { margin: 0 0 1rem; color: var(--doux); }

        dl {
            margin: 0 0 1.5rem;
            padding: 1rem 1.25rem;
            background: var(--surface);
            border: 1px solid var(--bordure);
            border-radius: .5rem;
        }

        dt {
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--doux);
        }

        dd { margin: .25rem 0 1rem; }
        dd:last-of-type { margin-bottom: 0; }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .875rem;
            padding: .1rem .4rem;
            border-radius: .25rem;
            background: var(--fond);
            border: 1px solid var(--bordure);
        }

        .aucune { font-style: italic; color: var(--doux); }

        .sorties { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }

        a {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            color: var(--accent);
            font-weight: 600;
            text-underline-offset: 3px;
        }
    </style>
</head>
<body>
<main>
    <p class="oeil">Erreur 403 — permission refusée</p>

    <h1>
        @if ($surface['nom'] !== null)
            « {{ $surface['nom'] }} » vous est fermé
        @else
            Cette surface vous est fermée
        @endif
    </h1>

    <p>
        Elle existe, et votre compte n'a pas la permission qui l'ouvre. Demandez-la à
        l'administrateur de votre organisme en lui citant ce qui suit.
    </p>

    <dl>
        <dt>Adresse refusée</dt>
        <dd><code>{{ $surface['chemin'] }}</code></dd>

        @if ($surface['permission'] !== null)
            <dt>Permission demandée</dt>
            <dd><code>{{ $surface['permission'] }}</code></dd>
        @endif

        <dt>Permissions de votre compte</dt>
        <dd>
            @forelse ($surface['permissions_du_compte'] as $permission)
                <code>{{ $permission }}</code>@if (! $loop->last), @endif
            @empty
                <span class="aucune">aucune dans cet organisme</span>
            @endforelse
        </dd>
    </dl>

    {{-- Règle des portes : aucun état vide, aucun refus, ne se termine sans un
         chemin cliquable. On renvoie vers la racine du panneau — celle-ci
         choisit d'elle-même la première surface que le compte peut ouvrir. --}}
    <p class="sorties">
        @if ($surface['est_du_panneau'])
            <a href="{{ url('/admin') }}">Revenir au back-office</a>
        @else
            <a href="{{ url('/') }}">Revenir à l'accueil</a>
        @endif
    </p>
</main>
</body>
</html>
