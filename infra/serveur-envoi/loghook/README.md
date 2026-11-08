# Log hook KumoMTA -> passerelle (lot K7)

Chaîne : KumoMTA (`kumomta/loghook.lua`, 5 types d'enregistrement) -> écouteur local `127.0.0.1:2580` (`axion_loghook.serveur`) -> classifieur (règles TOML) -> `public/loghook.php` de la passerelle (127.0.0.1 seulement) -> `Evenements\Ingestion` -> `Stockage::ingererLoghook` (une transaction SQLite). K1 tire ensuite `GET /v1/evenements` : la passerelle ne pousse rien. **Aucun envoi réel, aucune action sur les adresses.**

## Règles de rebonds (`rebonds/*.toml`)
Première règle qui correspond gagne ; `defaut_bounce` et `defaut_transitoire` obligatoires ; classes : `rebond_dur`, `rebond_mou`, `report`. Un `Expiration` n'est jamais dur. CI : `python3 -m axion_loghook.valider_toml rebonds` — TOML invalide, classe inconnue, défaut absent ou dossier sans `.toml` = CI rouge.

## Garanties (tests)
- Séquence sans trou ni doublon par espace, y compris après redémarrage ; l'époque est dans `event_id` (K2). Dédoublonnage par (espace, source, `id_kumo`, type). Un désordre garde tout ; un trou dans `src_seq` de la source lève l'alerte `loghook_trou_source`.
- Le relais numérote `src_seq` (fichier d'état, fsync) ; un rejeu après panne du puits garde le même numéro.
- **Mode observation** : les 50 premiers `rebond_dur` de chaque espace portent `observation=true`. `action_auto` vaut TOUJOURS `aucune` : la passerelle n'agit jamais. Tout est dans `loghook_journal` (ajout seul, déclencheurs).
- Aucune adresse en clair : seule l'empreinte SHA-256 du destinataire sort.
- Jetons K6 : à la réception, l'interface `JetonsConfirmation::confirmer` fait passer `reserve -> confirme`. Un échec n'arrête jamais la séquence (alerte `loghook_jeton_echec`).

## Dettes nommées
- **K7-D1** : K6a (`jetons.lua`) non fusionné -> seule l'interface existe, défaut `JetonsNonDisponibles` ; chaque jeton reste `jeton_en_attente` au journal, à rejouer quand K6a arrive.
- **K7-D2** : `loghook.lua` écrit d'après l'API documentée de KumoMTA, non exécuté ici (pas de KumoMTA) : test statique seulement ; à passer au banc K10.
- **K7-D3** : décision du responsable pour la sortie du mode observation (après 50 rebonds durs, quelle action K1 peut-elle appliquer ?). Seuil actuel : champ `observation` seulement.
- **K7-D4** : nginx (K2-nginx) doit n'exposer `loghook.php` qu'en local ; le code refuse déjà toute autre adresse.
