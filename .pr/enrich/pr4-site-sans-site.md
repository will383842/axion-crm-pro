Titre : Commande gratuite « site pour fiche sans site » (PR 4)

**PR en brouillon : les tests n'ont PAS pu tourner ici (voir « Blocage »).**

## Ce que fait la PR
Nouvelle commande `crm:entreprises:chercher-site-sans-site` : pour les fiches sans site d'un lot (1 à 7, `PerimetreEnrichissement`), elle construit des domaines candidats (`CandidatsSite::domaines`) et ne retient un candidat que si le SIREN est sur l'accueil ou les mentions légales, en arrivant sur le même domaine.

- Options : `--lot` (obligatoire), `--limite`, `--dry-run`, `--jusqua`, `--forcer`, `--paquet`, `--delai-domaine-ms`, `--timeout`.
- Même verrou que `verifier-sites` et `chercher-site-prouve`, même fenêtre de lancement, `GardeDisque` avant chaque paquet (arrêt code 4).
- Lecture seulement par `LecturePageAccueil` (robots.txt, garde SSRF, redirections revérifiées).
- Écriture (5.8) : `website`, `website_method = candidat-prouve` (jamais `guess`), `field_origins` et `field_origins_at`, marqueurs `site_entreprise` et `recherche_site`. `enriched_at` et `updated_at` ne bougent pas.
- Échec : marqueur `recherche_site {etape: candidats, resultat: aucun}` ; la fiche n'est plus reprise.
- Écriture gardée par le même WHERE que la lecture : fiche protégée ou `website` rempli entre-temps = écartée.
- `--dry-run` : aucune écriture (un `SELECT count(*)` au même WHERE).
- Non inscrite au calendrier.

## Fichiers touchés (les seuls)
- `backend/app/Console/Commands/CrmEntreprisesChercherSiteSansSite.php` (nouveau)
- `backend/app/Crm/Sites/PreuveSiren.php` (nouveau ; logique copiée de `chercher-site-prouve`, l'ancien fichier n'est pas modifié)
- `backend/tests/Feature/Crm/EntreprisesChercherSiteSansSiteTest.php` (nouveau)
- `.pr/enrich/pr4-site-sans-site.md` (ce texte)

Aucune migration, aucun instantané du filet modifié.

## Tests
Les 8 tests couvrent : SIREN sur l'accueil (écriture complète), SIREN sur un autre domaine (rien), robots.txt interdit (page non lue), adresses internes (garde SSRF, via une redirection du candidat), `--dry-run` (zéro requête d'écriture), fiche protégée ou site rempli entre lecture et écriture (écartée), disque à 86 % (code 4) et options invalides / `--limite`.

- **Rouges constatés** : seulement au sens « la commande n'existe pas encore » (voir blocage) ; je n'ai pas pu voir les tests échouer sur leur assertion.
- **Verts** : NON constatés.
- Constaté : `pint --dirty` OK, `composer analyse` (PHPStan) OK, `php -l` OK, la commande est enregistrée et refuse `--lot` absent ou invalide.

## Blocage
Pas de base de test dans cet environnement : l'hôte `postgres` n'existe pas, le cluster PostgreSQL local appartient à un autre utilisateur (je ne peux pas le démarrer), et dans un cluster que j'ai créé moi-même les extensions `postgis` et `vector` sont absentes, donc les migrations échouent. Aucun test d'intégration n'a donc tourné. À lancer en CI ou sur une machine avec la base :
`cd backend && vendor/bin/pest tests/Feature/Crm/EntreprisesChercherSiteSansSiteTest.php`

## Ce qui reste
- Faire tourner les tests et corriger si besoin.
- Taille : environ 645 lignes avec les tests, au-dessus des 400 prévues. Découpage possible : 4a (`PreuveSiren` + son test) et 4b (la commande).
- Pas de curseur persistant : les fiches cherchées portent le marqueur `recherche_site` ; à blanc et pour les fiches écartées, un ensemble d'identifiants en mémoire évite de les relire.
- Test SSRF : le DNS ne se simule pas en test ; la garde est exercée par des redirections vers des adresses internes.
- Audit « données » et « sécurité » (scraping, URL externes) à faire.
