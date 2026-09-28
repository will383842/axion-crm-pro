# Référentiels de classement — provenance

Ces fichiers sont la **seule** table de passage « code d'activité → secteur » du CRM.
Ils sont lus par `App\Crm\Referentiels\NomenclatureNaf` (collecte INSEE, enrichissement,
reclassement de masse, écrans). Aucune autre liste de secteurs ne doit exister dans le code.

| Fichier | Contenu | Lignes |
|---|---|---|
| `secteurs.csv` | les 33 clés de secteur (31 secteurs + `interprofessionnel` + `non_classe`) et leur libellé | 33 |
| `naf_rev2_secteurs.csv` | les 732 sous-classes de la NAF rév. 2 (2008) → secteur | 732 |
| `naf_rev1_vers_rev2.csv` | les 712 codes de la NAF rév. 1 (1993/2003) → code rév. 2 principal + secteur | 712 |
| `naf_rev1_groupes.csv` | repli par groupe rév. 1 (`NN.N`) pour les codes absents de la table officielle | 224 |
| `naf_rev1_divisions.csv` | dernier repli par division rév. 1 (`NN`) | 62 |
| `nap600_secteurs.csv` | les 650 postes de la NAP 600 (1973, format `NN.NN` sans lettre) → secteur | 650 |

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

## Règle de lecture du champ `companies.naf`

| Forme | Nomenclature | Calcul du secteur |
|---|---|---|
| `NN.NNL` (ex. `62.01Z`) | NAF rév. 2 | `naf_rev2_secteurs.csv` |
| `NN.NL` (ex. `52.1D`) | NAF rév. 1 | `naf_rev1_vers_rev2.csv`, sinon groupe `NN.N`, sinon division `NN` |
| `NN.NN` sans lettre (ex. `67.01`) | NAP 1973 | `nap600_secteurs.csv` |
| vide, ou commençant par `00` | aucune activité connue | `non_classe` |

Le code d'origine n'est jamais réécrit : la fiche garde `naf` tel que l'INSEE l'a donné,
et porte en plus `naf_nomenclature` et, quand la table officielle le permet, `naf_rev2`.
