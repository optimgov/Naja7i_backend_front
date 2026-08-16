#!/bin/sh
#
# Démarrage d'un conteneur backend Naja7i.
#
# Les caches Laravel sont écrits ICI et non dans le Dockerfile : `config:cache`
# fige les variables d'environnement au moment où il tourne. Les figer à la
# construction publierait une image portant la configuration de la machine de
# CI — base de données, clé applicative, domaines de session compris.
#
# Le script sert les trois rôles (serveur HTTP, worker de file, planificateur) :
# ils partagent la même image, seule la commande finale change.

set -eu

echo "→ Vérification de la configuration"

# APP_KEY absente = sessions et cookies chiffrés avec une clé vide. Laravel
# lèverait une exception à la première requête, mais le conteneur serait déjà
# déclaré « démarré » et le déploiement passerait pour réussi.
if [ -z "${APP_KEY:-}" ]; then
	echo "APP_KEY est vide. Générez-la une fois (php artisan key:generate --show)" >&2
	echo "et placez-la dans le fichier d'environnement du serveur." >&2
	exit 1
fi

case "${APP_ENV:-}" in
	production|staging) ;;
	*) echo "APP_ENV vaut « ${APP_ENV:-<vide>} » : attendu production ou staging." >&2; exit 1 ;;
esac

if [ "${APP_DEBUG:-false}" != "false" ]; then
	echo "APP_DEBUG est actif : les traces d'exception seraient renvoyées au candidat." >&2
	exit 1
fi

echo "→ Reconstruction des caches"

# Les dossiers sont recréés à chaque démarrage : un volume monté sur
# storage/ masquerait ceux de l'image, et Laravel échouerait sans dire quoi.
mkdir -p storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

# `clear` avant `cache` : un cache hérité de la couche précédente survivrait à
# un changement de variable d'environnement.
php artisan config:clear >/dev/null
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "→ Démarrage : $*"
exec "$@"
