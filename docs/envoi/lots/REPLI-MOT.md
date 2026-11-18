# Lot REPLI-MOT — repli sur l'adresse générale : mots composés et adresses partagées

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680 (relectures de #743 et #751). Aucune migration, aucun drapeau.

## Ce que fait le lot
- `RepliGenerique` : les termes exclus sont cherchés en sous-chaîne après le mot de la liste blanche. `contact.relationspresse` est refusé ; `contact@` reste admis. Effet voulu et prudent : un suffixe qui contient un terme court (`rh`, `hr`) est aussi refusé (`contact.rhone`).
- `SelectionDestinataires` (la ligne des adresses partagées seulement) : la clé `$partagees` passe par `QualificationEmail::normaliser()`, comme la lecture. Deux fiches portant « ␠Info@X » et « ␠info@x » sont vues comme une adresse partagée.

## Tests (rouges d'abord ; sélection jouée sous `pgsql_app` ET sous le propriétaire)
`tests/Unit/Envoi/RepliGeneriqueMotsComposesTest.php`, `tests/Feature/Envoi/SelectionAdressePartageeNormaliseeTest.php`. Voisins (`tests/Unit/Envoi`, `SelectionRepliGeneriqueTest`) : 662 verts.

## Mutations (rouges, restaurées)
Retour à `array_intersect` ⇒ 7 rouges ; retour à `mb_strtolower` ⇒ 2 rouges.
- Relecture Opus n°2 : la détection D10 compare par empreinte (`empreinte_adresse_sql`, index `idx_contacts_empreinte_email`) : « ␣Info@X » et « info@x » (sans espace) sur deux fiches sont la même adresse. Tests ajoutés : cas du plan et cloisonnement par espace.
