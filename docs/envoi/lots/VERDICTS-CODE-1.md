# VERDICTS-CODE-1 — vérificateur interne, écrivain, préparation

Cahier : `VERDICTS-MIGRATION` fusionnée (#610), conception #607. Aucune planification ici (CODE-2).

## Ce que fait le lot
- `App\Envoi\Verdicts\VerificateurInterne` (source `interne-v1`) : syntaxe, jetable, rebond dur connu,
  rôle technique, boîte générique (`risque/generique`, choix par défaut), puis DNS (cache 7 jours, quota,
  garde du domaine témoin). DNS sans réponse : rien n'est écrit.
- `EcrivainVerdicts` : INSERT seulement, contexte d'espace vérifié avant tout insert, pas de doublon avant 20 jours.
- `PreparationVerdicts::pourContacts` + `permetInscription` : ce que le lot L4 appellera avant d'inscrire
  (à reporter dans le cahier L4 ; compteur `adresse_non_verifiee`).
- `VerdictsAdresseServiceProvider` : `VerificateurAdresse` → `VerificateurInterne`, `QuotaDns` scoped.
  `EnvoiServiceProvider` non touché.
- Ajout du pilotage : migration `2026_12_05_000510` = `REVOKE UPDATE, DELETE` du rôle applicatif sur
  `envoi_verdicts_adresse` (rejouable, `down()` inerte, entrée NR-17). Audit : aucun code applicatif ne fait
  UPDATE/DELETE sur cette table (lectures : `Sequenceur`, `EnvoiAutorise` ; insert : `EssaiABlanc`).

## Garanties
Aucun réseau hors DNS (test T1.9), aucune connexion SMTP, aucune adresse en clair stockée ni journalisée,
aucun drapeau ni configuration touchés, `ENVOI_REEL_ACTIF` inchangé.

## Hors lot
Rafraîchissement planifié (CODE-2), vérificateur externe payant (ESCALADE 2), écran B8.
