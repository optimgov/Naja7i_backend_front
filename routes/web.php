<?php

use Illuminate\Support\Facades\Route;

/*
 * L'API n'a pas de page d'accueil.
 *
 * Le navigateur ne l'atteint jamais directement : il ne parle qu'au BFF Nitro
 * (ADR-0004), et en production l'API n'a aucun port publié. La page de
 * bienvenue du squelette Laravel n'avait donc aucun lecteur — et elle appelait
 * `@vite`, ce qui imposait de construire des ressources front dans l'image du
 * backend pour un écran que personne ne voit.
 *
 * `/up` est la route de santé déclarée dans bootstrap/app.php : y renvoyer
 * donne au moins une réponse utile à qui atterrit ici en développement.
 */
Route::redirect('/', '/up');
