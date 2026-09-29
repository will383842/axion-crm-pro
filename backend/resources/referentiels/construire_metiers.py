# Construit `metiers.csv` (la liste des métiers et leur libellé, dans l'ordre de l'écran) et
# `naf_rev2_metiers.csv` (sous-classe NAF rév. 2 -> métier) — chantier 2, 2026-09-29.
#
# La DÉFINITION des métiers est la table METIERS ci-dessous : chaque métier est la liste
# de ses sous-classes NAF rév. 2. Les intitulés des sous-classes sont recopiés de
# `naf_rev2_secteurs.csv` (source INSEE, cf. LISEZMOI.md) : le script refuse un code qui n'y
# figure pas, et une sous-classe rangée dans deux métiers.
#
# Une sous-classe absente de la table n'a PAS de métier : on n'invente rien. En particulier
# les sous-classes « fourre-tout » (74.90B, 82.99Z, 96.09Z, 94.99Z…) n'en ont aucun.
#
# Usage (Python 3, bibliothèque standard seulement) :
#     python construire_metiers.py
# réécrit les deux CSV à côté du script. La garde `tests/Unit/Crm/MetiersTest.php` vérifie
# qu'ils sont cohérents entre eux et avec `naf_rev2_secteurs.csv`.
import csv
import os

ICI = os.path.dirname(os.path.abspath(__file__))

