"""État durable en SQLite. Chaque écriture est validée avec fsync (synchronous=FULL) AVANT toute réponse."""
import os
import sqlite3
import threading


class ErreurStockage(Exception):
    """Fichier absent, illisible, disque plein : le socle se ferme (451)."""


SCHEMA = """
CREATE TABLE meta(cle TEXT PRIMARY KEY, valeur TEXT);
CREATE TABLE compteur(jour TEXT PRIMARY KEY, n INTEGER NOT NULL);
CREATE TABLE jetons(jeton TEXT PRIMARY KEY, empreinte TEXT NOT NULL, id TEXT NOT NULL,
  destinataire TEXT NOT NULL, contenu BLOB NOT NULL, cree_a REAL NOT NULL, depose INTEGER NOT NULL DEFAULT 0);
CREATE TABLE journal(n INTEGER PRIMARY KEY AUTOINCREMENT, ts REAL, type TEXT, detail TEXT);
"""


class Stockage:
    simuler_disque_plein = False

    def __init__(self, chemin: str):
        if not os.path.isfile(chemin):
            raise ErreurStockage("fichier d'état absent")
        try:
            self.c = sqlite3.connect(f"file:{chemin}?mode=rw", uri=True, isolation_level=None,
                                     check_same_thread=False)
            self.c.execute("PRAGMA synchronous=FULL")
            if self.c.execute("PRAGMA integrity_check").fetchone()[0] != "ok":
                raise ErreurStockage("fichier d'état corrompu")
            self.c.execute("SELECT 1 FROM meta LIMIT 1").fetchall()
        except sqlite3.Error as e:
            raise ErreurStockage(f"fichier d'état illisible: {e}") from e

    @classmethod
    def creer(cls, chemin: str) -> None:
        """Installation uniquement. Départ FERMÉ : témoins allumés, aucune santé reçue."""
        if os.path.exists(chemin):
            raise ErreurStockage("existe déjà")
        c = sqlite3.connect(chemin, isolation_level=None)
        c.execute("PRAGMA synchronous=FULL")
        c.executescript(SCHEMA)
        c.execute("INSERT INTO meta VALUES('temoins','1'),('pause_hm','0')")
        c.close()

    def pragma(self, nom: str) -> int:
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

    def jeton(self, jeton):
        return self.c.execute("SELECT empreinte,id,destinataire,contenu,depose FROM jetons WHERE jeton=?",
                              (jeton,)).fetchone()

    def jeton_present(self, jeton) -> bool:
        return self.jeton(jeton) is not None

    def en_attente(self):
        return self.c.execute("SELECT jeton,id,destinataire,contenu FROM jetons WHERE depose=0").fetchall()

    def compteur(self, jour) -> int:
        r = self.c.execute("SELECT n FROM compteur WHERE jour=?", (jour,)).fetchone()
        return r[0] if r else 0

    def journal(self):
        return self.c.execute("SELECT type,detail,ts FROM journal ORDER BY n").fetchall()
