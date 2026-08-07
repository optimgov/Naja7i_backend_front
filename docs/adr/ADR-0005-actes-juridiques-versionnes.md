# ADR-0005 — Actes juridiques versionnés, et non « consentements »

**Statut :** accepté · 7 août 2026 — remplace la conception initiale du PAS-2
**Contexte :** revue externe, BLOC-4. Loi 09-08.

## Problème

La conception initiale rangeait trois choses sous le mot « consentement »,
avec un état lu comme « dernière ligne par (user, type) ». Deux défauts :

1. **Une acceptation de la v1 satisfaisait indûment la v2.** Publier une
   nouvelle politique ne faisait rien apparaître : la dernière ligne restait
   valide alors que le document publié avait changé.

2. **La qualification juridique était fausse.** `service_terms` était présenté
   comme un consentement obligatoire — or si le candidat ne peut pas refuser,
   ce n'est par définition pas un consentement. Le traitement nécessaire à la
   fourniture du service repose sur l'exécution du contrat, pas sur le
   consentement.

## Décision

Trois actes de nature distincte, nommés pour ce qu'ils sont :

| Acte | Nature juridique | Refusable ? |
|---|---|---|
| `terms_accepted` | Acceptation contractuelle | Non — pas de compte sans |
| `privacy_notice_acknowledged` | Prise de connaissance | Non — information due |
| `marketing_granted` / `marketing_withdrawn` | Consentement au sens strict | Oui, à tout moment |

- `legal_documents` porte le document publié : type, **version**, **langue**,
  texte, **empreinte SHA-256**, date de publication. Immuable.
- `legal_events` porte l'acte, référençant le **document exact**. Jamais
  modifié ni supprimé : un retrait crée un événement, il n'efface pas l'octroi.
- **L'état courant se calcule contre le document publié**, jamais par type
  seul. Une nouvelle version fait automatiquement apparaître l'acte en attente
  dans `pending_legal_actions`.
- **Pas de `tenant_id`** sur ces tables — exception assumée à la règle
  « activité = tenant_id ». C'est le DOCUMENT qui porte le responsable de
  traitement. Dupliquer la portée sur l'événement créerait deux sources de
  vérité potentiellement contradictoires. Quand un centre publiera ses propres
  CGU, c'est `legal_documents` qui identifiera son émetteur.
- **IP tronquée** (/24, /48) et user-agent en **HMAC** et non en hash nu : un
  user-agent a une diversité faible et un SHA-256 se recalcule par dictionnaire.
  La preuve principale reste utilisateur + document + version + empreinte +
  horodatage + request_id.

## Limite connue

Les textes actuellement en base sont **provisoires** et marqués comme tels
(`version = 0.1-provisoire`, champ `provisional` exposé par l'API). Le
développement peut avancer ; la mise en ligne est bloquée tant que les textes
FR et AR validés juridiquement ne sont pas fournis. Voir DET-07.

La qualification juridique retenue ici est une décision d'architecture, pas un
avis juridique : elle doit être confirmée par un conseil marocain avant
ouverture publique.
