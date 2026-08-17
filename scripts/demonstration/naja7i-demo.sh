#!/usr/bin/env bash
#
# naja7i-demo.sh — démarre la plateforme complète pour une visite locale.
#
#   ./naja7i-demo.sh          démarre tout, vérifie ce qu'il a produit, et laisse tourner
#   ./naja7i-demo.sh stop     arrête l'API et le front (les conteneurs restent)
#   ./naja7i-demo.sh reset    base neuve, puis démarre
#   ./naja7i-demo.sh etat     dit ce qui tourne et ce que la base contient, sans rien toucher
#
# ═══════════════════════════════════════════════════════════════════════════
# CE SCRIPT EST LA PREMIÈRE CHOSE QUE VOIT UN PARTENAIRE
#
# La version précédente a coûté une journée au pilote, sur trois défauts de
# forme qui tiennent en trois lignes — et qui sont le même défaut :
#
#   dropdb --if-exists naja7i 2>/dev/null || true
#   php artisan tinker preparer-referentiel.php >/dev/null
#   lsof … | xargs kill … || true
#
# Un drop qui échoue, un script qui lève, un port qu'on n'a pas su libérer :
# les trois étaient traités comme des succès. Le semis explosait ensuite sur
# `filieres_slug_unique`, à vingt lignes de là, et l'erreur ne nommait pas sa
# cause.
#
# TROIS RÈGLES GOUVERNENT LA RÉÉCRITURE :
#
#   1. AUCUN ÉCHEC AVALÉ. Pas de `|| true` sur une opération dont le résultat
#      compte. Chaque étape se nomme avant d'agir, et sa panne cite l'étape.
#
#   2. LE SCRIPT VÉRIFIE CE QU'IL A PRODUIT avant d'afficher son cadre de
#      succès. Un installateur qui ne mesure pas son résultat fabrique des
#      verts, exactement comme un test qui ne discrimine pas. Un compteur à
#      zéro est une panne, pas un détail.
#
#   3. LES PORTS DOIVENT ÊTRE À NOUS. DET-73 : la pile Docker de mise au point
#      se relance seule et tient 8000 et 3000 avec sa propre base et une image
#      ancienne. Le pilote a testé CETTE pile toute une journée en croyant
#      tester le dépôt. Le script refuse de démarrer sur un port occupé par
#      autre chose que lui, et NOMME l'occupant.
#
set -Eeuo pipefail

# LES CHEMINS SE DÉDUISENT DE L'EMPLACEMENT DU SCRIPT, pas d'un ~/Coding écrit
# en dur. Ce script vivait hors dépôt (DET-77) et pouvait se le permettre ;
# maintenant qu'il est versionné, quelqu'un le clonera ailleurs, et un chemin en
# dur ferait échouer sur une étape sans rapport avec la vraie cause.
BACK=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
FRONT=$(dirname "$BACK")/Naja7i_frontend
PIDS=~/Coding/.naja7i-demo.pids
JOURNAL_API=/tmp/naja7i-api.log
JOURNAL_FRONT=/tmp/naja7i-front.log

# macOS tue le processus PHP quand une classe Objective-C s'initialise après un
# fork() — c'est ce qui a fait planter l'étape des comptes d'équipe sur une
# exécution mesurée. Le même export protège déjà la suite de tests du dépôt.
export OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES

ETAPE="démarrage"

rouge()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
vert()   { printf '\033[32m%s\033[0m\n' "$*"; }
gras()   { printf '\033[1m%s\033[0m\n' "$*"; }
doux()   { printf '\033[2m%s\033[0m\n' "$*"; }

