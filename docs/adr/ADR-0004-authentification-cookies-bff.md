# ADR-0004 — Authentification par cookies de session (BFF)

**Statut :** accepté · 7 août 2026

## Décision
Le front-office s'authentifie par **cookie httpOnly** via Sanctum stateful,
relayé par le BFF Nitro : le navigateur ne parle qu'à `www.naja7i.ma`.
Aucun jeton n'est accessible au JavaScript ; une faille XSS ne permet pas
d'exfiltrer une session réutilisable ailleurs. Sessions en Redis, application
stateless. Protection CSRF par le cookie `XSRF-TOKEN`.

Le schéma **bearer** du contrat OpenAPI reste réservé aux intégrations
serveur-à-serveur. Jamais utilisé par le navigateur candidat.

## Alternative écartée
JWT en `localStorage` : plus simple, mais expose le jeton au JavaScript et rend
la révocation immédiate difficile. Le gain ne compense pas la perte sur une
plateforme qui hébergera des paiements.

## Reconnaissance du premier tiers — écart assumé vis-à-vis de Sanctum
Sanctum décide qu'une requête est first-party en lisant son en-tête `Referer`
ou `Origin`, et n'active la session que dans ce cas. Cette règle vise une autre
topologie : un SPA qui appelle l'API **depuis** le navigateur. Ici, le
navigateur n'appelle jamais l'API — il appelle le BFF, qui relaie de serveur à
serveur, sans `Referer` ni `Origin`. Appliquée telle quelle, la règle de
Sanctum conclut « requête tierce », n'ouvre aucune session, et l'authentification
par cookie ne fonctionne pas du tout.

`App\Http\Middleware\EnsureBffRequestsAreStateful` inverse donc la valeur par
défaut : **aucune origine annoncée = appel du BFF = first-party**. Dès qu'une
origine EST annoncée, le filtre d'origine de Sanctum s'applique sans
modification. La sécurité tient parce que le navigateur impose l'en-tête
`Origin` sur toute requête inter-origine de changement d'état : une page tierce
ne peut pas se faire passer pour le BFF en omettant l'en-tête. La validation du
jeton CSRF reste par ailleurs en place dans la pile.

## Conséquences
- Nuxt appelle `/sanctum/csrf-cookie` avant la première écriture et envoie
  `credentials: 'include'`.
- Le BFF ne doit **pas** recopier tel quel l'`Origin` du navigateur vers l'API :
  il enverrait une origine tierce sur un appel qui n'en est pas un.
- `SANCTUM_STATEFUL_DOMAINS` et `SESSION_DOMAIN` doivent être exacts par
  environnement, sinon aucun cookie n'est posé.
- La signature des liens signés doit être calculée sur l'URL publique reçue
  après proxy, pas sur l'URL interne (à tester derrière Nitro).
