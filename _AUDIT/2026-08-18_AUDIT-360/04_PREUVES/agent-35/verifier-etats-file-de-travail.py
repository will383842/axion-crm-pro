# -*- coding: utf-8 -*-
r"""
CONTROLE : la file de travail ment-elle sur ce qui est encore ouvert ?

POURQUOI CE FICHIER EXISTE.

Le 2026-08-21, six agents ont ete lances sur six lots S1 pris dans
`FILE-DE-TRAVAIL.md`. QUATRE d'entre eux ont rapporte la meme chose :

    « le correctif etait deja pose et committe (0ac9578), la file le donne
      encore "ouvert" »

Quatre lots sur six ont donc passe leur premiere heure a redecouvrir un travail
fait. Ce n'est pas la faute des agents : c'est le registre qui ment, parce que sa
colonne d'etat est tenue A LA MAIN et qu'on oublie de la repasser.

CE QUE FAIT CE SCRIPT. Il croise trois sources :
  1. la colonne d'etat de `FILE-DE-TRAVAIL.md` ;
  2. les MESSAGES de commit de la branche (`origin/main..HEAD`) ;
  3. les identifiants nommes dans les fichiers de test du depot.

Et il liste les constats marques « ouvert » qu'un commit ou une garde nomme
pourtant. Ce ne sont PAS forcement des erreurs — certains sont deliberement
laisses ouverts ET comptes par une garde (`F36-001`, dont le compteur est fige a
zero ; `A-011`, qui est un motif systemique et non un constat). C'est une LISTE A
VERIFIER, pas un verdict : le script ne referme rien tout seul, parce qu'un etat
recopie d'un message de commit vaudrait exactement ce que valait la colonne tenue
a la main.

USAGE (depuis ce dossier) :
    cd C:\Users\willi\Documents\Projets\crmpro-wt-a35-auth
    git log origin/main..HEAD --format="%h%x00%s%x00%b%x00---FIN---" > commits.txt
    python verifier-etats-file-de-travail.py commits.txt
"""
import io, os, re, sys, collections

RACINE_DEPOT = r'C:\Users\willi\Documents\Projets\crmpro-wt-a35-auth'
FILE_DE_TRAVAIL = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'FILE-DE-TRAVAIL.md')
IDRE = re.compile(r'\b([A-Z]\d{2}-\d{3}|A-\d{3}|P5-[A-Z0-9]+-\d{3})\b')


def identifiants_cites_par_les_commits(chemin):
    texte = io.open(chemin, encoding='utf-8', errors='replace').read()
    cite = collections.defaultdict(set)
    for bloc in texte.split('---FIN---'):
        if not bloc.strip():
            continue
        sha = bloc.strip('\x00\n ').split('\x00')[0].strip()
        for i in IDRE.findall(bloc):
            cite[i].add(sha)
    return cite


def identifiants_nommes_par_une_garde():
    garde = collections.defaultdict(set)
    for base in ('backend/tests', 'frontend/tests', 'workers/tests'):
        racine = os.path.join(RACINE_DEPOT, base.replace('/', os.sep))
        for dp, _, fs in os.walk(racine):
            for f in fs:
                chemin = os.path.join(dp, f)
                try:
                    t = io.open(chemin, encoding='utf-8', errors='replace').read()
                except OSError:
                    continue
                for i in set(IDRE.findall(t)):
                    garde[i].add(os.path.relpath(chemin, RACINE_DEPOT))
    return garde


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    cite = identifiants_cites_par_les_commits(sys.argv[1])
    garde = identifiants_nommes_par_une_garde()

    suspects = 0
    print('CONSTATS MARQUES « ouvert », MAIS NOMMES PAR UN COMMIT OU UNE GARDE')
    print('=' * 100)
    for numero, ligne in enumerate(io.open(FILE_DE_TRAVAIL, encoding='utf-8').read().split('\n'), 1):
        if not ligne.startswith('| '):
            continue
        cellules = ligne.split('|')
        if len(cellules) < 6:
            continue
        ident, severite = cellules[2].strip().strip('`'), cellules[3].strip()
        etat = cellules[-2].strip().strip('*')
        if severite not in ('S0', 'S1', 'S2', 'S3') or etat.lower() != 'ouvert':
            continue
        shas, fichiers = set(), set()
        for x in (p.strip() for p in ident.split('/')):
            shas |= cite.get(x, set())
            fichiers |= garde.get(x, set())
        if not shas and not fichiers:
            continue
        suspects += 1
        print('l.%-5d %-4s %s' % (numero, severite, ident))
        if shas:
            print('         commits : %s' % ' '.join(sorted(shas)))
        if fichiers:
            print('         gardes  : %s' % ' | '.join(sorted(fichiers)[:4]))
    print('=' * 100)
    print('TOTAL A VERIFIER : %d' % suspects)
    return 0


if __name__ == '__main__':
    sys.exit(main())