# Une ligne « libellé …… valeur » ALIGNÉE, y compris sur des libellés accentués.
#
# `printf '%-28s'` pade en OCTETS, pas en caractères : en UTF-8, chaque accent
# vaut deux octets, et une colonne se décale d'autant. C'est ce qui rendait le
# bloc de vérification bancal — « éligibles à la simulation » (trois accents)
# décalé de trois espaces par rapport à « comptes candidats ». Cosmétique, mais
# ce bloc est précisément celui qu'on lit pour décider si la visite est
# montrable : il doit se parcourir d'un coup d'oeil.
#
# `wc -m` compte des CARACTÈRES. On complète nous-mêmes.
chiffre() {
  local libelle=$1 valeur=$2 largeur=28 n
  n=$(printf '%s' "$libelle" | wc -m | tr -d ' ')
  printf '   %s%*s %s\n' "$libelle" $(( largeur - n )) '' "$valeur"
}

etape()  { ETAPE="$1"; printf '\n\033[1m── %s\033[0m\n' "$1"; }

# Toute sortie non nulle non rattrapée passe ici : l'étape se nomme elle-même.
trap 'rouge ""; rouge "  ÉCHEC pendant : ${ETAPE}"; rouge "  (ligne ${LINENO})"; rouge ""; exit 1' ERR

