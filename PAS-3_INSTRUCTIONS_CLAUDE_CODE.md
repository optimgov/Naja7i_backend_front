# PAS-3 — Instructions d'exécution pour Claude Code

**Dépôt :** `optimgov/Naja7i_backend_front` — état attendu : `be7ac28` (PAS-2, CI verte)
**Objet :** fermer la boucle d'authentification — envoi réel des e-mails de vérification, mot de passe oublié, en FR et AR.

**Contexte :** au PAS-2, la vérification d'e-mail bloque dès l'inscription mais
aucun e-mail n'est envoyé. Un candidat qui s'inscrit est actuellement enfermé.
Ce pas referme ce trou.

---

## DÉCISIONS PRÉ-ARBITRÉES — ne pose aucune question sur ces points

| Situation | Décision |
|---|---|
| Colima / Docker arrêté | `colima start`, `docker compose up -d` |
| Base `naja7i_test` absente | `docker compose exec -T postgres createdb -U naja7i naja7i_test` |
| Conflit sur un fichier de l'overlay | L'overlay fait foi, écraser |
| Choix d'un fournisseur d'e-mail | **Aucun.** Mailpit en local, `MAIL_MAILER=log` en CI. Le fournisseur de production est une décision ouverte (DET-09) — ne rien coder qui en dépende |
| Tentation d'utiliser les URL signées Laravel | **Non.** Jetons opaques, voir ADR-0008 §4 |
| Test rouge | Corriger le code applicatif, jamais le test |
| PostgreSQL indisponible | S'arrêter et signaler. Jamais de repli SQLite |
| Choix de nommage | Trancher toi-même |

**Arrête-toi uniquement si :** perte de données possible, dépôt incorrect, ou PostgreSQL indisponible.

---

## Étapes

1. **Vérifier le départ** : `git log --oneline` montre `be7ac28`, CI verte.

2. **Appliquer l'overlay.** Trois fusions manuelles à faire, le reste se copie :
   - `routes/api-pas3-additions.php` → **fusionner** son contenu dans le groupe
     `Route::prefix('v1')` de `routes/api.php`, section publique. Puis
     supprimer le fichier d'additions.
   - `lang/fr/auth.php.append` et `lang/ar/auth.php.append` → **fusionner** les
     clés dans les fichiers `auth.php` existants, puis supprimer les `.append`.

3. **Ajouter Mailpit** à `docker-compose.yml` :
   ```yaml
     mailpit:
       image: axllent/mailpit
       ports: ["1025:1025", "8025:8025"]
   ```
   Interface de consultation : http://localhost:8025

4. **Configurer** `.env` et `.env.example` :
   ```
   MAIL_MAILER=smtp
   MAIL_HOST=127.0.0.1
   MAIL_PORT=1025
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_FROM_ADDRESS="no-reply@naja7i.ma"
   MAIL_FROM_NAME="Naja7i.ma"
   FRONTEND_URL=http://localhost:3000
   ```
   Vérifier que `config/app.php` expose `'frontend_url' => env('FRONTEND_URL')`.
   En CI (`.github/workflows/ci.yml`), ajouter `MAIL_MAILER: log`.

5. **Brancher les notifications sur le modèle User** :
   - `sendPasswordResetNotification($token)` → `$this->notify(new ResetPasswordNotification($token))`
   - `sendEmailVerificationNotification()` → déléguer à `EmailVerificationService::send($this)`

   Ainsi l'événement `Registered` émis au PAS-2 déclenche réellement l'envoi.

6. **Migrer et tester** :
   ```bash
   php artisan migrate
   php artisan test
   ```
   Attendu : 57 tests des pas précédents + 18 nouveaux. **75 verts, 0 rouge.**

7. **Vérification manuelle** (une minute, elle vaut la peine) :
   inscrire un compte via `curl` ou l'API, puis ouvrir http://localhost:8025 —
   l'e-mail doit s'afficher, en français ou en arabe selon la locale, avec un
   lien pointant vers `localhost:3000`.

8. **Style, commit, push, CI verte** :
   ```bash
   ./vendor/bin/pint
   git add -A
   git commit -m "PAS-3: boucle d'authentification fermée — vérification d'e-mail par jeton opaque, mot de passe oublié, notifications FR/AR, Mailpit en développement"
   git push origin main
   ```

---

## Points de vigilance

- **Le jeton ne doit jamais être journalisé.** Ni dans un log, ni dans un
  message d'exception. Un test vérifie qu'il n'est pas stocké en clair ;
  aucun test ne peut vérifier qu'il n'est pas dans vos logs.
- **Réponse identique que le compte existe ou non**, sur `resend` et
  `password/request`. Ne pas « améliorer » l'expérience en distinguant les cas.
- **Un seul jeton actif par usage.** Un nouvel envoi invalide le précédent.
- **La réinitialisation régénère le `remember_token`.** C'est voulu : un mot de
  passe oublié peut signifier un compte compromis.
- **Ne rien coder qui dépende d'un fournisseur particulier** : pas de SDK, pas
  d'appel d'API propriétaire. SMTP seulement.

## Ce que ce Pas ne fait pas

OTP par téléphone, Google/Facebook, webhooks de rebond (ils attendent le choix
du fournisseur), profil progressif, catalogue.
