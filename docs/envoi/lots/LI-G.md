# LI-G — garde d'architecture du module prospection LinkedIn

Cahier : `PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691).

Fichiers : `backend/tests/Feature/ProspectionLinkedIn/ArchitectureProspectionTest.php` ; ce compte rendu.

Le test lit les sources de `backend/app/ProspectionLinkedIn/**`, `backend/routes/prospection-linkedin.php` et
`frontend/src/features/prospection-linkedin/**`. Il interdit : appels réseau sortants (`Http::`, `curl_`, `fsockopen`,
`file_get_contents('http`, `fetch(` vers un domaine externe), les anciennes tables mortes `linkedin_*`, `cookie_blob`,
`proxy_id`, toute suppression (`->delete(`, `DELETE FROM`, `TRUNCATE`) et la lecture directe de `ENVOI_REEL_ACTIF` par `env(`.
Vert à vide tant que les dossiers sont absents. Aucun envoi LinkedIn automatisé, aucun outil tiers.