# (clé, libellé, sous-classes NAF rév. 2). L'ordre est celui de l'écran.
METIERS = [
    # ── Professions du droit, du chiffre et de la santé ──────────────────────
    ("professions-juridiques", "Avocats, notaires et professions juridiques", ["69.10Z"]),
    ("experts-comptables", "Experts-comptables et cabinets comptables", ["69.20Z"]),
    ("medecins", "Médecins généralistes et spécialistes", ["86.21Z", "86.22A", "86.22B", "86.22C"]),
    ("dentistes", "Dentistes", ["86.23Z"]),
    ("pharmacies", "Pharmacies", ["47.73Z"]),
    ("infirmiers-sages-femmes", "Infirmiers et sages-femmes", ["86.90D"]),
    ("kines-reeducation", "Kinésithérapeutes, orthophonistes, podologues (rééducation)", ["86.90E"]),
    ("autres-praticiens-sante", "Autres praticiens de santé (psychologues, ostéopathes…)", ["86.90F"]),
    ("laboratoires-analyses", "Laboratoires d'analyses médicales", ["86.90B"]),
    ("ambulances", "Ambulances", ["86.90A"]),
    ("hopitaux-cliniques", "Hôpitaux et cliniques", ["86.10Z"]),
    ("ehpad-hebergement-medicalise", "EHPAD et hébergement des personnes âgées", ["87.10A", "87.10B", "87.10C", "87.30A"]),
    ("aide-a-domicile", "Aide à domicile", ["88.10A"]),
    ("creches", "Crèches et accueil de jeunes enfants", ["88.91A"]),
    ("veterinaires", "Vétérinaires", ["75.00Z"]),
    ("opticiens", "Opticiens", ["47.78A"]),
    # ── Immobilier, architecture, ingénierie ─────────────────────────────────
    ("architectes", "Architectes", ["71.11Z"]),
    ("geometres", "Géomètres-experts", ["71.12A"]),
    ("bureaux-etudes", "Bureaux d'études, ingénierie et économistes de la construction", ["71.12B", "74.90A"]),
    ("controle-technique", "Contrôle technique, analyses et diagnostics", ["71.20A", "71.20B"]),
    ("agents-immobiliers", "Agents immobiliers et administrateurs de biens", ["68.31Z", "68.32A"]),
    ("promoteurs-marchands-biens", "Promoteurs immobiliers et marchands de biens", ["41.10A", "41.10B", "41.10C", "68.10Z"]),
    ("location-immobiliere", "Location et gestion de biens immobiliers (SCI…)", ["68.20A", "68.20B", "68.32B", "41.10D"]),
    ("holdings-sieges", "Holdings et sièges sociaux", ["64.20Z", "70.10Z"]),
    # ── Banque, assurance, patrimoine ────────────────────────────────────────
    ("banques-credit", "Banques et établissements de crédit", ["64.19Z", "64.91Z", "64.92Z"]),
    ("assurance", "Assurance (compagnies, agents et courtiers)", ["65.11Z", "65.12Z", "65.20Z", "66.22Z"]),
    ("gestion-patrimoine", "Gestion de patrimoine, courtage financier et gestion de fonds", ["66.12Z", "66.19B", "66.30Z"]),
    # ── Automobile et transport ──────────────────────────────────────────────
    ("garages-carrosseries", "Garages, mécanique et carrosserie", ["45.20A", "45.20B"]),
    ("commerce-automobile", "Vente de véhicules, motos et pièces automobiles", ["45.11Z", "45.19Z", "45.31Z", "45.32Z", "45.40Z"]),
    ("location-vehicules", "Location de véhicules", ["77.11A", "77.11B", "77.12Z"]),
    ("taxis-vtc", "Taxis et VTC", ["49.32Z", "49.39B"]),
    ("transport-routier", "Transport routier de marchandises", ["49.41A", "49.41B", "49.41C"]),
    ("demenageurs", "Déménageurs", ["49.42Z"]),
    ("logistique-messagerie", "Logistique, entreposage, messagerie et livraison", ["52.10A", "52.10B", "52.24A", "52.24B", "52.29A", "52.29B", "53.20Z"]),
    # ── Bâtiment et travaux publics ──────────────────────────────────────────
    ("maconnerie-gros-oeuvre", "Maçonnerie, gros œuvre et construction de bâtiments", ["41.20A", "41.20B", "43.99C"]),
    ("travaux-publics", "Travaux publics, terrassement et démolition", ["42.11Z", "42.12Z", "42.13A", "42.13B", "42.21Z", "42.22Z", "42.91Z", "42.99Z", "43.11Z", "43.12A", "43.12B", "43.13Z"]),
    ("electriciens", "Électriciens", ["43.21A", "43.21B"]),
    ("plombiers-chauffagistes", "Plombiers, chauffagistes et climatisation", ["43.22A", "43.22B"]),
    ("menuisiers", "Menuisiers, serruriers et agenceurs", ["16.23Z", "43.32A", "43.32B", "43.32C"]),
    ("peintres", "Peintres et vitriers", ["43.34Z"]),
    ("platriers-isolation", "Plâtriers, plaquistes et isolation", ["43.29A", "43.31Z"]),
    ("carreleurs-revetements", "Carreleurs et revêtements de sols et murs", ["43.33Z"]),
    ("couvreurs-charpentiers", "Couvreurs, charpentiers et étancheurs", ["43.91A", "43.91B", "43.99A"]),
    ("finitions-batiment", "Autres travaux spécialisés du bâtiment", ["43.29B", "43.39Z", "43.99B", "43.99D", "43.99E"]),
    ("paysagistes", "Paysagistes", ["81.30Z"]),
    # ── Services aux entreprises ─────────────────────────────────────────────
    ("nettoyage", "Nettoyage et propreté", ["81.10Z", "81.21Z", "81.22Z", "81.29A", "81.29B"]),
    ("securite-privee", "Sécurité privée", ["80.10Z", "80.20Z", "80.30Z"]),
    ("interim-recrutement", "Intérim, recrutement et placement", ["78.10Z", "78.20Z", "78.30Z"]),
    ("services-informatiques", "Services informatiques (ESN, développement, conseil)", ["62.01Z", "62.02A", "62.02B", "62.03Z", "62.09Z", "63.11Z"]),
    ("editeurs-logiciels", "Éditeurs de logiciels", ["58.21Z", "58.29A", "58.29B", "58.29C"]),
    ("telecoms", "Opérateurs de télécommunications", ["61.10Z", "61.20Z", "61.30Z", "61.90Z"]),
    ("agences-communication", "Agences de communication et relations publiques", ["70.21Z"]),
    ("agences-publicite", "Agences de publicité, régies et études de marché", ["73.11Z", "73.12Z", "73.20Z"]),
    ("conseil-gestion", "Conseil en gestion et management", ["70.22Z"]),
    ("design-graphisme", "Designers, graphistes et architectes d'intérieur", ["74.10Z"]),
    ("photographes", "Photographes", ["74.20Z"]),
    ("traducteurs", "Traducteurs et interprètes", ["74.30Z"]),
    ("evenementiel-salons", "Organisateurs de salons, foires et congrès", ["82.30Z"]),
    ("secretariat-domiciliation", "Secrétariat, domiciliation et centres d'appels", ["82.11Z", "82.19Z", "82.20Z"]),
    ("agents-commerciaux", "Agents commerciaux et intermédiaires du commerce", ["46.11Z", "46.12A", "46.12B", "46.13Z", "46.14Z", "46.15Z", "46.16Z", "46.17A", "46.17B", "46.18Z", "46.19A", "46.19B"]),
    # ── Formation ────────────────────────────────────────────────────────────
    ("organismes-formation", "Organismes de formation continue", ["85.59A"]),
    ("cours-soutien-scolaire", "Soutien scolaire, cours et autres enseignements", ["85.59B", "85.60Z"]),
    ("auto-ecoles", "Auto-écoles", ["85.53Z"]),
    # ── Commerce, restauration, services à la personne ───────────────────────
    ("coiffeurs", "Coiffeurs", ["96.02A"]),
    ("esthetique", "Instituts de beauté et soins du corps", ["96.02B", "96.04Z"]),
    ("boulangeries-patisseries", "Boulangeries et pâtisseries", ["10.71B", "10.71C", "10.71D", "47.24Z"]),
    ("boucheries-charcuteries", "Boucheries et charcuteries", ["10.13B", "47.22Z"]),
    ("commerces-alimentaires", "Épiceries, primeurs, cavistes et commerces alimentaires", ["47.11A", "47.11B", "47.11C", "47.21Z", "47.23Z", "47.25Z", "47.29Z"]),
    ("grande-distribution", "Supermarchés, hypermarchés et grands magasins", ["47.11D", "47.11E", "47.11F", "47.19A", "47.19B"]),
    ("tabac-presse", "Tabac et presse", ["47.26Z", "47.62Z"]),
    ("restaurants", "Restaurants (y compris restauration rapide)", ["56.10A", "56.10B", "56.10C"]),
    ("traiteurs-restauration-collective", "Traiteurs et restauration collective", ["56.21Z", "56.29A", "56.29B"]),
    ("cafes-bars", "Cafés et bars", ["56.30Z"]),
    ("hotels-hebergement", "Hôtels, campings et hébergement touristique", ["55.10Z", "55.20Z", "55.30Z", "55.90Z"]),
    ("agences-voyage", "Agences de voyage et voyagistes", ["79.11Z", "79.12Z", "79.90Z"]),
    ("fleuristes", "Fleuristes, jardineries et animaleries", ["47.76Z"]),
    ("habillement-chaussures", "Magasins d'habillement et de chaussures", ["47.51Z", "47.71Z", "47.72A", "47.72B"]),
    ("bijouteries", "Bijouteries et horlogeries", ["47.77Z", "95.25Z"]),
    ("equipement-maison", "Meubles, bricolage et équipement de la maison", ["47.52A", "47.52B", "47.53Z", "47.54Z", "47.59A", "47.59B"]),
    ("vente-distance", "Vente à distance et e-commerce", ["47.91A", "47.91B", "47.99A", "47.99B"]),
    ("pompes-funebres", "Pompes funèbres", ["96.03Z"]),
    ("salles-sport-clubs", "Salles de sport et clubs sportifs", ["93.11Z", "93.12Z", "93.13Z", "93.19Z"]),
    # ── Culture, médias, impression ──────────────────────────────────────────
    ("arts-spectacle", "Arts du spectacle et création artistique", ["90.01Z", "90.02Z", "90.03A", "90.03B", "90.04Z"]),
    ("production-audiovisuelle", "Production audiovisuelle et musicale", ["59.11A", "59.11B", "59.11C", "59.12Z", "59.20Z"]),
    ("edition-presse", "Édition et presse", ["58.11Z", "58.13Z", "58.14Z", "58.19Z", "63.91Z"]),
    ("imprimeries", "Imprimeries", ["18.11Z", "18.12Z", "18.13Z", "18.14Z"]),
    # ── Agriculture et industrie ─────────────────────────────────────────────
    ("agriculteurs-eleveurs", "Agriculteurs et éleveurs", ["01.11Z", "01.13Z", "01.19Z", "01.24Z", "01.25Z", "01.28Z", "01.30Z", "01.41Z", "01.42Z", "01.43Z", "01.45Z", "01.46Z", "01.47Z", "01.49Z", "01.50Z", "01.61Z", "01.62Z"]),
    ("viticulture", "Viticulteurs et vinification", ["01.21Z", "11.02A", "11.02B"]),
    ("mecanique-industrielle", "Mécanique industrielle, usinage et maintenance", ["25.50A", "25.50B", "25.61Z", "25.62A", "25.62B", "25.73A", "25.73B", "33.12Z", "33.13Z", "33.14Z", "33.17Z", "33.19Z", "33.20B", "33.20C", "33.20D"]),
    ("metallerie-chaudronnerie", "Métallerie et chaudronnerie", ["25.11Z", "25.12Z", "25.29Z", "33.11Z", "33.20A"]),
]


