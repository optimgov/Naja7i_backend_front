# naja7i.ma — visite locale

## Démarrer
```
cd ~/Coding && ./naja7i-demo.sh
```
La première fois compte 2-3 minutes (Docker, migrations, semis de 20 questions par
la chaîne éditoriale). Ensuite ~30 secondes. `./naja7i-demo.sh stop` pour arrêter,
`./naja7i-demo.sh reset` pour repartir d'une base neuve.

Le mot de passe est le même pour tous les comptes : `Recette-FRONT3-2026!`

## Les comptes

| Rôle | E-mail | Sert à |
|---|---|---|
| Candidat A | `recette.a@naja7i.test` | le parcours complet côté candidat |
| Candidat B | `recette.b@naja7i.test` | un second candidat (reprise, isolation) |
| Auteur | `editorial.auteur@naja7i.test` | rédiger dans /admin |
| Relecteur | `editorial.relecteur@naja7i.test` | relire dans /admin |
| Valideur | `editorial.valideur@naja7i.test` | valider et publier dans /admin |

Un compte que vous créez vous-même passe par la vérification d'e-mail : le lien
arrive dans **Mailpit** (http://localhost:8025), pas dans une vraie boîte.

## Le chemin de visite — front-office (http://localhost:3000/fr)

1. **Accueil sans compte** — la démonstration de correction sur la page ; le
   catalogue via « Concours » ; les annonces via « Opportunités » (fixture du
   8 août, tant que votre relecture des 44 fiches n'est pas branchée).
2. **Connexion** en Candidat A → tableau de bord.
3. **Diagnostic** — lancez, répondez en déclarant votre certitude à chaque fois.
   Faites exprès une ou deux erreurs « sûr » : c'est ce qui rend l'ordonnance
   intéressante.
4. **Correction** — l'écran signature : chaque option justifiée, la cause de vos
   erreurs (2 gratuites, puis « réservé aux abonnés » : c'est le mur payant).
5. **Maîtrise** et **Ordonnance** — la carte par domaine, et quoi réviser.
6. **Cliquez une ligne d'ordonnance** → série d'entraînement ciblée.
7. **Révisions** — vide le premier jour (les rendez-vous mémoire tombent à J+1) ;
   pour en voir : `cd ~/Coding/Naja7i_backend_front && php artisan tinker
   ../Naja7i_frontend/scripts/recette/echoir-revisions.php` puis rechargez.
8. **Examen blanc** — le seuil, la passation chronométrée, le rapport avec la note.
9. Basculez **العربية** et le **thème sombre** en haut de page — tout suit.

## L'abonnement — la visite commerciale

Un script prépare l'état, puis s'efface : les clics sont les vôtres.

```
cd ~/Coding/Naja7i_frontend
node scripts/recette/demo-abonnement.mjs
```

Il épuise le quota gratuit du Candidat A (sans quoi le mur payant ne s'affiche
pas et vous montreriez une correction ouverte en annonçant un mur), retire un
abonnement laissé par une visite précédente, tire un **code cadeau** par le
générateur du produit, et affiche le chemin à suivre avec ses URL.

Le point à montrer est le TEMPS HUMAIN : saisir le code crée une commande **en
attente**, et rien d'autre — la cause reste fermée. Un coupon qui ouvrirait seul
serait de la monnaie au porteur.

Puis, dans le rôle de l'équipe, au choix :

```
node scripts/recette/demo-abonnement.mjs --valider     # le même service que Filament
```
ou http://localhost:8000/admin → Commandes → Valider.

Le candidat recharge : la cause est ouverte, le mur a disparu du rendu.

`--rendre` remet le compte à zéro pour rejouer la visite.

Le paiement simulé (sans coupon) n'apparaît qu'en développement — il n'est pas
dans le bundle de production, et la recette le vérifie en grepant `.output/`.

## Le back-office (http://localhost:8000/admin)

Connectez-vous en Auteur : la file de rédaction, une question à créer, le plan de
rédaction (couverture) en accueil. En Relecteur : la file « à relire ». En
Valideur : valider puis publier — la publication refuse si la source n'est pas
vérifiée (registre des sources).

## Le collecteur d'annonces (terminal)
```
cd ~/Coding/Naja7i_opportunites
.venv/bin/python -m naja7i_opp.cli relire --operateur redouan --gisement concours
```

## Ce que la démo ne montre pas encore
Un vrai encaissement (le coupon remplace le prestataire de paiement, qui reste à
brancher) · un vrai e-mail sortant (Mailpit intercepte tout) · du contenu en
volume (20 questions de recette) · le déploiement.
