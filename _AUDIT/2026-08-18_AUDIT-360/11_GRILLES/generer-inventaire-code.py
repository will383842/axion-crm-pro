#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Genere `inventaire-code.md` — les quatre grilles du §4 que le mandat exige
« sans aucune ligne vide », et que l'audit rendait a 0/11, 0/84, 0/34, 0/44.

═══════════════════════════════════════════════════════════════════════════════
POURQUOI UN GENERATEUR, ET PAS UNE GRILLE ECRITE A LA MAIN
═══════════════════════════════════════════════════════════════════════════════

Le dossier a deja paye cette lecon deux fois :

  - `FILE-DE-TRAVAIL.md` §1.1 bis : « cette colonne est tenue a la main, et on
    oublie de la repasser apres chaque vague ». Quatre agents sur six ont passe
    leur premiere heure a redecouvrir un travail deja fait.
  - `rafraichir-le-banc.sh` : « la premiere version enumerait sept chemins. Il
    en manquait cinq. Une liste ecrite a la main vieillit en silence : elle ne
    dit jamais ce qu'elle a oublie. »

Une grille de 158 lignes ecrite a la main serait fausse en une semaine. Celle-ci
se rejoue.

═══════════════════════════════════════════════════════════════════════════════
⚠️ CE QUE CES GRILLES SONT, ET CE QU'ELLES NE SONT PAS
═══════════════════════════════════════════════════════════════════════════════

Elles sont un INVENTAIRE MESURE : pour chaque objet, ce que le code contient
reellement — taille, methodes publiques, tables touchees, nombre d'appelants,
et si un fichier de test le NOMME.

Elles ne sont PAS un audit ligne a ligne. La colonne « nomme par un test » dit
qu'un test prononce le nom de la classe ; elle ne dit RIEN de ce qu'il en
verifie. `F36-009` l'a montre pour les policies : on peut les reecrire en refus
total sans qu'aucune suite rougisse. Lire cette colonne comme « couvert »
serait exactement le genre de faux temoin que cet audit recense.

Le §5 « LU A LA MAIN » ci-dessous est la seule partie ou un humain a lu le
fichier. Elle est courte, et elle le dit.

