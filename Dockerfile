# syntax=docker/dockerfile:1.7
#
# Image de production du backend Naja7i.
#
# Trois décisions non évidentes, chacune expliquée là où elle est prise :
#   1. FrankenPHP plutôt que php-fpm + nginx — un seul processus, donc pas de
#      superviseur ni de configuration nginx dupliquée entre les images.
#   2. Les dépendances sont résolues PAR LE PHP DE PRODUCTION, pas par celui de
#      l'image `composer` (voir l'étape « socle »).
#   3. Les caches Laravel sont écrits au DÉMARRAGE et non à la construction :
#      `config:cache` fige les variables d'environnement, or elles ne sont
#      connues qu'au lancement du conteneur. Les figer ici publierait l'image
#      avec la configuration de la machine de construction.

########################################################################
# Socle — le PHP commun aux deux étapes
########################################################################
#
# `composer:2` n'est PAS utilisé comme image de base. Elle est bâtie sur
# `php:8-alpine`, dont la version mineure suit la dernière 8.x : le jour où
# elle passe à 8.6, la résolution des dépendances change sans qu'aucun fichier
# du dépôt n'ait bougé. Et surtout, elle n'embarque ni `intl` ni `pdo_pgsql` —
# or `filament/support` exige `ext-intl`, et `composer install` s'arrête net
# sur son absence.
#
# Résoudre les dépendances avec exactement le PHP qui les exécutera supprime
# les deux problèmes d'un coup.
FROM dunglas/frankenphp:php8.4-alpine AS socle

# pdo_pgsql/pgsql : la base est PostgreSQL, jamais SQLite (ADR-0002).
# intl        : exigée par filament/support ; formats de date et de nombre.
# bcmath      : calculs de maîtrise sans erreur de virgule flottante.
# pcntl       : sans elle, `queue:work` n'entend pas SIGTERM et Docker le tue
#               au bout du délai de grâce, au milieu d'un travail.
# opcache     : voir docker/php.ini.
# zip         : archives d'export ; utilisée aussi par composer.
RUN install-php-extensions \
      pdo_pgsql pgsql intl bcmath pcntl opcache zip \
 && apk add --no-cache curl

# ---------------------------------------------------------------------
# Argon2id : vérifié, pas supposé.
#
# `.env.example` impose HASH_DRIVER=argon2id, et phpunit.xml teste ce hacheur
# précisément parce que bcrypt tronque à 72 OCTETS — ce qui ampute une phrase
# de passe en arabe. Si l'image de base était un jour compilée sans libargon2,
# Laravel basculerait sur bcrypt À L'EXÉCUTION, silencieusement, et la faille
# que le projet a documentée reviendrait sans qu'aucun test ne rougisse.
# Cette ligne fait échouer la CONSTRUCTION plutôt que la production.
# ---------------------------------------------------------------------
RUN php -r 'if (!defined("PASSWORD_ARGON2ID")) { fwrite(STDERR, "PHP compilé sans Argon2id : le hachage retomberait sur bcrypt.\n"); exit(1); } echo "Argon2id disponible\n";'


########################################################################
# Étape 1 — dépendances PHP
########################################################################
FROM socle AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Clé jetable, valable UNIQUEMENT dans cette étape de construction : elle ne
# figure dans aucune couche de l'image finale.
#
# `post-autoload-dump` amorce Laravel (package:discover, filament:upgrade).
# Sans APP_KEY, cet amorçage échoue sur toute lecture de valeur chiffrée, et
# le message d'erreur ne dit pas que c'est la clé qui manque.
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_ENV=production \
    APP_DEBUG=false

# Le manifeste seul d'abord : tant que composer.lock ne change pas, cette
# couche est réutilisée et l'installation n'est pas rejouée.
COPY composer.json composer.lock ./

# `--no-scripts` : les scripts Laravel exigent le code de l'application, qui
# n'est pas encore copié. Ils tournent au dump ci-dessous.
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction --no-progress

COPY . .

# `dump-autoload` déclenche `post-autoload-dump`, donc `package:discover` et
# `filament:upgrade` : le manifeste des paquets et les ressources de
# l'administration sont écrits ici, une fois, plutôt qu'au premier démarrage
# de chaque conteneur.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


########################################################################
# Étape 2 — image d'exécution
########################################################################
FROM socle AS runtime

COPY docker/php.ini    /usr/local/etc/php/conf.d/naja7i.ini
COPY docker/Caddyfile  /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

COPY --from=vendor --chown=1000:1000 /app /app

# Les dossiers que Laravel écrit à l'exécution. `storage/app` reçoit un volume
# en production (fichiers candidats) ; `storage/framework` reste éphémère, il
# est reconstruit à chaque démarrage par l'entrypoint.
#
# Chemins écrits en toutes lettres : le shell d'Alpine est celui de BusyBox,
# qui n'étend pas les accolades. `storage/framework/{cache,sessions}` y créerait
# un dossier nommé littéralement « {cache,sessions} », et Laravel échouerait au
# premier écrit — sans que la construction, elle, n'ait rien signalé.
RUN set -eux; \
    mkdir -p storage/framework/cache/data; \
    mkdir -p storage/framework/sessions; \
    mkdir -p storage/framework/views; \
    mkdir -p storage/logs bootstrap/cache; \
    chown -R 1000:1000 storage bootstrap/cache

# APP_ENV et APP_DEBUG sont posés ici pour que l'image soit sûre PAR DÉFAUT :
# un conteneur lancé sans fichier d'environnement ne divulguera pas de trace
# d'exception. Le fichier d'environnement du serveur les recouvre.
#
# XDG_* : Caddy écrit une sauvegarde automatique de sa configuration dans
# $XDG_CONFIG_HOME. Laissé à `/config`, propriété de root, chaque démarrage
# journalise « unable to autosave config » sous l'utilisateur 1000 — un bruit
# permanent qui masque les vrais avertissements.
ENV APP_ENV=production \
    APP_DEBUG=false \
    XDG_CONFIG_HOME=/app/storage/framework \
    XDG_DATA_HOME=/app/storage/framework

# Utilisateur NUMÉRIQUE plutôt que nommé : selon l'image de base, l'UID 1000
# est déjà pris ou non, et un `adduser` toléré en échec laisserait un `USER app`
# qui, lui, ne l'est pas. 1000 correspond au `--chown` de la copie ci-dessus.
USER 1000:1000
EXPOSE 8000

# `/up` est la route de santé déclarée dans bootstrap/app.php.
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=4 \
  CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
