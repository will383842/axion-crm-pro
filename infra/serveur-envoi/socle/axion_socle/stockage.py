"""État durable en SQLite. Chaque écriture est validée avec fsync (synchronous=FULL) AVANT toute réponse."""
import os
import re
import sqlite3
import stat

PRAGMAS_AUTORISES = frozenset({"synchronous", "journal_mode", "foreign_keys", "user_version"})


def assainir(texte, borne: int = 64) -> str:
    """Texte à journaliser : borné, sans retour ligne ni caractère de contrôle ou exotique."""
    return re.sub(r"[^A-Za-z0-9._@-]", "_", str(texte))[:borne]


def cle_temoins(espace: str) -> str:
    return f"temoins:{espace}"


def cle_liste_temoins(espace: str) -> str:
    return f"liste_temoins:{espace}"


class ErreurStockage(Exception):
    """Fichier absent, illisible, disque plein : le socle se ferme (451)."""


SCHEMA = """
CREATE TABLE meta(cle TEXT PRIMARY KEY, valeur TEXT);
CREATE TABLE compteur(jour TEXT NOT NULL, espace TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(jour, espace));
CREATE TABLE jetons(jeton_h TEXT PRIMARY KEY, empreinte TEXT NOT NULL, id TEXT NOT NULL UNIQUE, espace TEXT NOT NULL,
  jour TEXT NOT NULL, etat TEXT NOT NULL CHECK(etat IN ('reserve','confirme')),
  cree_duree REAL NOT NULL, reserve_duree REAL NOT NULL, reacceptations INTEGER NOT NULL DEFAULT 0);
CREATE TABLE nonces(h TEXT PRIMARY KEY, expire_duree REAL NOT NULL);
CREATE TABLE journal(n INTEGER PRIMARY KEY AUTOINCREMENT, ts REAL, type TEXT, detail TEXT);
"""


class Stockage:
    simuler_disque_plein = False

    def __init__(self, chemin: str):
        if not os.path.isfile(chemin):
            raise ErreurStockage("fichier d'état absent")
        if os.name != "nt" and stat.S_IMODE(os.stat(chemin).st_mode) & 0o077:
            raise ErreurStockage("droits du fichier d'état trop larges (0600 exigé)")
        try:
            self.c = sqlite3.connect(f"file:{chemin}?mode=rw", uri=True, isolation_level=None,
                                     check_same_thread=False)
            self.c.execute("PRAGMA synchronous=FULL")
            if self.c.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
                raise ErreurStockage("fichier d'état corrompu")
            self.c.execute("SELECT 1 FROM meta LIMIT 1").fetchall()
            if "reacceptations" not in [r[1] for r in self.c.execute("PRAGMA table_info(jetons)")]:
                self.c.execute("ALTER TABLE jetons ADD COLUMN reacceptations INTEGER NOT NULL DEFAULT 0")  # ajout seul
        except (sqlite3.Error, ErreurStockage) as e:
            self.close()
            if isinstance(e, ErreurStockage):
                raise
            raise ErreurStockage(f"fichier d'état illisible: {e}") from e

    @classmethod
    def creer(cls, chemin: str) -> None:
        """Installation uniquement. Départ FERMÉ : pause (seule la commande reprise ouvre), aucun espace n'est configuré (donc tous en mode témoins, liste vide : l'absence ferme)."""
        if os.path.lexists(chemin):
            raise ErreurStockage("existe déjà")
        dossier = os.path.dirname(os.path.abspath(chemin))
        if not os.path.isdir(dossier):
            os.makedirs(dossier, mode=0o700)
            os.chmod(dossier, 0o700)  # indépendant de l'umask
        try:
            fd = os.open(chemin, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        except FileExistsError as e:
            raise ErreurStockage("existe déjà") from e
        os.close(fd)
        os.chmod(chemin, 0o600)  # SQLite crée le journal avec les droits de ce fichier
        c = sqlite3.connect(chemin, isolation_level=None)
        try:
            c.execute("PRAGMA synchronous=FULL")
            c.executescript(SCHEMA)
            c.execute("INSERT INTO meta VALUES('pause_hm','1'),('duree','0')")
        finally:
            c.close()

    def close(self) -> None:
        """Idempotent. Libère le fichier (nécessaire pour le nettoyage sous Windows)."""
        c, self.c = getattr(self, "c", None), None
        if c is not None:
            c.close()

    def pragma(self, nom: str) -> int:
        if nom not in PRAGMAS_AUTORISES:
            raise ValueError("pragma non autorisé")
        return self.c.execute(f"PRAGMA {nom}").fetchone()[0]

    def meta(self, cle, defaut=None):
        r = self.c.execute("SELECT valeur FROM meta WHERE cle=?", (cle,)).fetchone()
        return r[0] if r else defaut

    def ecrire(self, f):
        """Exécute f(c) dans une transaction exclusive validée (fsync) ; toute erreur => ErreurStockage."""
        if self.simuler_disque_plein:
            raise ErreurStockage("disque plein")
        try:
            self.c.execute("BEGIN IMMEDIATE")
            try:
                r = f(self.c)
                self.c.execute("COMMIT")
                return r
            except BaseException:
                self.c.execute("ROLLBACK")
                raise
        except sqlite3.Error as e:
            raise ErreurStockage(str(e)) from e

    def poser(self, c, cle, valeur):
        c.execute("INSERT OR REPLACE INTO meta VALUES(?,?)", (cle, str(valeur)))

    def effacer(self, c, cle) -> int:
        """Rend le nombre de lignes effacées (1 = la clé existait et CETTE transaction l'a consommée)."""
        return c.execute("DELETE FROM meta WHERE cle=?", (cle,)).rowcount

    def effacer_cles_des_jetons_purges(self, c, prefixe, seuil):
        """Efface les clés `prefixe+id` des jetons confirmés que la purge va supprimer (seuil = durée)."""
        c.execute("DELETE FROM meta WHERE cle IN (SELECT ? || id FROM jetons WHERE cree_duree<? AND etat<>'reserve')",
                  (prefixe, seuil))

    def jeton(self, jeton_h):
        return self.c.execute("SELECT empreinte,id,etat,reserve_duree,espace,reacceptations FROM jetons WHERE jeton_h=?",
                              (jeton_h,)).fetchone()

    def compteur(self, jour, espace="*") -> int:
        r = self.c.execute("SELECT n FROM compteur WHERE jour=? AND espace=?", (jour, espace)).fetchone()
        return r[0] if r else 0

    def journal(self):
        return self.c.execute("SELECT type,detail,ts FROM journal ORDER BY n").fetchall()
