<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * L'application s'amorce et répond.
     *
     * Ce test est celui du squelette Laravel : sa valeur est de vérifier que le
     * conteneur de services se monte et qu'une requête traverse la pile. Il
     * attendait 200 sur `/`, ce qui n'avait de sens que tant que la racine
     * servait la page de bienvenue.
     *
     * La racine renvoie désormais vers `/up` (voir routes/web.php) : l'API n'a
     * pas de page d'accueil, le navigateur ne l'atteint jamais directement. Le
     * test suit la décision plutôt que de la contredire, et vérifie en plus
     * que la route de santé répond réellement — ce que la version d'origine ne
     * faisait pas, alors que c'est elle que les sondes de conteneur
     * interrogent à chaque déploiement.
     */
    public function test_la_racine_renvoie_vers_la_route_de_sante(): void
    {
        $this->get('/')->assertRedirect('/up');
    }

    public function test_la_route_de_sante_repond(): void
    {
        $this->get('/up')->assertOk();
    }
}