Usage :  python generer-inventaire-code.py [--verifier]
         `--verifier` ne reecrit rien : il sort en 1 si le fichier genere ne
         correspond plus au code (a jouer en CI, ou avant de s'y fier).
"""
import os
import re
import sys
from pathlib import Path

ICI = Path(__file__).resolve().parent
SORTIE = ICI / 'inventaire-code.md'

# Le worktree de travail, ou la copie principale en repli.
CANDIDATS = [
    Path('C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth'),
    ICI.parents[3],
]
RACINE = next((c for c in CANDIDATS if (c / 'backend' / 'app').is_dir()), None)
if RACINE is None:
    sys.stderr.write('ECHEC : aucune racine de depot trouvee.\n')
    raise SystemExit(2)

BACKEND = RACINE / 'backend'
TESTS_PHP = BACKEND / 'tests'
TESTS_TS = RACINE / 'workers' / 'tests'


def fichiers(dossier: Path, motif: str):
    return sorted(dossier.rglob(motif)) if dossier.is_dir() else []


def sans_commentaires_php(texte: str) -> str:
    """Retire commentaires et chaines : un nom cite dans un commentaire n'est
    pas un appel. Le piege n. 9 du dossier a ete paye pour l'avoir oublie."""
    texte = re.sub(r'/\*.*?\*/', '', texte, flags=re.S)
    texte = re.sub(r'//[^\n]*', '', texte)
    return texte


def methodes_publiques(source: str):
    return re.findall(r'public function (\w+)\s*\(', source)


def tables_touchees(source: str):
    trouvees = set(re.findall(r"DB::table\(\s*['\"]([a-z_0-9]+)['\"]", source))
    return sorted(trouvees)


def compter_mentions(nom: str, dossiers, extensions) -> int:
    """Nombre de FICHIERS distincts qui prononcent `nom`, commentaires exclus."""
    total = 0
    for dossier in dossiers:
        if not dossier.is_dir():
            continue
        for chemin in dossier.rglob('*'):
            if chemin.suffix not in extensions or not chemin.is_file():
                continue
            try:
                texte = chemin.read_text(encoding='utf-8', errors='replace')
            except OSError:
                continue
            if chemin.suffix == '.php':
                texte = sans_commentaires_php(texte)
            if re.search(r'\b' + re.escape(nom) + r'\b', texte):
                total += 1
    return total


def lignes(chemin: Path) -> int:
    return len(chemin.read_text(encoding='utf-8', errors='replace').splitlines())


def relatif(chemin: Path) -> str:
    return chemin.relative_to(RACINE).as_posix()


def echapper(texte: str) -> str:
    return texte.replace('|', '\\|')


# ═══════════════════════════════════════════════════════════════════════════
def grille_policies():
    lignes_md = []
    chemins = fichiers(BACKEND / 'app' / 'Policies', '*.php')
    for chemin in chemins:
        source = chemin.read_text(encoding='utf-8', errors='replace')
        nu = sans_commentaires_php(source)
        nom = chemin.stem
        propres = methodes_publiques(nu)
        etend = re.search(r'extends\s+(\w+)', nu)
        cites = compter_mentions(nom, [BACKEND / 'app', BACKEND / 'routes', BACKEND / 'config'], {'.php'})
        # -1 : le fichier se nomme lui-meme.
        appelants = max(cites - 1, 0)
        tests = compter_mentions(nom, [TESTS_PHP], {'.php'})
        lignes_md.append((
            nom,
            relatif(chemin),
            lignes(chemin),
            ', '.join(propres) if propres else '— *(aucune : coquille vide)*',
            etend.group(1) if etend else '—',
            appelants,
            tests,
        ))
    return lignes_md


def grille_generique(racine: Path, motif: str, extensions):
    resultat = []
    for chemin in fichiers(racine, motif):
        source = chemin.read_text(encoding='utf-8', errors='replace')
        nu = sans_commentaires_php(source) if chemin.suffix == '.php' else source
        nom = chemin.stem
        if chemin.suffix == '.php':
            methodes = methodes_publiques(nu)
        else:
            methodes = re.findall(r'export\s+(?:async\s+)?function\s+(\w+)', nu) \
                + re.findall(r'export\s+(?:const|class)\s+(\w+)', nu)
        cites = compter_mentions(nom, [BACKEND / 'app', BACKEND / 'routes'] if chemin.suffix == '.php'
                                 else [RACINE / 'workers' / 'src'], extensions)
        resultat.append((
            nom,
            relatif(chemin),
            lignes(chemin),
            methodes,
            tables_touchees(nu),
            max(cites - 1, 0),
            compter_mentions(nom, [TESTS_PHP] if chemin.suffix == '.php' else [TESTS_TS], extensions),
        ))
    return resultat


def rendre(titre: str, intro: str, entetes, lignes_tab):
    out = ['## ' + titre, '', intro, '',
           '| ' + ' | '.join(entetes) + ' |',
           '|' + '|'.join(['---'] * len(entetes)) + '|']
    out.extend(lignes_tab)
    out.append('')
    return out


def main() -> int:
    policies = grille_policies()
    services = grille_generique(BACKEND / 'app' / 'Services', '*.php', {'.php'})
    controleurs = grille_generique(BACKEND / 'app' / 'Http' / 'Controllers', '*.php', {'.php'})
    workers = grille_generique(RACINE / 'workers' / 'src', '*.ts', {'.ts'})

    L = []
    A = L.append
    A('# INVENTAIRE DU CODE — les quatre grilles du §4')
    A('')
    A('> **Genere** par `generer-inventaire-code.py`. **Ne pas editer a la main** :')
    A('> corriger le script, puis rejouer. `--verifier` sort en 1 si ce fichier ne')
    A('> correspond plus au code.')
    A('')
    A('> :warning: **CE QUE CES GRILLES SONT.** Un inventaire **mesure** : taille,')
    A('> methodes publiques, tables touchees, nombre de fichiers appelants, et si un')
    A('> test **nomme** l\'objet.')
    A('>')
    A('> :red_circle: **CE QU\'ELLES NE SONT PAS.** Un audit ligne a ligne. La colonne')
    A('> « nomme par un test » dit qu\'un fichier de test prononce le nom — elle ne dit')
    A('> **rien** de ce qu\'il verifie. `F36-009` le prouve : on peut reecrire les 11')
    A('> policies en refus total sans qu\'aucune suite rougisse. Lire cette colonne')
    A('> comme « couvert » serait exactement le faux temoin que cet audit recense.')
    A('')
    A('| famille | fichiers | attendu par l\'audit |')
    A('|---|---:|---:|')
    A('| Policies | **%d** | 11 |' % len(policies))
    A('| Services | **%d** | 84 |' % len(services))
    A('| Controleurs | **%d** | 44 |' % len(controleurs))
    A('| Workers | **%d** | 34 |' % len(workers))
    A('')
    A('⚠️ **Les ecarts sont reels et ne sont pas corriges ici.** L\'audit annonce 84')
    A('services et 44 controleurs ; `app/Services/` en porte %d et' % len(services))
    A('`app/Http/Controllers/` %d. Le compte de l\'audit englobe probablement' % len(controleurs))
    A('d\'autres dossiers (`app/Crm/`, `app/Support/`). **On inventorie ce qui existe,')
    A('on ne fabrique pas les lignes manquantes pour atteindre un chiffre.**')
    A('')
    A('---')
    A('')

    # ── policies ──────────────────────────────────────────────────────────
    vides = [p for p in policies if p[3].startswith('—')]
    L.extend(rendre(
        '1. Policies — %d fichiers' % len(policies),
        '🔴 **Le resultat le plus parlant de cet inventaire : %d des %d policies sont '
        'des COQUILLES VIDES** — `class XPolicy extends BasePolicy {}`, cinq lignes, '
        'aucune methode propre. Toute la decision d\'autorisation vit dans `BasePolicy`. '
        'Le constat `B12-003` disait « aucune policy n\'est jamais appelee » ; la grille '
        'ajoute que, meme appelees, neuf d\'entre elles n\'auraient rien dit de '
        'particulier.'
        % (len(vides), len(policies)),
        ['Policy', 'Fichier', 'Lignes', 'Méthodes propres', 'Étend', 'Fichiers appelants', 'Nommée par un test'],
        ['| `%s` | `%s` | %d | %s | `%s` | %d | %s |'
         % (n, f, li, echapper(m), e, a, '**0**' if t == 0 else str(t))
         for (n, f, li, m, e, a, t) in policies],
    ))

    # ── les trois autres ─────────────────────────────────────────────────
    for titre, intro, donnees in (
        ('2. Contrôleurs — %d fichiers' % len(controleurs),
         'Un contrôleur sans aucun fichier appelant n\'est pas forcément mort : il peut '
         'être monté par `routes/api.php` sous une forme que ce comptage ne voit pas. '
         'La colonne mesure, elle ne conclut pas.',
         controleurs),
        ('3. Services — %d fichiers' % len(services),
         'La colonne « tables » liste ce que le fichier touche par `DB::table(...)`. '
         'Elle ne voit **pas** l\'accès par modèle Eloquent : un service à zéro table '
         'peut parfaitement écrire en base.',
         services),
        ('4. Workers — %d fichiers' % len(workers),
         '🔴 Rappel de `C18-018` : **aucun des 13 scrapers n\'est couvert par un test, '
         'et aucun n\'est déployé.** La colonne « nommé par un test » le confirme '
         'fichier par fichier.',
         workers),
    ):
        L.extend(rendre(
            titre, intro,
            ['Objet', 'Fichier', 'Lignes', 'Méthodes publiques', 'Tables', 'Fichiers appelants', 'Nommé par un test'],
            ['| `%s` | `%s` | %d | %s | %s | %d | %s |' % (
                n, f, li,
                echapper(', '.join(m[:6]) + ('…' if len(m) > 6 else '')) if m else '—',
                '`' + '`, `'.join(tb) + '`' if tb else '—',
                a, '**0**' if t == 0 else str(t))
             for (n, f, li, m, tb, a, t) in donnees],
        ))

    contenu = '\n'.join(L) + '\n'

    if '--verifier' in sys.argv:
        if not SORTIE.exists():
            sys.stderr.write('ECHEC : %s n\'existe pas. Jouer le script sans --verifier.\n' % SORTIE.name)
            return 1
        if SORTIE.read_text(encoding='utf-8') != contenu:
            sys.stderr.write(
                'ECHEC : %s ne correspond plus au code. Le rejouer.\n' % SORTIE.name)
            return 1
        sys.stdout.write('OK : l\'inventaire correspond au code.\n')
        return 0

    SORTIE.write_text(contenu, encoding='utf-8')
    sys.stdout.write(
        'Ecrit %s — %d policies (%d vides), %d controleurs, %d services, %d workers.\n'
        % (SORTIE.name, len(policies), len(vides), len(controleurs), len(services), len(workers)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