echouer() {
  rouge ""
  rouge "  ÉCHEC — ${ETAPE}"
  rouge ""
  while [ $# -gt 0 ]; do printf '  %s\n' "$1" >&2; shift; done
  rouge ""
  exit 1
}

# ───────────────────────────────────────────────────── outils de mesure

# L'ÉTAT VIENT DE L'APPLICATION, PAS DE `psql`.
#
# Les identifiants de la base sont dans `.env`, que ce script n'a pas à lire —
# et l'hôte comme le port changent dès qu'un conteneur entre en jeu. La
# première écriture appelait `psql -d naja7i` en supposant « base locale,
# utilisateur courant » : elle ne joignait rien, et annonçait « la base ne
# répond pas » sur une base parfaitement vivante.
#
# `php artisan naja7i:etat` rend des lignes `cle=valeur` et sort en erreur si
# la base est injoignable. Un compteur qu'on n'a pas su lire n'est pas un
# compteur à zéro.
ETAT_CACHE=""

relever_l_etat() {
  ETAT_CACHE=$(cd "$BACK" && php artisan naja7i:etat 2>&1) || echouer \
    "La base n'a pas répondu." \
    "$ETAT_CACHE"
}

compter() {
  local cle=$1
  printf '%s' "$(printf '%s\n' "$ETAT_CACHE" | grep "^${cle}=" | cut -d= -f2 | head -1)"
}

# Qui tient ce port ? On NOMME, on ne devine pas.
occupant_du_port() {
  local port=$1 pid nom conteneur ligne racine

  pid=$(lsof -ti :"$port" -sTCP:LISTEN 2>/dev/null | head -1 || true)
  [ -z "$pid" ] && { printf 'libre'; return; }

  # NE JAMAIS TRONQUER AVEUGLÉMENT. Première écriture : `cut -c1-90`, qui a
  # coupé « Naja7i_backend_front » juste après « Naja7i_backend » — soit
  # exactement au caractère qui distingue notre dépôt d'un autre. Un script
  # écrit pour lever le doute de DET-73 (« quelle pile tient ce port ? ») ne
  # peut pas mentir précisément là où on l'interroge.
  #
  # On extrait donc le DOSSIER DE PROJET de la ligne de commande, en entier, et
  # on le montre à part. La ligne complète reste disponible en dessous, coupée
  # au MILIEU et non à la fin, pour que ses deux extrémités survivent.
  ligne=$(ps -o command= -p "$pid" 2>/dev/null) || ligne=""
  [ -z "$ligne" ] && { printf '(processus %s, disparu entre-temps)' "$pid"; return; }

  racine=$(printf '%s' "$ligne" | grep -oE '/[^ ]*/(Naja7i[A-Za-z0-9_-]*)' | head -1 || true)

  if [ -n "$racine" ]; then
    nom="$racine"
  elif [ ${#ligne} -gt 90 ]; then
    nom="${ligne:0:45} … ${ligne: -45}"
  else
    nom="$ligne"
  fi

  # Un conteneur Docker se présente sous `com.docker.backend` : on va chercher
  # son nom réel, sans quoi le message ne sert à rien.
  conteneur=$(docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null \
              | grep ":${port}->" | cut -f1 | head -1 || true)

  if [ -n "$conteneur" ]; then
    printf 'conteneur Docker « %s »' "$conteneur"
  else
    printf 'pid %s — %s' "$pid" "$nom"
  fi
}

# À QUI EST CE PORT ? Trois réponses, pas deux.
#
# Première écriture : « à nous = libre, ou pid enregistré dans $PIDS ». Mesuré
# en relançant le script deux fois de suite : IL SE REFUSE À LUI-MÊME.
#
# La raison est celle qui avait déjà piégé l'arrêt : `artisan serve` n'écoute
# pas lui-même, il engendre un `php -S` enfant. C'est le PARENT qui va dans
# $PIDS et l'ENFANT que `lsof` rapporte. Les deux pids ne se rencontrent jamais.
#
# Et le refus était pire que l'obstacle : le script nommait l'occupant
# (« /Users/…/Naja7i_backend_front »), puis conseillait à la ligne suivante
# d'arrêter des conteneurs Docker qui n'y étaient pour rien. Un diagnostic qui
# contredit la preuve qu'il vient d'imprimer est plus nuisible qu'aucun
# diagnostic.
#
# On raisonne donc sur la LIGNE DE COMMANDE, comme le fait l'arrêt : un
# processus est à nous s'il tourne depuis un de nos deux dépôts. Ce critère
# survit au couple parent/enfant, et à un fichier $PIDS périmé.
#
#   0 = libre        1 = à nous, encore debout        2 = à quelqu'un d'autre
a_qui_est_le_port() {
  local port=$1 pid ligne

  pid=$(lsof -ti :"$port" -sTCP:LISTEN 2>/dev/null | head -1 || true)
  [ -z "$pid" ] && return 0

  ligne=$(ps -o command= -p "$pid" 2>/dev/null || true)

  case "$ligne" in
    *"$BACK"*|*"$FRONT"*) return 1 ;;
    *) ;;
  esac

  [ -f "$PIDS" ] && grep -qx "$pid" "$PIDS" && return 1

  return 2
}

exiger_les_ports() {
  local souci=0 rc

  for port in 8000 3000; do
    a_qui_est_le_port "$port" && continue || rc=$?

    rouge "  le port ${port} est tenu par : $(occupant_du_port "$port")"
    [ "$rc" = "2" ] && souci=2
    [ "$rc" = "1" ] && [ "$souci" = "0" ] && souci=1
  done

  if [ "$souci" = "1" ]; then
    echouer \
      "Une exécution précédente de CE script est encore debout." \
      "" \
      "Ce n'est pas le piège de DET-73 : les processus ci-dessus tournent bien" \
      "depuis nos dépôts. Ils tiennent simplement les ports dont la nouvelle" \
      "exécution a besoin." \
      "" \
      "Pour les arrêter :" \
      "    ./naja7i-demo.sh stop" \
      "" \
      "Puis relancez ce script."
  fi

  [ "$souci" = "0" ] && return 0

  echouer \
    "Un port dont ce script a besoin est tenu par autre chose que lui." \
    "" \
    "C'est le piège de DET-73 : la pile Docker de mise au point se relance" \
    "seule au démarrage du service, avec SA PROPRE BASE et une image qui peut" \
    "dater. Une plateforme répond alors sur ces ports — mais ce n'est pas" \
    "celle du dépôt, et tout ce que vous y testerez sera faux." \
    "" \
    "Pour l'arrêter :" \
    "    docker stop naja7i-local-app-1 naja7i-local-frontend-1" \
    "    docker stop naja7i-local-scheduler-1 naja7i-local-worker-1" \
    "" \
    "Puis relancez ce script."
}

# `php artisan tinker <fichier>` REND 0 MÊME QUAND LE SCRIPT LÈVE. Son code de
# sortie ne vaut donc rien : on lit ce qu'il a écrit.
tinker_sur() {
  local fichier=$1 sortie
  shift

  sortie=$(cd "$BACK" && env "$@" php artisan tinker "$fichier" 2>&1) || true

  printf '%s\n' "$sortie" | sed 's/^/   /'

  if printf '%s' "$sortie" | grep -qiE 'Exception|Fatal|ÉCHEC|\bError\b'; then
    echouer "Le script « $(basename "$fichier") » a levé." \
            "Sa sortie est reproduite ci-dessus."
  fi

  # ET LES PLANTAGES QUI NE DISENT AUCUN DE CES MOTS.
  #
  # Mesuré sur une exécution complète : l'étape « comptes de l'équipe
  # éditoriale » a planté sur la sécurité fork() d'Objective-C, et le script a
  # CONTINUÉ en la donnant pour faite. Le message était :
  #
  #     objc[42431]: +[NSCheapMutableString initialize] may have been in
  #     progress in another thread when fork() was called. … Crashing instead.
  #     Set a breakpoint on objc_initializeAfterForkError to debug.
  #
  # Il a traversé LES DEUX filtres : il ne dit ni « Exception » ni « ÉCHEC », et
  # `objc_initializeAfterForkError` n'a pas de frontière de mot avant « Error »,
  # donc `\bError\b` ne l'attrape pas. Il n'est pas non plus silencieux — c'est
  # justement le bruit du plantage qui a satisfait le contrôle de silence.
  #
  # On n'élargit PAS `\bError\b` en `Error` : une sortie légitime peut contenir
  # ce mot, et un contrôle qui accuse du code juste finit désactivé. On nomme
  # les signatures de plantage, une par une.
  if printf '%s' "$sortie" | grep -qE 'objc\[[0-9]+\]|Crashing instead|Segmentation fault|core dumped'; then
    echouer "Le script « $(basename "$fichier") » a été TUÉ par le système." \
            "Ce n'est pas une exception applicative : le processus est mort." \
            "Sa sortie est reproduite ci-dessus." \
            "" \
            "Sur macOS, la cause habituelle est la sécurité fork() d'Objective-C" \
            "déclenchée par PHP. Ce script exporte déjà" \
            "OBJC_DISABLE_INITIALIZE_FORK_SAFETY=YES ; si le message revient," \
            "l'export a été perdu quelque part sur le chemin."
  fi

  if [ -z "$(printf '%s' "$sortie" | tr -d '[:space:]')" ]; then
    echouer "Le script « $(basename "$fichier") » n'a rien écrit." \
            "Tous les scripts de préparation annoncent ce qu'ils posent :" \
            "un silence complet est une panne, pas un succès discret."
  fi
}

# ───────────────────────────────────────────────────────────── arrêt

arreter() {
  etape "arrêt de l'API et du front"

  if [ -f "$PIDS" ]; then
    while read -r pid; do
      [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
    done < "$PIDS"
    rm -f "$PIDS"
  fi

  sleep 1

  # `php artisan serve` ENGENDRE UN `php -S` ENFANT, et `npm run dev` un `node`.
  # Tuer le pid enregistré laisse donc l'enfant orphelin, qui garde le port —
  # défaut constaté en éprouvant la garde des ports, pas deviné.
  #
  # On ne fait PAS `lsof | xargs kill || true` pour autant : tuer ce qui écoute
  # sans savoir ce que c'est, c'est risquer d'arrêter la pile d'un autre. On
  # n'achève que ce qui porte NOS chemins dans sa ligne de commande.
  for port in 8000 3000; do
    pid=$(lsof -ti :"$port" -sTCP:LISTEN 2>/dev/null | head -1 || true)
    [ -z "$pid" ] && continue

    ligne=$(ps -o command= -p "$pid" 2>/dev/null || true)

    if printf '%s' "$ligne" | grep -qF "Naja7i_backend_front" \
    || printf '%s' "$ligne" | grep -qF "Naja7i_frontend"; then
      doux "   port ${port} : reste un enfant orphelin, on l'achève"
      kill "$pid" 2>/dev/null || true
    else
      doux "   le port ${port} reste tenu par : $(occupant_du_port "$port")"
      doux "   (ce n'est pas à nous — laissé en place)"
    fi
  done

  vert "   arrêté."
}

# ─────────────────────────────────────────────────────────────── état

etat() {
  gras "── ce qui tourne"
  for port in 8000 3000 8025; do
    printf '   port %-5s %s\n' "$port" "$(occupant_du_port "$port")"
  done

  gras ""
  gras "── ce que la base contient"

  if ! ETAT_CACHE=$(cd "$BACK" && php artisan naja7i:etat 2>&1); then
    doux "   la base ne répond pas :"
    printf '%s\n' "$ETAT_CACHE" | sed 's/^/     /'
    return 0
  fi

  for cle in filieres epreuves noeuds questions_publiees eligibles_diagnostic \
             eligibles_simulation annales_importees offres comptes; do
    chiffre "$cle" "$(compter "$cle")"
  done
}

# ──────────────────────────────────────────────────────── base neuve

reinitialiser() {
  etape "base neuve"

  arreter

  # `dropdb --if-exists … || true` était le premier des trois défauts : un drop
  # qui échoue — parce qu'une connexion reste ouverte, le cas le plus courant —
  # laissait le script continuer, et le semis explosait vingt lignes plus loin
  # sur une contrainte d'unicité, sans jamais nommer la vraie cause.
  #
  # Ici, un drop qui échoue ARRÊTE, et dit quoi faire.
  if psql -tA -d postgres -c "select 1 from pg_database where datname = 'naja7i'" 2>/dev/null | grep -q 1; then
    dropdb naja7i 2>/dev/null || echouer \
      "La base « naja7i » n'a pas pu être supprimée." \
      "" \
      "Presque toujours : une connexion reste ouverte. Un tinker, un psql, une" \
      "API encore vivante. Pour voir qui :" \
      "    psql -d postgres -c \"select pid, application_name from pg_stat_activity where datname = 'naja7i'\"" \
      "" \
      "Fermez-les, puis relancez."
  fi

  createdb naja7i || echouer "La base « naja7i » n'a pas pu être créée."

  vert "   base « naja7i » recréée, vide."
}

case "${1:-}" in
  stop)  arreter; exit 0 ;;
  etat)  etat; exit 0 ;;
  reset) reinitialiser ;;
  "")    ;;
  *)     echouer "Argument inconnu : ${1}." "Attendus : (aucun), stop, reset, etat." ;;
