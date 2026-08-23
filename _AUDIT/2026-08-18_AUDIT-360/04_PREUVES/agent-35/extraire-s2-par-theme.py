#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Extrait les constats S2 OUVERTS de FILE-DE-TRAVAIL.md et les range par theme.

Pourquoi ce script existe : la session d'explication du 2026-08-23 a range les
168 S2 ouverts en 15 themes. Ce rangement doit etre REJOUABLE, pas recopie a la
main -- sinon il devient une affirmation de plus que personne n'a mesuree
(cf. FILE-DE-TRAVAIL.md 1.1 bis, "la colonne d'etat a menti").

Il verifie trois choses et sort en 1 si l'une echoue :
  1. le nombre de S2 ouverts vaut bien celui du tableau 1.2 ;
  2. chaque constat est range dans EXACTEMENT un theme ;
  3. aucun identifiant du rangement n'est inconnu de la file.

Usage :  python extraire-s2-par-theme.py [--ecrire]
         (sans --ecrire : verifie seulement, n'ecrit rien)
"""
import sys
import unicodedata
from pathlib import Path

ICI = Path(__file__).resolve().parent
FILE = ICI / "FILE-DE-TRAVAIL.md"
SORTIE = ICI / "S2-PAR-THEME.md"

# Bornes du tableau du paragraphe 2 "LA FILE RESTANTE". On ne code pas les
# numeros de ligne en dur : on repere les titres de section.
DEBUT = "## 2. LA FILE RESTANTE"
FIN = "## 3. LES CONSTATS"

THEMES = [
    ("1. Cloisonnement des espaces (RLS)",
     "Ce qui doit empecher la donnee d'un client de passer chez un autre.",
     "A06-004 A08-004 A08-002 B10-006 B10-007 B11-007 B11-010 B12-013 B12-018 "
     "B14-007 B17-014 A07-010 A09-008"),
    ("2. Securite du serveur de production",
     "Defauts d'exploitation mesures sur le VPS, pas defauts de code.",
     "F37-004 F37-007 F37-008 F37-009 F37-010 F37-011 F40-009 F40-010 F40-013 G42-006"),
    ("3. Acces, permissions, 2FA",
     "Qui a le droit de faire quoi, et ce que l'ecran propose quand meme.",
     "P5-35-001 P5-35-002 P5-35-003 P5-35-008 D22-006 F36-009 B12-005 B12-008 "
     "H45-007 B12-015"),
    ("4. RGPD et journal d'audit",
     "Obligations modelisees mais jamais tenues, et la chaine d'audit.",
     "A05-002 A07-005 E31-009 E31-010 E33-007 B16-010 B16-011 B16-012 C21-002"),
    ("5. Collecte de masse (scraping)",
     "Moins technique que juridique : ce que les collecteurs s'autorisent.",
     "C19-002 C19-004 C19-005 C19-006 C18-010 C18-012 C18-013 C18-018"),
    ("6. Le pont site <-> CRM",
     "L'instrument qui devait prouver que rien ne se perd est lui-meme faux.",
     "E31-005 E31-006 E31-007 E31-008 E31-011 E33-004 E33-005 E33-008 A06-012 "
     "B13-004 B14-006 B14-011 B14-012 I49-002 I49-008 E34-002 A07-011"),
    ("7. Qualite des donnees en base",
     "Mesure sur la vraie base, pas deduit.",
     "C18-004 C21-005 C21-007 A05-004 A05-006 A07-007 B10-009 B10-011"),
    ("8. Navigation et reperes",
     "La cible des plans et ce que la console fait ne se recouvrent pas.",
     "A-006 A05-005 D23-002 D23-003 D23-004 D23-005 D23-007 D23-008 D23-009 "
     "D23-011 D23-012 D24-006 I48-005 I48-007 I48-008 I49-003 I49-005 E32-004 "
     "E32-005 E32-006 E32-007 E32-009 E34-004 E34-008 D29-002"),
    ("9. Comportement des ecrans",
     "Ce qui se passe quand on s'en sert : listes, formulaires, retour arriere.",
     "D24-005 D25-005 D25-007 D25-009 D26-006 D26-007 D26-009 D26-013 D29-007 H46-004"),
    ("10. Design system, sombre, accessibilite, mobile",
     "Le systeme existe et n'est pas employe ; le sombre est contraste a l'envers.",
     "D27-003 D27-004 D27-005 D27-006 D27-008 D27-013 D28-006 D28-008 D28-009 "
     "D28-010 D28-011 D30-003 D30-004 D30-006 D30-007 D29-004 D29-006"),
    ("11. Performance",
     "Mesure au volume de production, pas en atelier vide.",
     "G41-009 G41-011 G41-013 G42-002 G42-008 G43-003 G43-008 H45-009"),
    ("12. Le filet de tests et la CI",
     "Le theme qui conditionne les autres : pourquoi les 148 precedents ont pu vivre.",
     "A08-005 A06-007 B10-015 B11-005 B12-011 B17-013 E34-005 E34-006 F38-006 "
     "F38-009 F38-013 G42-013 H44-002 H44-004 H44-006 H44-011 H45-010 H46-001 "
     "H46-002 H46-009"),
    ("13. Sauvegardes et reprise",
     "Exigence n. 13 du mandat, toujours a zero.",
     "F39-002 F39-003 F39-004 F39-012"),
    ("14. Planification des taches",
     "Des taches qu'on croit vivantes.",
     "B17-004 B17-006 D29-009"),
    ("15. Documents qui mentent",
     "Des etats declares que le depot contredit.",
     "A06-002 A06-008 A06-010 A07-008 A09-007 E32-010"),
]


def sans_accents(s):
    return "".join(c for c in unicodedata.normalize("NFD", s)
                   if unicodedata.category(c) != "Mn").upper()


def lire_lignes_du_tableau():
    lignes = FILE.read_text(encoding="utf-8").split("\n")
    i0 = next(i for i, l in enumerate(lignes) if l.startswith(DEBUT))
    i1 = next(i for i, l in enumerate(lignes) if l.startswith(FIN))
    rangs = []
    for l in lignes[i0:i1]:
        if not l.startswith("|"):
            continue
        c = [x.strip() for x in l.strip().strip("|").split("|")]
        if len(c) < 8 or c[0] == "#" or set(c[0]) <= set("-: "):
            continue
        rangs.append({
            "n": c[0], "id": c[1].strip("`"), "sev": c[2], "symptome": c[3],
            "fichiers": c[4], "depot": c[5], "nature": c[6].strip("`"), "etat": c[7],
        })
    return rangs


def est_ouvert(etat):
    # "FERME" ou "VERIFIE FERME" en tete de cellule = ferme. Le reste est ouvert.
    return "FERME" not in sans_accents(etat)[:40]


def main():
    rangs = lire_lignes_du_tableau()
    s2 = [r for r in rangs if r["sev"] == "S2"]
    ouverts = [r for r in s2 if est_ouvert(r["etat"])]
    par_id = dict((r["id"], r) for r in ouverts)

    erreurs = []

    if len(ouverts) != 168:
        erreurs.append(
            "Le nombre de S2 ouverts est %d, pas 168. Le tableau 1.2 de "
            "FILE-DE-TRAVAIL.md a bouge : RE-VERIFIER le rangement avant de "
            "s'en servir." % len(ouverts))

    vus = {}
    doublons, inconnus = [], []
    for titre, _, liste in THEMES:
        for ident in liste.split():
            if ident in vus:
                doublons.append("%s (themes %s et %s)" % (ident, vus[ident], titre))
            vus[ident] = titre
            if ident not in par_id:
                inconnus.append("%s (theme %s)" % (ident, titre))
    non_ranges = sorted(set(par_id) - set(vus))

    if doublons:
        erreurs.append("Ranges deux fois : " + ", ".join(doublons))
    if inconnus:
        erreurs.append(
            "Ranges mais absents de la file des S2 ouverts (referme ? renomme ?) : "
            + ", ".join(inconnus))
    if non_ranges:
        erreurs.append("Ouverts mais ranges dans aucun theme : " + ", ".join(non_ranges))

    for e in erreurs:
        sys.stderr.write("ECHEC : " + e + "\n")
    if erreurs:
        return 1

    sys.stdout.write("OK : %d S2 ouverts, %d themes, chacun range une fois.\n"
                     % (len(ouverts), len(THEMES)))

    if "--ecrire" not in sys.argv:
        return 0

    lignes = []
    A = lignes.append
    A("# LES 168 CONSTATS S2 OUVERTS, RANGES PAR THEME")
    A("")
    A("> Genere par `extraire-s2-par-theme.py --ecrire`, depuis `FILE-DE-TRAVAIL.md`.")
    A("> **Ne pas editer a la main** : editer le rangement dans le script, puis rejouer.")
    A("> Le script sort en 1 si un constat est range deux fois, dans aucun theme, ou")
    A("> si le compte de 168 a bouge.")
    A("")
    A("> :warning: **Le dedoublonnage n'a jamais ete fait sur les S2.** `02ter` s'arrete")
    A("> aux S1 et le dit. Il reste tres probablement des paires decrivant le meme defaut")
    A("> sous deux etiquettes. **168 est un plafond, pas un compte exact.**")
    A("")
    A("| # | Theme | Nb |")
    A("|---:|---|---:|")
    for titre, _, liste in THEMES:
        num, nom = titre.split(". ", 1)
        A("| %s | %s | %d |" % (num, nom, len(liste.split())))
    A("| | **Total** | **%d** |" % len(ouverts))
    A("")
    A("---")
    A("")
    for titre, sous_titre, liste in THEMES:
        idents = liste.split()
        A("## %s -- %d constats" % (titre, len(idents)))
        A("")
        A("*%s*" % sous_titre)
        A("")
        A("| Identifiant | Depot | Nature | Symptome | Fichiers |")
        A("|---|---|---|---|---|")
        for ident in idents:
            r = par_id[ident]
            A("| `%s` | %s | `%s` | %s | %s |" % (
                ident, r["depot"], r["nature"],
                r["symptome"].replace("|", "\\|"),
                r["fichiers"].replace("|", "\\|")))
        A("")
    SORTIE.write_text("\n".join(lignes) + "\n", encoding="utf-8")
    sys.stdout.write("Ecrit : %s\n" % SORTIE.name)
    return 0


if __name__ == "__main__":
    sys.exit(main())
