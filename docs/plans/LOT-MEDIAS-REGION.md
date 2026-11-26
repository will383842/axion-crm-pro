# Plan : lot médias par région (PR M1)

Statut : PLAN SEULEMENT. Aucun code n'est écrit.

## Pourquoi seulement un plan
Cette PR a besoin de deux PR précédentes, pas encore fusionnées dans `main` :
- PR 1 : compteur global de dépense ;
- PR 2 : `ClientClaude`, `PrixClaude`, `RotationCles`
  (le dossier `backend/app/Crm/Claude/` n'existe pas dans `main`).

Dès qu'elles sont fusionnées, on repart de `main` et on code ce plan.

## Constat dans le code actuel
- Les filtres `editorial_theme` et `diffusion_zone` existent déjà dans
  `MediaController` : ajouter un test API, puis les deux filtres dans
  `MediaListPage.tsx` s'ils manquent.
- Commandes à réutiliser : `MediaEnrich`, `GenerateMediaRedactionEmails`,
  `JournalistsScrapeOurs`, `CrmPresseClasserMedias`, `MediaBackfillPeriodicity`.

## Livrables prévus
1. Config sous `backend/config/` : interrupteur `CRM_MEDIAS_ENRICHIR_ACTIF`
   (faux par défaut), lu par `config()`.
2. Service sous `backend/app/Crm/Medias/` + constantes des listes fermées
   (périodicité, thème, zone de diffusion, types par défaut).
3. Commande `crm:medias:enrichir-region {--region=} {--limite=} {--a-blanc} {--types=}`.
   - Refus si `--region` absent ou interrupteur faux.
   - Périmètre : médias non supprimés, sans site et sans e-mail, types éditoriaux
     (hors production audiovisuelle et émissions), jamais marqués.
   - Marqueur dans `socials` (idempotent), reprise par curseur sur `id`.
   - Étape A : ClientClaude Haiku, recherche web 1 fois, lieu FR, JSON {site, confiance} ;
     vérification gratuite (`LecturePageAccueil`, `SsrfGuard`, `verifyBody`) ;
     sinon `website_status='not_found'`, `website_method='claude'`, rien d'autre.
   - Étape B : logique de `media:enrich`, puis e-mails génériques validés MX.
   - Étape C : journalistes (rôles publics) via ClientClaude sans recherche web,
     300 jetons max ; aucun e-mail personnel deviné ; `source`, `source_url`,
     `opt_out`, `dedup_key`. Factorisation minimale dans `JournalistsScrapeOurs.php`.
   - Étape D : classement (`media_type`, `periodicity`, `editorial_theme`,
     `diffusion_zone`) dans la même réponse JSON que l'étape C, sinon un appel
     Haiku sans recherche web (150 jetons) sur l'accueil. Hors liste = ignoré ;
     jamais d'écrasement d'une valeur ARCOM/CPPAP ; reclassement seulement de
     `presse_autre` et `portail_web`.
   - Chaque appel passe par le compteur global avant envoi ; plafond atteint =
     arrêt propre, code 6 ; bascule de clé gérée par la PR 2.
   - Mode à blanc : aucune écriture, aucun appel Claude.
   - Rapport : traités, sites trouvés/vérifiés, e-mails, journalistes, coût en
     micro-dollars, coût moyen, répartition par type et par thème.
4. Front : `frontend/src/features/media/MediaListPage.tsx`.
5. Pas de migration prévue.

## Tests (rouges d'abord, données fictives, `Http::fake` + `Http::preventStrayRequests`)
Refus sans région ; refus interrupteur faux ; production audiovisuelle ignorée ;
site non vérifié = rien écrit ; média déjà passé jamais retraité ; plafond atteint =
arrêt avant l'appel suivant ; bascule de clé ; journaliste sans e-mail deviné ;
classement (hors liste ignoré, ARCOM/CPPAP intact, `presse_autre` reclassé) ;
mode à blanc n'écrit rien.
