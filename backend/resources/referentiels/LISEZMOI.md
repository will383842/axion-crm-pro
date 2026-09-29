# Référentiels de classement — provenance

Ces fichiers sont la **seule** table de passage « code d'activité → secteur » du CRM.
Ils sont lus par `App\Crm\Referentiels\NomenclatureNaf` (collecte INSEE, enrichissement,
reclassement de masse, écrans). Aucune autre liste de secteurs ne doit exister dans le code.

| Fichier | Contenu | Lignes |
|---|---|---|
| `secteurs.csv` | les 33 clés de secteur (31 secteurs + `interprofessionnel` + `non_classe`) et leur libellé | 33 |
| `naf_rev2_secteurs.csv` | les 732 sous-classes de la NAF rév. 2 (2008) → secteur | 732 |
| `naf_rev1_vers_rev2.csv` | les 712 codes de la NAF rév. 1 (1993/2003) → code rév. 2 retenu + secteur (règle ci-dessous) | 712 |
| `naf_rev1_groupes.csv` | repli par groupe rév. 1 (`NN.N`) pour les codes absents de la table officielle | 224 |
| `naf_rev1_divisions.csv` | dernier repli par division rév. 1 (`NN`) | 62 |
| `nap600_secteurs.csv` | les 650 postes de la NAP 600 (1973, format `NN.NN` sans lettre) → secteur | 650 |
| `metiers.csv` | les 90 métiers (clé, libellé), dans l'ordre de l'écran — chantier 2 | 90 |
| `naf_rev2_metiers.csv` | 280 sous-classes de la NAF rév. 2 → métier (les autres n'en ont pas) — chantier 2 | 280 |

## Sources officielles (INSEE)

- NAF rév. 2, liste des sous-classes (`naf2008_liste_n5.xls`) : https://www.insee.fr/fr/information/2120875
- Table de passage NAF rév. 1 → NAF rév. 2 (`table_NAF1-NAF2.xls`) : https://www.insee.fr/fr/information/2579599
- Nomenclature d'activités et de produits NAP 1973 (`nap1973.xls`) : https://www.insee.fr/fr/information/3582824

Les fichiers `.xls` ne sont pas versionnés (propriété INSEE, volumineux) : on les
retélécharge depuis ces pages pour reconstruire.

## Reconstruire

`construire.py` (Python 3, module `xlrd`) lit les trois fichiers INSEE posés à côté de lui
et écrit les CSV dans un sous-dossier `sortie/` ; on recopie ensuite ces CSV ici.
Le choix d'un secteur par division ou groupe NAF est écrit dans la fonction
`secteur_rev2()` du script : c'est la liste validée par Will le 2026-09-28
(`_FEDERATIONS/CHANTIER-1-REFERENTIELS.md`, décision « OUI PARFAIT »).

Deux gardes empêchent une dérive silencieuse :

- `tests/Unit/Crm/ReferentielsTest.php` vérifie que `secteurs.csv` et `Taxonomy::SECTEURS`
  portent exactement les mêmes clés, et que chaque secteur cité par une table de passage
  existe dans cette liste ;
- `tests/Unit/Crm/ReferentielsFrontTest.php` vérifie que le fichier généré pour l'écran
  (`frontend/src/lib/referentiels.generated.ts`) est à jour.

## Choix du lien rév. 1 → rév. 2

La table de passage INSEE marque chaque lien : « CC » (lien principal), « CA » (lien
annexe), « NC », ou rien (correspondance totale). `construire.py` retient, pour chaque
code rév. 1 :

1. les **candidats** : les liens CC ; à défaut, les liens sans précision ; à défaut, TOUS
   les liens (8 codes n'ont que des liens CA ou NC, ex. 15.9D → 11.01Z) ;
2. parmi eux, d'abord le lien « CC : tout sauf … » ; puis celui dont l'intitulé rév. 2 est
   le plus proche de l'intitulé rév. 1 (mots communs) ; puis le premier ;
3. un arbitrage manuel, documenté dans le script : 74.8K → 82.99Z.

Les replis par groupe (`NN.N`) et par division (`NN`) ne comptent que les liens retenus.
La toute première version du script prenait simplement la première ligne de la table :
74.1G (conseil) partait en agriculture. Les écarts mesurés sur la production sont dans la
description de la PR #254.

## Règle de lecture du champ `companies.naf`

| Forme | Nomenclature | Calcul du secteur |
|---|---|---|
| `NN.NNL` (ex. `62.01Z`) | NAF rév. 2 | `naf_rev2_secteurs.csv` |
| `NN.NL` (ex. `52.1D`) | NAF rév. 1 | `naf_rev1_vers_rev2.csv`, sinon groupe `NN.N`, sinon division `NN` |
| `NN.NN` sans lettre (ex. `67.01`) | NAP 1973 | `nap600_secteurs.csv` |
| vide, ou commençant par `00` | aucune activité connue | `non_classe` |

Le code d'origine n'est jamais réécrit : la fiche garde `naf` tel que l'INSEE l'a donné,
et porte en plus `naf_nomenclature` et, quand la table officielle le permet, `naf_rev2`.

## Métiers (chantier 2, 2026-09-29)

Le **métier** est la maille fine sous le secteur : « experts-comptables », « plombiers,
chauffagistes et climatisation », « coiffeurs »… Il n'est pas une colonne de `companies` :
c'est l'étiquette automatique `metier-<clé>` (catégorie `sector`), posée et retirée par la
même synchro que `sector-`, `size-`, `region-` (`EtiquettesClassement`), lue par
`App\Crm\Referentiels\Metiers`.

- **Définition** : la table `METIERS` de `construire_metiers.py` — chaque métier est la liste
  de ses sous-classes NAF rév. 2. `python construire_metiers.py` réécrit `metiers.csv` et
  `naf_rev2_metiers.csv` ; il refuse un code absent de `naf_rev2_secteurs.csv` (donc de la
  liste INSEE) et une sous-classe rangée dans deux métiers.
- **Intitulés** : recopiés de `naf_rev2_secteurs.csv` (source INSEE ci-dessus).
- **Calcul** : depuis `companies.naf_rev2` — le code rév. 2 d'origine, ou celui que la table
  de passage INSEE retient pour un code de 1993. Une fiche dont le code n'a pas pu être
  converti n'a pas de métier.
- **Aucune invention** : une sous-classe absente de la table n'a PAS de métier, et il n'y a
  aucun repli par groupe ou par division. Les sous-classes « fourre-tout » (74.90B, 82.99Z,
  96.09Z, 94.99Z, 88.99B…) n'en ont volontairement aucun.
- **Limites de la NAF, écrites dans les libellés** : la NAF ne distingue pas les avocats des
  notaires (69.10Z), ni les carrossiers des garagistes (45.20A), ni les agences web des ESN
  (62.01Z) : ces métiers sont donc regroupés, et le libellé le dit.
- **Couverture** : `php artisan crm:etiquettes:inventaire --naf-sans-metier` liste les
  sous-classes les plus portées par des fiches SANS métier (nombres seulement) ; le bilan de
  `crm:referentiels:reclasser --dry-run` donne la répartition avant/après par métier.

La garde `tests/Unit/Crm/MetiersTest.php` vérifie la cohérence des deux fichiers entre eux et
avec la NAF, et la correspondance sur des exemples réels.
