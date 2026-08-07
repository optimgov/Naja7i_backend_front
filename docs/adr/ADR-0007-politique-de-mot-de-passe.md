# ADR-0007 — Politique de mot de passe : 12 caractères, anti-fuite obligatoire

**Statut :** accepté · 7 août 2026 — arbitrage OptimGov
**Contexte :** revue externe, BLOC-5.

## Décision

- **Minimum 12 caractères**, maximum 128.
- **Aucune règle de composition** : ni majuscule, ni chiffre, ni symbole
  imposés. Ces règles produisent des substitutions prévisibles (« Passw0rd! »)
  sans gain mesuré.
- **Espaces et Unicode autorisés** : une phrase de passe en arabe est valide.
- **Vérification obligatoire contre les bases de fuites** (`uncompromised()`).
- **Aucune expiration périodique.**

## Écart assumé, et pourquoi

NIST SP 800-63B-4 exige **15 caractères** pour une authentification à facteur
unique — ce qui est notre cas au PAS-2. Nous retenons 12.

La demande initiale était « faire comme les GAFA », dont le minimum réel est de
8 caractères. Mais ces 8 caractères reposent sur une infrastructure que nous
n'avons pas : second facteur généralisé, détection de connexion anormale par
appareil et géolocalisation, blocage automatique sur fuite. NIST autorise
d'ailleurs 8 caractères **uniquement** en présence d'un second facteur. Copier
la longueur sans les défenses nous laisserait plus faibles que les deux
références à la fois.

12 caractères avec contrôle anti-fuite est le compromis retenu : la friction
reste acceptable à l'inscription, et le contrôle anti-fuite écarte les mots de
passe réellement dangereux bien mieux que la longueur seule.

**En conséquence, nous ne revendiquons pas la conformité NIST** tant qu'un
second facteur n'est pas proposé. Toute communication produit qui l'affirmerait
serait fausse.

## Chemin de sortie

Quand le MFA sera disponible (lot sécurité), deux options s'ouvriront : rester
à 12 en devenant conforme, ou monter à 15 pour les comptes sans second facteur.
Le seuil est dans `config/naja7i.php`, pas dans le code.

## Note technique

bcrypt tronque silencieusement au-delà de 72 octets — une phrase de passe en
arabe (2 à 3 octets par caractère) peut dépasser ce seuil dès 25 caractères.
Le projet utilise donc **argon2id** (`HASH_DRIVER=argon2id`), qui n'a pas cette
limite.
