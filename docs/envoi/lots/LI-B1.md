# LI-B1 — dépôt des prospects : ajout, rapprochement, gestes journalisés

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section LI-B1.
Dépend de PL-M0B (tables `pl_prospects`, `pl_journal`, en ligne), de LI-A (`EtatsProspect`) et de LI-L3 (`StatutOpposition::profilsOpposes`),
tous fusionnés. Aucune migration. Aucun appel vers LinkedIn, aucun outil tiers, aucun envoi, aucun drapeau touché.

## Fichiers (seuls ceux-là sont touchés)
- C `backend/app/ProspectionLinkedIn/DepotProspects.php`
- C `backend/app/ProspectionLinkedIn/Rapprochement.php`
- C `backend/app/ProspectionLinkedIn/JournalProspection.php`
- C `backend/tests/Feature/ProspectionLinkedIn/DepotProspectsTest.php`
- C `docs/envoi/lots/LI-B1.md`

Aucune PR ouverte ne porte ces fichiers (vérifié au moment de pousser).

## Comportement
- `DepotProspects::ajouter(ws, expediteur, profil, source, source_ref, source_le, entreprise?, siren?)` rend
  `{refus, cree, prospect_id, contact_id}`. Refus nommés (aucune ligne) : `profil_invalide`, `source_invalide`, `source_ref_invalide`,
  `siren_invalide`, `entreprise_invalide`, `date_invalide`, `oppose`. Le contrôle d'opposition lit le registre ; toute erreur de lecture
  lève une exception (échec fermé, rien créé). Doublon `(expediteur, slug)` : rend l'existant (`cree = false`).
- `Rapprochement::contactPour(ws, slug)` : lecture à la volée de `contacts.linkedin_url` (préfiltre LIKE, confirmation par
  `LinkedinSlug::normalize`), filtrée par `workspace_id` ; un seul contact ⇒ `contact_id` ; plusieurs ⇒ aucun et le journal d'ajout
  porte `rapprochement_ambigu = true` (l'énumération `evenement` de `pl_journal` n'a pas de valeur dédiée : c'est un détail de l'événement `ajout`).
- `DepotProspects::appliquerGeste(ws, prospect_id, geste, cle_idempotence, auteur, details)` : transaction, `SELECT … FOR UPDATE`
  filtré `workspace_id`, `EtatsProspect::appliquer`, ligne de journal (clé unique : rejeu ⇒ résultat précédent, `rejeu = true`),
  `UPDATE` de l'état seulement si changement. Prospect d'un autre espace ⇒ `RuntimeException` « introuvable ».
- `JournalProspection` : seul écrivain de `pl_journal` ; `details` filtrés par liste blanche de clés, valeurs sans adresse.

## Tests (rôle applicatif `pgsql_app` ET propriétaire)
Trois formes d'URL ⇒ un prospect ; opposé ⇒ refus, 0 ligne ; entrées invalides ; registre illisible ⇒ exception ; contact rattaché ;
deux contacts ⇒ aucun + journal ; autre espace jamais rattaché ; double clic ⇒ 1 ligne ; `a_repondu` avant `message_fait` ;
`ecarte` puis `invitation_acceptee` ; geste inconnu ; espace B ⇒ introuvable ; clé d'un autre prospect ; `FOR UPDATE` avant `UPDATE`
(journal des requêtes) ; liste blanche du journal ; aucune suppression.

## Preuve rouge puis verte
Premier commit = tests + doc seuls (rouge attendu : classes absentes), second commit = code (vert attendu). Voir les commentaires de la PR.

## Mutations demandées (à rejouer en CI sur demande du relecteur)
Contrôle d'opposition à l'ajout retiré ⇒ test « opposé » rouge ; `FOR UPDATE` retiré ⇒ test de verrou rouge (le test de concurrence à deux
connexions n'est pas jouable dans la transaction englobante de `RefreshDatabase` : le verrou est prouvé par l'ordre des requêtes ;
ESCALADE possible si le relecteur veut un banc à deux connexions) ; unicité de clé ignorée ⇒ test « double clic » rouge.
