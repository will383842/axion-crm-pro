# S1-B — Schéma de fin d'envoi, partie B (vague 2)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche S1-B. `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée existante modifiée.

## Fait
- `2026_12_03_000030_envoi_essais_blanc.php` (D10) : table `envoi_essais_blanc` en ajout seul, RLS forcée, SELECT + INSERT, aucun `@` dans `auteur` ni dans `etapes`.
- `2026_12_03_000040_envoi_domaines_adaptateur.php` (D11) : colonne `envoi_domaines.adaptateur` (`faux` par défaut, CHECK `faux|kumo|smtp`) ; déclencheur `BEFORE INSERT OR UPDATE` (INSERT : `faux` seulement ; UPDATE de `adaptateur` refusé hors de la fonction journalisée, même pour le propriétaire ; message préfixé `envoi_domaines :`) ; la fonction `envoi_domaine_adaptateur_changer(p_ws, p_domaine, p_adaptateur, p_auteur)` (SECURITY DEFINER, refus si espace ≠ contexte ou sans contexte, filtre `workspace_id = p_ws`, journal `domaine-adaptateur` dans `envoi_journal_commandes`, variable `envoi.domaine_adaptateur` posée par la clause `SET`) ; `envoi_adaptateurs_reels_portes()` (SECURITY DEFINER, sans espace, ne rend que des noms d'adaptateur) ; `REVOKE UPDATE` de table puis `GRANT UPDATE (spf_verifie_le, dkim_verifie_le, dmarc_verifie_le)`.
- `2026_12_03_000050_envoi_feu_vert_decisions.php` (D12) : table `envoi_feu_vert_decisions` (`id` croissant : la dernière décision fait foi), ajout seul, RLS forcée, aucun `@` dans `auteur`, `motif`, `points`.
- `2026_12_03_000080_envoi_injections_locales.php` (L8-COMPO) : table `envoi_injections_locales` (unique par espace et clé, `id_injection` UUID), ajout seul, RLS forcée, SELECT + INSERT ; `envoi_verifie_id_injection` accepte un identifiant de `envoi_injections_smtp` ou de `envoi_injections_locales` du même espace (inconnu des deux : refusé, même code 23503).
- Filet : `SemeurTablesScopees`, `NR17_RETOUR_INERTE_VOULU`, `RlsTest`, fragment `colonnes-json/2026-12-03-S1-B.json` ; `SchemaFinEnvoiBTest` (14 tests).

## Colonnes de `envoi_domaines` mises à jour aujourd'hui (constat dans le code)
Seul `DepotControlesDnsDb::marquerVerifie` met à jour la table : `spf_verifie_le`, `dkim_verifie_le`, `dmarc_verifie_le`. `flux` n'est écrit qu'à l'INSERT (`EcrituresInfrastructure`) et par un test (propriétaire). Le `GRANT UPDATE` de colonnes est donc limité à ces trois.

## Reste (autres PR du plan)
- `000020` : S1-C (vague 3). Consommateurs : K17-FV, L8-COMPO (vague 3), K17-AIG (vague 4), L8-ESSAI (vague 5) ; aucun code applicatif ne lit encore ces tables.

## Risques
- Le rôle applicatif perd `UPDATE` de table sur `envoi_domaines` : tout futur code qui mettrait à jour une autre colonne devra ajouter son droit de colonne.
- Les barrières de droits ne valent que si `CRM_DB_APP_ROLE_ENABLED=true` ; sinon seuls le déclencheur et les fonctions protègent.
- Le message de `envoi_verifie_id_injection` change (il cite les deux tables) ; code SQLSTATE inchangé, aucun test existant ne lit le texte.
- Les `up()` rejoués verrouillent `envoi_domaines` : le test de rejeu tourne sur la seule connexion du test.

## Tests lancés
`SchemaFinEnvoiBTest`, `Nr17MigrationsTest` (filet), `RlsTest`, `PortabiliteCompleteTest`, `PrivilegesDeLaFonctionDefinerCouvertureTest`, `tests/Feature/Envoi`, PHPStan, Pint.