def main():
    with open(os.path.join(ICI, "naf_rev2_secteurs.csv"), encoding="utf-8", newline="") as f:
        lignes = list(csv.reader(f))[1:]
    intitules = {l[0]: l[2] for l in lignes}

    cles = set()
    vus = {}
    sortie = []
    for cle, libelle, codes in METIERS:
        if cle in cles:
            raise SystemExit(f"métier en double : {cle}")
        cles.add(cle)
        if not codes:
            raise SystemExit(f"métier sans sous-classe : {cle}")
        for code in codes:
            if code not in intitules:
                raise SystemExit(f"{cle} : {code} n'est pas une sous-classe NAF rév. 2")
            if code in vus:
                raise SystemExit(f"{code} rangé dans deux métiers : {vus[code]} et {cle}")
            vus[code] = cle
            sortie.append((code, cle, intitules[code]))

    sortie.sort()
    with open(os.path.join(ICI, "metiers.csv"), "w", encoding="utf-8", newline="\n") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["metier", "libelle"])
        w.writerows((cle, libelle) for cle, libelle, _ in METIERS)
    with open(os.path.join(ICI, "naf_rev2_metiers.csv"), "w", encoding="utf-8", newline="\n") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["naf_rev2", "metier", "libelle"])
        w.writerows(sortie)
    print(f"{len(METIERS)} métiers, {len(sortie)} sous-classes sur {len(intitules)}")
    for cle, libelle, codes in METIERS:
        print(f"  {cle:36} {len(codes):3}  {libelle}")


if __name__ == "__main__":
    main()
