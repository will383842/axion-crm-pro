# T-SEQ — Trous des séquences (4) — décision D6

## Fait
- `GET /envoi/sequences`, `GET /envoi/sequences/{id}` : compteurs seulement (inscriptions, envoyés, réponses, rebonds durs, désinscriptions par étape) ; aucun corps de message, aucune personne. Permission `users.manage`.
- `POST /envoi/sequences/{id}/pause` : `users.manage`. `POST /envoi/sequences/{id}/reprise` : `workspaces.manage` (méthode `reprise`, jamais `reprendre`).
- `DepotSequences` : l'état d'une séquence est son dernier geste dans `envoi_sequences_gestes` (ajout seul, migration S1-A). Geste pris sous verrou consultatif par séquence, auteur tracé, rejeu sans nouvelle ligne.
- La reprise répond 409 sous arrêt général (`frein_arret_general`) ou sous une pause active, frein ou pause automatique (`frein_pause_automatique`). Elle ne lève jamais un frein : seule la commande locale `envoi:lever-pause` le fait.
- `Sequenceur::executer` saute les séquences en pause (filtre dans la requête) : leurs inscriptions ne sont pas traitées et leur compteur de reports ne bouge pas.
- Les 4 trous de `trous/sequences.php` sont comblés (fichier vidé) ; instantané `instantane-routes-envoi/sequences.json` créé.
- Aucune migration dans cette PR.

## Reste
- Le contrat S2-CONTRATS (statuts 4xx, schémas) n'est pas encore vérifié sur les corps d'erreur : statut seul.
- `motif_pause` ne rend que `manuelle` (une séquence n'est mise en pause que par ce geste) ; `frein`, `arret_general` et `automatique` du contrat s'expriment par `peut_reprendre` et `raison_refus_reprise`.
- Les évènements (réponse, stop, rebond dur) ne portent pas l'étape : ils sont comptés à l'étape courante de l'inscription.

## Risques
- Une pause posée entre la lecture des échéances et le traitement d'une inscription ne l'arrête pas pour ce passage (une inscription au plus par cycle) ; la fiche limite `Sequenceur.php` au filtre de `executer`, `traiter` n'est pas modifié.
- Tout reste fermé : `ENVOI_REEL_ACTIF=false`, aucune route ne résout `ServeurEnvoi`, aucun message réel.

## Tests lancés
- Rouges d'abord (avant le code) : `SequencesRoutesTest` (4 rouges, 404 faute de routes), `SequencePauseSequenceurTest` (2 rouges, inscription traitée malgré la pause).
- Verts après : ces deux fichiers, `tests/Feature/Envoi` complet, `tests/Feature/NonRegression` (dont `EnvoiSequenceurFigeTest`), `RlsTest`, `CouvertureContratEnvoiTest`, `RoutesEnvoiInstantaneTest`.
- `pint --dirty` ; PHPStan sur les fichiers touchés (aucune erreur propre à la PR).
