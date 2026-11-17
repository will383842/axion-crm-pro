# Lot D2 — rattachement d'une réponse à son inscription (sous-lot D2-1)

Cahier : `docs/envoi/lots/D1-D2-cahier.md` §6, PR #617 (branche `conception/d1-imap`). Ne pas confondre avec l'ancien `D2.md` (dettes du feu vert).

## Fait (D2-1)
- `ServiceReponses::rattacher` : l'identifiant est accepté s'il existe, pour l'espace, dans `envoi_injections_smtp` OU `envoi_injections_locales` (même règle que le déclencheur `2026_12_03_000080`). Avant : `inconnue_injection` pour tout identifiant du registre (faux serveur, Kumo).
- `DepotBoites::resumer` : `reponses_7j` compte `boite IN (<UUID de la boîte>, <adresse>)` (la relève écrit l'UUID ; l'adresse est gardée pour d'anciennes lignes). Avant : toujours 0.
- `MessageOrigine` : commentaire et message corrigés (UUID du registre). Aucun changement de règle.
- Tests R5 (3 cas) et R6 (2 cas) dans `RattachementReponseD2Test`, sous le rôle applicatif ET le propriétaire.

## Fait (D2-2)
- Preuves du § 6.2 sur la chaîne entière (séquenceur → faux serveur → registre → `ReleveImap` avec `LecteurBoiteMemoire` → arrêt), sous le rôle applicatif ET le propriétaire :
  R1 (réponse par `In-Reply-To` : événement `rattache`, inscription `arretee` motif `reponse`, collègue de l'entreprise arrêté comme celui qui répond),
  R2 (VERP signé rattache ; sans VERP ni `In-Reply-To` : « à rattacher », aucun arrêt), R3 (ancienne forme : `sim-7` + UUID local de clé `seq-<inscription>-1`),
  R4 (cloisonnement : réponse citant le Message-ID d'un autre espace ; même Message-ID dans l'espace B), R7 (aucune adresse en clair dans `envoi_evenements` ni dans les journaux).
- `ServiceReponses::BASE` : `boite_id` joint `b.adresse = e.boite OR b.id::text = e.boite` (la relève écrit l'UUID : avant, `boite_id` valait NULL, donc « inconnue »). Le fichier était autorisé ; c'est le contrat § 6.1 qui ne couvrait pas cette jointure (correction de la note de D2-1).
- Remarque Opus de #650 (point 1) : le test R6 ajoute un événement de l'espace B portant l'UUID de la boîte de A ; `reponses_7j` rouge si `where workspace_id` disparaît.
- Test de liste : `boite_id` correct pour l'UUID et pour l'ancienne forme (adresse), jamais une boîte d'un autre espace.

## Conditions d'activation (remarque Opus de #650, point 3)
Le § 9 du cahier exige « D2 fusionné » avant d'allumer `ENVOI_RELEVE_ACTIF` : lire **D2-1 ET D2-2 fusionnés**. La relève ne doit pas être allumée avant D2-2 (et L5 pour STOP, plainte, rebond).

## Reste de D2
D2 complet (aucun code restant dans le cahier § 6). Hors D2 : L5, L6, D4, D6 (§ 11 du cahier).

## Mutations vérifiées
- `ServiceReponses` ne lit plus que `envoi_injections_smtp` : R5 rouge.
- Filtre `reponses_7j` sur l'adresse seule : R6 rouge.
- `where workspace_id` retiré de la lecture de `envoi_injections_locales` : R5 (UUID d'un autre espace) rouge sous le propriétaire.
- `where workspace_id` retiré de `reponses_7j` (DepotBoites) : R6 rouge (événement de l'espace B portant l'UUID de la boîte de A).
- `boite_id` joint sur l'adresse seule : test de liste rouge.
- Récepteur de séquence sans la branche « clé locale seq-<inscription>-<étape> » : R3 et R4 rouges.
- Registre sans filtre d'espace dans `retrouver` : R4 rouge (la même personne est contact de l'espace B).
- Identifiant du serveur remis dans `envoi_messages.id_injection` (essai local du `Sequenceur`, non poussé) : R1 rouge.