esac

# ═══════════════════════════════════════════════════════ démarrage

gras ""
gras "naja7i.ma — démonstration locale"

etape "les ports 8000 et 3000 doivent être à nous (DET-73)"
exiger_les_ports
vert "   libres, ou tenus par une exécution précédente de ce script."

etape "conteneurs — PostgreSQL, Redis, Mailpit"
( cd "$BACK" && docker compose up -d ) || echouer \
  "docker compose n'a pas démarré." \
  "Si Docker est arrêté :  colima start"

for essai in $(seq 1 30); do
  ( cd "$BACK" && php artisan naja7i:etat >/dev/null 2>&1 ) && break
  [ "$essai" = "30" ] && echouer "PostgreSQL n'a pas répondu après 30 secondes."
  sleep 1
done
vert "   PostgreSQL répond."

# ─────────────────────────────────────────── la base : neuve ou peuplée
#
# `CatalogueSeeder` emploie `create()`, pas `firstOrCreate()`. Sur une base déjà
# semée, il explose sur `filieres_slug_unique` — c'est ce qui est arrivé au
# pilote, et l'erreur ne nommait pas sa cause.
#
# TROIS VOIES, ET LA TROISIÈME EST LA BONNE :
#
#   (a) RENDRE LES SEEDERS IDEMPOTENTS. Tentant, et faux ici. Un `firstOrCreate`
#       accepte une base à moitié semée, une base dont un nœud a été renommé à
#       la main, une base d'une version antérieure du référentiel — et il
#       repart sans rien dire. Le catalogue est une donnée de RÉFÉRENCE : sa
#       moitié n'a aucun sens. L'idempotence masquerait exactement l'état qu'il
#       faut voir.
#
#   (b) EXIGER UNE BASE NEUVE À CHAQUE FOIS. Honnête, et invivable : le pilote
#       perdrait à chaque démarrage les questions qu'il a rédigées, les
#       commandes qu'il a validées, les tentatives qu'il a passées. Un outil de
#       démonstration qui efface le travail de la veille ne sert qu'une fois.
#
#   (c) DÉTECTER, ET DÉCIDER SELON CE QU'ON TROUVE. Le catalogue est absent :
#       on sème. Il est présent ET COMPLET : on n'y touche pas, et le contenu
#       ajouté survit. Il est présent et INCOMPLET : on s'arrête en le disant,
#       parce qu'une base à moitié semée n'est réparable que par un humain qui
#       sait ce qu'il a fait.
#
# La complétude se mesure sur les invariants du référentiel, pas sur « il y a
# des lignes » : trois voies, trois épreuves, et les matrices de domaines.
#
etape "état du référentiel"

