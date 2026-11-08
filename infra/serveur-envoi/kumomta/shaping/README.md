# Modèle de shaping par fournisseur (K8)

`shaping.modele.toml` décrit, pour `gmail`, `microsoft` et `autre`, les débits, les connexions et le TSA
(réduction automatique puis suspension). `valider_shaping.py` le valide (stdlib seule, Python >= 3.11) ;
la CI (`serveur-envoi-shaping.yml`) fait échouer toute PR dont le modèle est invalide.

## Limite connue — ce n'est PAS du format KumoMTA

Ce modèle est un **format interne** du dépôt : KumoMTA ne sait pas le lire. Il n'est branché sur aucun serveur.
La **traduction vers la configuration réelle de KumoMTA** (shaping helper, règles par domaine de destination)
est **prévue** et n'est pas faite : dette **K18 / K9-suite**. Tant qu'elle n'existe pas, valider ce fichier garantit
sa cohérence interne, pas son effet sur un serveur.

Aucun nom de domaine réel ni adresse IP n'y figure (seuls `.test` et `.example` sont tolérés).
