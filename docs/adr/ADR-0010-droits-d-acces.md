# ADR-0010 — Droits d'accès : un contrat unique, posé avant les ressources

**Statut :** accepté · 8 août 2026
**Contexte :** l'ADR-0003 sépare le rôle du droit commercial, sans en donner
l'implémentation. Le catalogue et la banque de questions arrivent : c'est le
dernier moment où cette pièce peut être posée sans reprise.

---

## Le problème que cette décision évite

Sans contrat unique, chaque ressource invente sa propre vérification :
`if ($user->subscription?->isActive())` dans le contrôleur des séries,
autre chose dans celui du simulateur, autre chose encore dans les corrections.
Le jour où le paiement arrive, il faut toutes les reprendre — et il en restera
toujours une oubliée, qui sera la faille.

C'est pourquoi ce contrat se pose **maintenant**, avant le premier contenu
payant, et non avec le module de paiement.

## Décision

### Une seule question, posée partout de la même façon

```php
interface AccessGrant
{
    public function allows(User $user, string $capability, ?Model $resource = null): bool;
}
```

Une **capacité** est un droit d'usage produit, distinct d'une permission RBAC.
Le registre normatif et ses codes exacts sont fixés par l'ADR-0030 ; les
exemples historiques `corrections.full` et `coach.access` sont supersédés et ne
doivent jamais être persistés. Le nommage reste délibérément différent des
permissions (`questions.publish`) pour qu'aucune confusion ne s'installe.

### Ce qui accorde une capacité

Trois sources, évaluées dans cet ordre :

1. **Le niveau d'accès du compte** — public, gratuit, premium. Couvre le B2C.
2. **Un octroi explicite** — table `access_grants` : utilisateur, capacité,
   portée éventuelle, date de début, date de fin, origine. Couvre les achats,
   les codes promotionnels, les gestes commerciaux et les accès accordés par un
   centre partenaire à ses inscrits.
3. **Un quota** — certaines capacités ne sont pas binaires : le compte gratuit
   a droit à un nombre de questions par jour. Le quota est porté par la capacité,
   pas dispersé dans les contrôleurs.

### Règles non négociables

- **La vérification est serveur, toujours.** L'interface affiche un état ; elle
  ne l'établit pas. Un mur payant masqué côté client n'est pas un contrôle.
- **Aucun rôle ne confère une capacité.** Un rôle dit qui vous êtes. Un octroi
  dit ce que vous avez obtenu. Le jour où quelqu'un proposera un rôle
  `premium`, c'est cet ADR qu'il faudra invoquer.
- **Un octroi n'est jamais modifié en place.** Une prolongation crée un nouvel
  octroi ; une révocation pose une date de fin. L'historique doit permettre de
  répondre à « de quoi disposait ce candidat le 14 mars ». Même discipline que
  les actes juridiques (ADR-0005).
- **La capacité est vérifiée au moment de l'usage**, pas mise en cache dans la
  session. Un abonnement expiré pendant une session doit prendre effet.

### Avant le paiement

Tant qu'aucun paiement n'existe, une implémentation lit le niveau de compte et
les octrois manuels. Les contrôleurs, eux, sont écrits contre l'interface dès
le premier contenu payant. Brancher CMI ne changera alors qu'une source
d'octroi — aucun contrôleur.

## Ce que ce choix coûte

Une indirection supplémentaire dès maintenant, pour un bénéfice différé. C'est
assumé : le coût de l'ajouter après coup est d'un ordre de grandeur supérieur,
et il se paie en failles d'accès plutôt qu'en temps de développement.

## Tests d'acceptation

- Un compte gratuit reçoit 403 sur une capacité premium, avec un code d'erreur
  contractuel distinct de l'authentification.
- Un octroi expiré ne donne plus accès, sans qu'aucune tâche planifiée n'ait à
  s'exécuter.
- Un quota épuisé bloque, et le compteur est visible du candidat.
- Retirer un rôle ne retire aucune capacité, et inversement.
- L'historique des octrois permet de reconstituer les droits à une date passée.