relever_l_etat
FILIERES=$(compter filieres)
EPREUVES=$(compter epreuves)
NOEUDS=$(compter noeuds)

if [ "$FILIERES" = "0" ] && [ "$EPREUVES" = "0" ]; then
  doux "   base vierge — semis complet"
  SEMER=oui
elif [ "$FILIERES" -ge 3 ] && [ "$EPREUVES" -ge 3 ] && [ "$NOEUDS" -ge 20 ]; then
  vert "   référentiel déjà en place (${FILIERES} filières, ${EPREUVES} épreuves, ${NOEUDS} nœuds)"
  doux "   il n'est pas rejoué : le contenu ajouté depuis est conservé."
  SEMER=non
else
  echouer \
    "Le référentiel est INCOMPLET : ${FILIERES} filière(s), ${EPREUVES} épreuve(s), ${NOEUDS} nœud(s)." \
    "" \
    "Une base à moitié semée ne se répare pas toute seule, et rejouer les" \
    "seeders par-dessus produirait des doublons ou une violation d'unicité." \
    "" \
    "Pour repartir d'une base neuve :" \
    "    ./naja7i-demo.sh reset"
fi

etape "migrations"
( cd "$BACK" && php artisan migrate --force )

if [ "$SEMER" = "oui" ]; then
  etape "semis du référentiel"
  ( cd "$BACK" && php artisan db:seed --force )
