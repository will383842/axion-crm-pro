# S1-A — Schéma de fin d'envoi, partie A (vague 1)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md` (branche `plan/fin-envoi`), fiche S1-A. Cette PR est la version « S1 » de la demande, découpée comme la dernière version du plan le prévoit (S1-A, S1-B, S1-C, une par vague).

## Fait
- `2026_12_03_000010_envoi_compteur_espace.php` (D7) : table `envoi_compteur_espace` (sans clé étrangère, RLS forcée, SELECT seul pour le rôle applicatif) ; fonction `envoi_compteur_espace_reserver` (refus sans écrire si `p_ws` ≠ espace courant ou sans contexte ; plafond effectif `LEAST(plafond, 300) < 1` ⇒ faux sans écrire ; incrément atomique) ; `envoi_injection_liberer` remplacée : elle rend aussi la place d'espace, jamais sous 0, une fois par réservation.
- `2026_12_03_000060_envoi_tentatives_retention.php` (D16) : table `envoi_tentatives_jours` (ajout seul, clé `UNIQUE NULLS NOT DISTINCT`) ; fonction `envoi_tentatives_archiver(p_ws, p_avant)` (agrège puis supprime, filtre explicite sur l'espace, jours entiers, bornes en Europe/Paris, variable `envoi.archivage_tentatives` posée par la clause `SET` de la fonction) ; déclencheur d'ajout seul de `envoi_tentatives` remplacé (DELETE accepté seulement si variable `on` et ligne de plus de 400 jours ; `RETURN OLD` ; UPDATE et TRUNCATE toujours refusés).
- `2026_12_03_000070_envoi_sequences_gestes.php` (D6) : table `envoi_sequences_gestes` en ajout seul, RLS forcée, motif sans `@`.
- Filet : `SemeurTablesScopees`, `NR17_RETOUR_INERTE_VOULU`, `RlsTest` ; `SchemaFinEnvoiATest` (15 tests).

## Reste (autres PR du plan)
- `000030`, `000040`, `000050`, `000080` : S1-B (vague 2). `000020` : S1-C (vague 3).
- Appel de la butée par `PasserelleLocale` : P1-PLAFOND. Commande d'archivage et planification à 04:50 : R06-ARCH. Pause/reprise de séquence : T-SEQ.

## Risques
- Les fonctions `SECURITY DEFINER` filtrent explicitement l'espace (le propriétaire contourne la RLS s'il est SUPERUSER ou BYPASSRLS, cas actuel à ne pas supposer). Les barrières de droits ne valent que si `CRM_DB_APP_ROLE_ENABLED=true`.
- Entre la fusion de S1-A et celle de P1-PLAFOND, `envoi_injection_liberer` peut rendre une place d'espace qui n'avait pas été comptée : le plancher à 0 évite toute valeur négative.
- `envoi_tentatives_archiver` lève une erreur (au lieu de rendre faux) sur un refus : un appel erroné se voit.
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée existante modifiée.

## Tests lancés
`SchemaFinEnvoiATest`, `Nr17MigrationsTest`, `RlsTest`, `PortabiliteCompleteTest`, `PrivilegesDeLaFonctionDefinerCouvertureTest`, `tests/Feature/Envoi`, PHPStan, Pint.
