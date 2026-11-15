# T-SEQ — Trous des séquences (4) — décision D6

## Fait
- `GET /envoi/sequences`, `GET /envoi/sequences/{id}` : compteurs seulement (inscriptions, envoyés, réponses, rebonds durs, désinscriptions par étape) ; aucun corps de message, aucune personne. Permission `users.manage`.
- `POST /envoi/sequences/{id}/pause` : `users.manage`. `POST /envoi/sequences/{id}/reprise` : `workspaces.manage` (méthode `reprise`, jamais `reprendre`).
- `DepotSequences` : l'état d'une séquence est son dernier geste dans `envoi_sequences_gestes` (ajout seul, migration S1-A). Geste pris sous verrou consultatif par séquence, auteur tracé, rejeu sans nouvelle ligne.
- La reprise répond 409 sous arrêt général (`frein_arret_general`) ou sous une pause active, frein ou pause automatique (`frein_pause_automatique`). Elle ne lève jamais un frein : seule la commande locale `envoi:lever-pause` le fait.
- `Sequenceur::executer` saute les séquences en pause : filtre dans la requête (état au début du cycle) PUIS relecture du dernier geste (même sous-requête indexée) avant CHAQUE `traiter`. Une pause posée en plein cycle arrête donc les inscriptions suivantes : elles ne sont pas traitées et leur compteur de reports ne bouge pas.
- Une séquence sans étape n'est ni listée, ni lisible, ni pausable (404) : le contrat veut `nb_etapes` de 1 à 3, le vrai compte est rendu (plus de `max(1, …)`).
- La reprise d'une séquence qui n'est pas en pause répond 200 même sous arrêt général (idempotent : aucun geste écrit, rien n'est repris). Le 409 ne vise que la reprise d'une séquence réellement en pause.
- Une pause automatique de boîte (frein, chauffe) ne compte pas comme blocage d'une séquence : seule une pause automatique ACTIVE de l'espace bloque la reprise d'une séquence en pause (`frein_pause_automatique`).
- Les 4 trous de `trous/sequences.php` sont comblés (fichier vidé) ; instantané `instantane-routes-envoi/sequences.json` créé.
- Aucune migration dans cette PR.

## Reste
- Le contrat S2-CONTRATS (statuts 4xx, schémas) n'est pas encore vérifié sur les corps d'erreur : statut seul.
- `motif_pause` ne rend que `manuelle` (une séquence n'est mise en pause que par ce geste) ; `frein`, `arret_general` et `automatique` du contrat s'expriment par `peut_reprendre` et `raison_refus_reprise`.
- Les évènements (réponse, stop, rebond dur) ne portent pas l'étape : ils sont comptés à l'étape courante de l'inscription.

## Risques
- Une pause posée pendant un cycle est vue avant chaque inscription (relecture du geste) ; seule la fenêtre entre cette relecture et l'injection de l'inscription en cours reste ouverte (ce message-là part, les suivants non). La fiche limite `Sequenceur.php` à `executer` et à son aide `sequenceEnPause`, `traiter` n'est pas modifié.
- Tout reste fermé : `ENVOI_REEL_ACTIF=false`, aucune route ne résout `ServeurEnvoi`, aucun message réel.

## Tests lancés
- Rouges d'abord (avant le code) : `SequencesRoutesTest` (4 rouges, 404 faute de routes), `SequencePauseSequenceurTest` (2 rouges, inscription traitée malgré la pause).
- Verts après : ces deux fichiers, `tests/Feature/Envoi` complet, `tests/Feature/NonRegression` (dont `EnvoiSequenceurFigeTest`), `RlsTest`, `CouvertureContratEnvoiTest`, `RoutesEnvoiInstantaneTest`.
- Bloquant 1, rouge d'abord : `SequencePauseSequenceurTest` (2 injections au lieu de 1, `Failed asserting that 2 is identical to 1`), vert après. Mineur : `SequencesRoutesTest` « séquence SANS étape » rouge (`Expecting […] not to contain …`), vert après.
- `pint --dirty` ; PHPStan sur les fichiers touchés (aucune erreur propre à la PR).

## Témoin des gardes
Action `reprise` renommée un instant en `reprendre` (contrôleur et route), `AucuneRouteTemoinSingulierTest` lancé : ROUGE, puis retour en arrière (vert).

```
────────────────────────────────────────────────────────────────────────────  
   FAILED  Tests\Feature\Envoi\Routes\AucuneRouteTemoinSingulierTest > aucun…   
  Expecting 'api/v1/envoi/sequences/{id}/r…rendre' not to contain 'reprendre'.
  at tests/Feature/Envoi/Routes/AucuneRouteTemoinSingulierTest.php:14
     10▕     expect(count(Route::getRoutes()->getRoutes()))->toBeGreaterThan(10);
     11▕     foreach (Route::getRoutes() as $route) {
     12▕         $texte = strtolower($route->uri() . ' ' . $route->getActionName());
     13▕         foreach (['temoin', 'armer', 'reprendre'] as $mot) {
  ➜  14▕             expect($texte)->not->toContain($mot);
     15▕         }
```