fi

etape "API sur le port 8000"
( cd "$BACK" && RATE_LIMIT_PROFILE=recette nohup php artisan serve --port=8000 >"$JOURNAL_API" 2>&1 & echo $! >> "$PIDS" )

for essai in $(seq 1 30); do
  curl -sf http://localhost:8000/up >/dev/null 2>&1 && break
  [ "$essai" = "30" ] && echouer \
    "L'API n'a pas répondu sur /up après 30 secondes." \
    "Journal : $JOURNAL_API"
  sleep 1
done
vert "   l'API répond."

etape "comptes de l'équipe éditoriale"
tinker_sur "$FRONT/scripts/recette/preparer-referentiel.php"

etape "banque de questions, par la chaîne éditoriale"
( cd "$FRONT" && node scripts/recette/semer-banque.mjs )

etape "comptes candidats"
( cd "$FRONT" && node scripts/recette/preparer-comptes.mjs )

etape "front sur le port 3000"
( cd "$FRONT" && API_BASE_URL=http://localhost:8000 nohup npm run dev >"$JOURNAL_FRONT" 2>&1 & echo $! >> "$PIDS" )

for essai in $(seq 1 60); do
  curl -sf http://localhost:3000/fr/connexion >/dev/null 2>&1 && break
  [ "$essai" = "60" ] && echouer \
    "Le front n'a pas répondu après 60 secondes." \
    "Journal : $JOURNAL_FRONT"
  sleep 1
done
vert "   le front répond."

# ═══════════════════════════════════════════════════════════════════════
# LA VÉRIFICATION — avant le cadre, jamais après
#
# Tout ce qui précède peut « réussir » et ne rien produire : un semis qui
# n'insère rien, une chaîne éditoriale qui publie zéro question, des comptes
# créés sans vérification d'e-mail. Le cadre de succès ne s'affiche qu'après
# avoir COMPTÉ.
#
etape "vérification de ce qui a été produit"

relever_l_etat
PUBLIEES=$(compter questions_publiees)
DIAGNOSTIC=$(compter eligibles_diagnostic)
SIMULATION=$(compter eligibles_simulation)
EQUIPE=$(compter comptes_equipe)
CANDIDATS=$(compter comptes_candidats)

chiffre 'questions publiées'        "$PUBLIEES"
chiffre 'éligibles au diagnostic'   "$DIAGNOSTIC"
chiffre 'éligibles à la simulation' "$SIMULATION"
# shellcheck disable=SC1112  # L'apostrophe typographique est VOULUE : ce libellé
# s'affiche à l'écran, et le produit écrit le français correctement. Entre quotes
# simples elle est littérale, donc sans effet sur le shell. On désactive la règle
# ICI seulement, pas dans tout le fichier : ailleurs, une quote unicode serait
# bien un défaut.
chiffre 'comptes de l’équipe'       "$EQUIPE"
chiffre 'comptes candidats'         "$CANDIDATS"

MANQUE=""
[ "$PUBLIEES"   -lt 1 ] && MANQUE="${MANQUE}\n  · aucune question publiée : le front-office n'a rien à montrer."
[ "$DIAGNOSTIC" -lt 1 ] && MANQUE="${MANQUE}\n  · aucune question éligible au diagnostic : la boucle candidat est morte."
[ "$SIMULATION" -lt 1 ] && MANQUE="${MANQUE}\n  · aucune question éligible à la simulation : l'examen blanc ne se compose pas."
[ "$EQUIPE"     -lt 3 ] && MANQUE="${MANQUE}\n  · moins de trois comptes d'équipe : la chaîne éditoriale exige auteur, relecteur et valideur distincts."
[ "$CANDIDATS"  -lt 2 ] && MANQUE="${MANQUE}\n  · moins de deux comptes candidats : la reprise sur un second appareil ne se montre pas."

if [ -n "$MANQUE" ]; then
  rouge ""
  rouge "  LA PLATEFORME TOURNE, MAIS ELLE EST VIDE."
  rouge ""
  printf '%b\n' "$MANQUE" >&2
  rouge ""
  rouge "  Ce n'est pas une visite montrable. Le journal de l'API est dans"
  rouge "  $JOURNAL_API, celui du front dans $JOURNAL_FRONT."
  rouge ""
  exit 1
fi

vert "   tout est là."

cat <<'FIN'

╔═══════════════════════════════════════════════════════════════════╗
║  naja7i.ma tourne en local — voir scripts/demonstration/VISITE.md ║
╠═══════════════════════════════════════════════════════════════════╣
║  Front-office   http://localhost:3000/fr                          ║
║  Back-office    http://localhost:8000/admin                       ║
║  E-mails reçus  http://localhost:8025   (Mailpit)                 ║
║                                                                   ║
║  Ce qui tourne  ./naja7i-demo.sh etat                             ║
║  Arrêter        ./naja7i-demo.sh stop                             ║
╚═══════════════════════════════════════════════════════════════════╝
FIN
