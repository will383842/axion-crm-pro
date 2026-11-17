# Lot B8-A — API : « campagne bloquée, pourquoi »

Cahier : `docs/envoi/lots/B6-B8-cahier.md` (PR brouillon #671), section 7. LECTURE SEULE : aucune écriture, aucun geste, aucune suppression.

## Ce que fait le lot
`blocage: {code, message} | null` dans `CampagneSuivi` (liste, et `campagne` de la fiche). Une seule raison, la première vraie dans l'ordre fermé :
`reglage_non_applique`, `preparation_bloquee`, `aucun_destinataire`, `frein_arret_general`, `frein_pause_automatique`, `sequence_en_pause`.
Seulement pour `programmee`, `en_cours`, `en_pause` ; nul ailleurs. Les gestes rendent `blocage: null` (ils ne le calculent pas).

- `RaisonBlocage::reglageNonApplique($c)` est la source UNIQUE du prédicat « boîtes ou plafond » : `MaterialisationCampagne` l'appelle (une ligne), il n'en existe pas de copie.
- Liste : lectures groupées (matérialisations, derniers gestes de séquence), freins lus une fois par espace (arrêt général, pauses actives) et seulement si utiles : nombre de requêtes constant.
- Aucune adresse ni nom de personne dans les messages.

## Écart avec le cahier
La base n'accepte que 6 motifs de blocage de préparation (`jours_non_applicables`, `objet_b_non_applicable`, `sequence_incoherente`, `cible_introuvable`, `etapes_invalides`, `espace_non_emetteur`). Les trois autres motifs cités par le cahier (`cible_non_supportee`, `population_non_ouverte`, `filtre_origine_inactif`) ont aussi un message ; tout autre motif reçoit la phrase générique.

## Préalable du cahier
Le cahier place B8-A après B3-A (même fichier `MaterialisationCampagne.php`). B3-A n'est pas encore sur `main` : le changement ici est UNE ligne dans `derouler`, un conflit éventuel serait trivial à rejouer.
