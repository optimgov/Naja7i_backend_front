# ADR-0037 — Le support v1.1 ne traite que les réclamations

**Statut :** accepté · décision propriétaire du 24 août 2026
**Précise :** ADR-0034 et ADR-0035

## Décision

Le rôle `support` porte exactement `complaints.view` et `complaints.reply`.
Il consulte les réclamations internes et y répond depuis Filament. Il ne
consulte plus la banque de questions, le catalogue ou l'annuaire, et ne peut
plus assister directement un compte ni accorder ou révoquer un accès.

`super_admin` conserve aussi l'accès aux réclamations. `expert_pedagogue` et
`finance` n'y accèdent pas : le premier reste strictement éditorial et la
seconde strictement commerciale. L'identité individuelle du personnel n'est
jamais exposée au candidat : l'API ne rend que l'émetteur générique `staff`.

## Livraison atomique

La migration `000840` constitue l'étape B. Elle suit le domaine de messagerie,
ses routes candidat, son poste Filament et leurs tests croisés. Elle retire les
anciennes attributions du support sans supprimer les permissions du registre.

Son retour arrière restaure l'étape A compatible : les cinq permissions
historiques reviennent au support, tandis que les deux permissions de
réclamation restent attachées. Un rejeu resserre de nouveau le rôle.
