"""Tests du socle K6 (écrits AVANT le code). Aucun envoi réel : la sortie est une fausse file."""
import hashlib
import os
import sqlite3
import stat
import tempfile
import threading
import unittest
from datetime import datetime, timezone

from axion_socle import local
from axion_socle.socle import Parametres, Socle, signer
from axion_socle.sortie import FausseSortie
from axion_socle.stockage import Stockage

CLE = b"cle-de-test-sans-valeur"
OK = "temoin@example.test"


def utc(*a):
    return datetime(*a, tzinfo=timezone.utc).timestamp()


def jt(x) -> str:
    """Jeton de test réaliste : 128 bits hex (jamais un motif répété)."""
    return hashlib.sha256(f"jeton-de-test-{x}".encode()).hexdigest()[:32]


class Horloge:
    """Horloge murale (`t`) + horloge monotone (`m`) : `avance` fait avancer les deux, `saut` seulement la murale."""

    def __init__(self, t):
        self.t, self.m = t, 1000.0

    def __call__(self):
        return self.t

    def monotone(self):
        return self.m

    def avance(self, dt):
        self.t += dt
        self.m += dt

    def saut(self, dt):
        self.t += dt


class ArretBrutal(BaseException):
    """Simule un kill -9 : n'est attrapé par aucun `except Exception`."""


class Base(unittest.TestCase):
    T0 = utc(2026, 6, 15, 10, 0, 0)

    def setUp(self):
        self.rep = tempfile.TemporaryDirectory()
        self.addCleanup(self.rep.cleanup)
        self._ouverts = []
        self.addCleanup(self._fermer_tout)  # exécuté AVANT rep.cleanup (nettoyage Windows)
        self.chemin = os.path.join(self.rep.name, "etat.sqlite")
        self.h = Horloge(self.T0)
        self.sortie = FausseSortie()
        Stockage.creer(self.chemin)
        self.socle = self.ouvrir()
        self.sante()
        self.eteindre()

    def _fermer_tout(self):
        for o in self._ouverts:
            o.close()

    def eteindre(self, chemin=None, operateur="op"):
        return local.eteindre_temoins_local(chemin or self.chemin, operateur)

    def stockage_ouvert(self, chemin=None):
        st = Stockage(chemin or self.chemin)
        self._ouverts.append(st)
        return st

    def avance(self, dt):
        self.h.avance(dt)

    def saut(self, dt):
        self.h.saut(dt)

    def regle(self, t):
        self.h.avance(t - self.h.t)

    def ouvrir(self, plafond=3, liste=(OK,), chemin=None, sortie=None):
        s = Socle.ouvrir(chemin or self.chemin, self.h, sortie or self.sortie,
                         Parametres(plafond=plafond, cle=CLE, liste_blanche=frozenset(liste)),
                         monotone=self.h.monotone)
        self._ouverts.append(s)
        return s

    def sante(self):
        return self.socle.sante(self.h.t, signer(CLE, "sante", self.h.t))

    def envoi(self, jeton=jt("j"), dest=OK, contenu=b"msg"):
        return self.socle.recevoir(jeton, dest, contenu)


class TestPlafond(Base):  # S17
    def test_plafond_atteint_donne_451_report_jamais_perte(self):
        for i in range(3):
            self.assertEqual(self.envoi(jt(i)).code, 250)
        r = self.envoi(jt("9"))
        self.assertEqual(r.code, 451)
        self.assertIn("report", r.texte)
        self.assertEqual(len(self.sortie.depots), 3)
        # le lendemain, le même jeton passe : rien n'a été perdu
        self.regle(utc(2026, 6, 15, 22, 0, 0))
        self.sante()
        self.assertEqual(self.envoi(jt("9")).code, 250)

    def test_50_parallele_un_seul_restant(self):
        s = self.ouvrir(plafond=1)
        codes = []
        def go(i):
            codes.append(s.recevoir(jt(i), OK, b"x").code)
        th = [threading.Thread(target=go, args=(i,)) for i in range(50)]
        [t.start() for t in th]
        [t.join() for t in th]
        self.assertEqual(codes.count(250), 1)
        self.assertEqual(codes.count(451), 49)

    def test_bornes_minuit_paris_ete(self):
        self._bornes(utc(2026, 6, 15, 21, 59, 59), utc(2026, 6, 15, 22, 0, 0))

    def test_bornes_minuit_paris_hiver(self):
        self._bornes(utc(2026, 1, 15, 22, 59, 59), utc(2026, 1, 15, 23, 0, 0))

    def test_changement_heure_mars_journee_de_23h(self):
        # 29 mars 2026 : de 28/03 23:00Z à 29/03 21:59:59Z
        self._bornes(utc(2026, 3, 29, 21, 59, 59), utc(2026, 3, 29, 22, 0, 0), avant=utc(2026, 3, 28, 23, 0, 0))

    def test_changement_heure_octobre_journee_de_25h(self):
        # 25 octobre 2026 : de 24/10 22:00Z à 25/10 22:59:59Z
        self._bornes(utc(2026, 10, 25, 22, 59, 59), utc(2026, 10, 25, 23, 0, 0), avant=utc(2026, 10, 24, 22, 0, 0))

    def _bornes(self, derniere, premiere, avant=None):
        depart = avant or derniere
        chemin = os.path.join(self.rep.name, "bornes.sqlite")
        Stockage.creer(chemin)
        h = Horloge(depart)
        s = Socle.ouvrir(chemin, h, FausseSortie(), Parametres(plafond=1, cle=CLE, liste_blanche=frozenset([OK])),
                         monotone=h.monotone)
        self._ouverts.append(s)
        def vie(t):
            h.avance(t - h.t)
            self.assertTrue(s.sante(t, signer(CLE, "sante", t)))
        vie(depart)
        self.eteindre(chemin)
        self.assertEqual(s.recevoir(jt("a"), OK, b"1").code, 250)
        if avant:
            vie(derniere)
        self.assertEqual(s.recevoir(jt("b"), OK, b"2").code, 451)  # même journée Paris
        vie(premiere)
        self.assertEqual(s.recevoir(jt("b"), OK, b"2").code, 250)  # journée suivante

    def test_horloge_reculee_ne_remet_pas_a_zero(self):
        for i in range(3):
            self.envoi(jt(i))
        self.saut(-86400 * 2)
        self.assertEqual(self.envoi(jt("8")).code, 451)

    def test_redemarrage_le_compteur_survit(self):
        for i in range(3):
            self.envoi(jt(i))
        s2 = self.ouvrir()
        self.assertEqual(s2.recevoir(jt("8"), OK, b"x").code, 451)


class TestHommeMort(Base):  # S18
    def test_15_min_sans_sante_pause_totale(self):
        self.avance(899)
        self.assertEqual(self.envoi(jt("a")).code, 250)
        self.avance(902)  # > 900 s après la dernière santé valide
        self.assertEqual(self.envoi(jt("b")).code, 451)
        self.assertEqual(len(self.sortie.depots), 1)

    def test_sante_forgee_ne_rearme_rien(self):
        self.avance(1000)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertFalse(self.socle.sante(self.h.t, "00" * 32))
        self.assertEqual(self.envoi(jt("b")).code, 451)

    def test_sante_valide_seule_ne_reprend_pas_il_faut_reprise(self):
        self.avance(1000)
        self.envoi(jt("a"))
        self.assertTrue(self.sante())
        self.assertEqual(self.envoi(jt("b")).code, 451)
        self.assertFalse(self.socle.reprise("faux"))
        self.assertTrue(self.socle.reprise(signer(CLE, "reprise", self.h.t), self.h.t))
        self.assertEqual(self.envoi(jt("b")).code, 250)

    def test_pause_survit_au_redemarrage(self):
        self.avance(1000)
        self.envoi(jt("a"))
        self.assertEqual(self.ouvrir().recevoir(jt("b"), OK, b"x").code, 451)

    def test_sante_rejouee_refusee(self):
        t = self.h.t
        self.assertFalse(self.socle.sante(t, signer(CLE, "sante", t)))  # déjà utilisée


class TestTemoins(Base):  # S19
    def test_crm_allume_et_hors_liste_refuse(self):
        self.assertEqual(self.envoi(jt("a"), "autre@example.test").code, 250)
        self.assertTrue(self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t))
        r = self.envoi(jt("b"), "autre@example.test")
        self.assertEqual(r.code, 550)
        self.assertEqual(self.envoi(jt("c"), OK).code, 250)

    def test_casse_differente_refusee(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertEqual(self.envoi(jt("a"), OK.upper()).code, 550)

    def test_extinction_distante_refusee_locale_journalisee(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertFalse(self.socle.eteindre_temoins("crm", "x"))
        self.assertFalse(self.socle.eteindre_temoins("paquet", "x"))
        self.assertFalse(self.socle.eteindre_temoins("local", "x"))  # la chaîne « local » ne suffit plus
        self.assertTrue(self.socle.temoins_actifs())
        self.assertTrue(self.eteindre(operateur="alice"))
        self.assertFalse(self.socle.temoins_actifs())
        j = self.socle.journal()
        self.assertTrue(any(e[0] == "temoins-off" and "alice" in e[1] for e in j))
        self.assertTrue(any(e[0] == "alerte-temoins-off-refuse" for e in j))

    def test_actif_survit_au_redemarrage(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertTrue(self.ouvrir().temoins_actifs())

    def test_allumage_non_signe_refuse(self):
        self.assertFalse(self.socle.allumer_temoins("faux", self.h.t))


class TestJetonsEtStockage(Base):
    def test_fsync_synchronous_full(self):
        self.assertEqual(self.stockage_ouvert().pragma("synchronous"), 2)

    def test_jeton_ecrit_sur_disque_avant_250(self):
        vu = []
        def espion(id_):
            vu.append(self.stockage_ouvert().jeton_present(jt("a")))
        self.sortie.avant_depot = espion
        self.assertEqual(self.envoi(jt("a")).code, 250)
        self.assertEqual(vu, [True])

    def test_rejeu_meme_reponse_aucun_nouvel_envoi(self):
        r1 = self.envoi(jt("a"))
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.id), (250, r1.id))
        self.assertTrue(r2.rejoue)
        self.assertEqual(len(self.sortie.depots), 1)
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_meme_jeton_autre_contenu_refuse_et_alerte(self):
        self.envoi(jt("a"), contenu=b"un")
        r = self.envoi(jt("a"), contenu=b"deux")
        self.assertEqual(r.code, 550)
        self.assertEqual(len(self.sortie.depots), 1)
        self.assertTrue(any(e[0] == "alerte-jeton-contenu" for e in self.socle.journal()))

    def test_jeton_accepte_7_jours(self):
        r1 = self.envoi(jt("a"))
        self.avance(6 * 86400 + 3600)
        self.sante()
        self.assertEqual(self.envoi(jt("a")).id, r1.id)
        self.avance(2 * 86400)
        self.sante()
        self.assertEqual(len(self.sortie.depots), 1)

    def test_jeton_trop_court_refuse(self):
        self.assertEqual(self.envoi("court").code, 550)

    def test_fichier_supprime_ferme_et_temoins(self):
        os.remove(self.chemin)
        s = self.ouvrir()
        self.assertEqual(s.recevoir(jt("a"), OK, b"x").code, 451)
        self.assertTrue(s.temoins_actifs())

    def test_fichier_illisible_ferme(self):
        with open(self.chemin, "wb") as f:
            f.write(b"pas du sqlite" * 100)
        self.assertEqual(self.ouvrir().recevoir(jt("a"), OK, b"x").code, 451)

    def test_disque_plein_451(self):
        self.socle.stockage.simuler_disque_plein = True
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(len(self.sortie.depots), 0)

    def test_exception_interne_ferme_jamais_accepte(self):
        self.sortie.panne = True
        self.assertEqual(self.envoi(jt("a")).code, 451)


class TestS21ArretBrutal(Base):
    def _crash_puis_reprise(self, point):
        def boom(*_):
            raise ArretBrutal()
        setattr(self.sortie, point, boom)
        with self.assertRaises(ArretBrutal):
            self.envoi(jt("a"))  # arrêt avant l'accusé 250
        setattr(self.sortie, point, None)
        s2 = self.ouvrir()  # redémarrage
        s2.sante(self.h.t + 1, signer(CLE, "sante", self.h.t + 1))
        self.avance(1)
        s2.recuperer()
        r = s2.recevoir(jt("a"), OK, b"msg")  # le client rejoue
        self.assertEqual(r.code, 250)
        self.assertEqual(len(self.sortie.depots), 1, "zéro perte, zéro doublon")
        self.assertEqual(s2.compteur_du_jour(), 1)

    def test_arret_avant_depot(self):
        self._crash_puis_reprise("avant_depot")

    def test_arret_apres_depot_avant_accuse(self):
        self._crash_puis_reprise("apres_depot")


X = "reserve@example.test"


class TestTemoinsEtMessagesEnAttente(Base):  # bloquant 1 : le dépôt revérifie TOUT, à chaque fois
    def _reserve_en_panne(self, dest=X, jeton=None):
        self.sortie.panne = True
        r = self.envoi(jeton or jt("x"), dest)
        self.assertEqual(r.code, 451)  # témoins éteints : accepté en base, mais file en panne
        self.sortie.panne = False

    def test_recuperer_apres_redemarrage_temoins_allumes_ne_depose_pas_hors_liste(self):
        self._reserve_en_panne()
        self.assertTrue(self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t))
        s2 = self.ouvrir()  # redémarrage
        s2.sante(self.h.t + 1, signer(CLE, "sante", self.h.t + 1))
        self.avance(1)
        self.assertEqual(s2.recuperer(), 0)
        self.assertNotIn(X, [d for d, _ in self.sortie.depots.values()])
        self.assertEqual(len(self.sortie.depots), 0)

    def test_rejeu_du_jeton_en_attente_temoins_allumes_ne_depose_pas(self):
        self._reserve_en_panne()
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        r = self.envoi(jt("x"), X)
        self.assertEqual(r.code, 550)
        self.assertEqual(len(self.sortie.depots), 0)

    def test_recuperer_depose_ce_qui_est_sur_liste_blanche_meme_temoins_allumes(self):
        self._reserve_en_panne(OK, jt("ok"))
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertEqual(self.socle.recuperer(), 1)
        self.assertEqual(len(self.sortie.depots), 1)

    def test_recuperer_et_rejeu_respectent_l_homme_mort(self):
        self._reserve_en_panne(OK, jt("ok"))
        self.avance(1000)
        self.assertEqual(self.socle.recuperer(), 0)
        self.assertEqual(self.envoi(jt("ok"), OK).code, 451)
        self.assertEqual(len(self.sortie.depots), 0)

    def test_depot_direct_revalide_aussi(self):
        self._reserve_en_panne()
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        for jeton, id_, dest, contenu in self.socle.stockage.en_attente():
            self.assertFalse(self.socle._deposer(jeton, id_, dest, bytes(contenu)))
        self.assertEqual(len(self.sortie.depots), 0)


class TestHorlogeHommeMort(Base):  # important 3 : horloge monotone, un saut ferme, jamais n'ouvre
    def test_recul_apres_longue_absence_de_sante_reste_ferme(self):
        self.avance(1000)  # > 15 min réelles sans santé (monotone)
        self.saut(-950)  # l'horloge murale est ramenée à 50 s après la dernière santé
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(len(self.sortie.depots), 0)

    def test_recul_d_horloge_ferme_et_ne_rouvre_pas_en_revenant(self):
        self.avance(100)
        self.saut(-3600)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.saut(3600)
        self.sante()
        self.assertEqual(self.envoi(jt("b")).code, 451)  # seule la reprise signée rouvre
        self.assertTrue(any(e[0] == "homme-mort" for e in self.socle.journal()))

    def test_saut_en_avant_ferme(self):
        self.avance(100)
        self.saut(+5000)
        self.assertEqual(self.envoi(jt("a")).code, 451)

    def test_pas_de_faux_positif_en_marche_normale(self):
        for _ in range(5):
            self.avance(300)
            self.assertTrue(self.sante())
            self.assertEqual(self.envoi(jt(self.h.t)).code, 250 if _ < 3 else 451)  # plafond 3

    def test_redemarrage_avec_horloge_en_arriere_ferme(self):
        self.saut(-3600)
        self.assertEqual(self.ouvrir().recevoir(jt("a"), OK, b"x").code, 451)


class TestStockagePermissions(Base):  # important 6
    def test_fichier_0600_dossier_0700(self):
        if os.name == "nt":
            self.skipTest("modes POSIX")
        chemin = os.path.join(self.rep.name, "sous", "etat.sqlite")
        Stockage.creer(chemin)
        self.assertEqual(stat.S_IMODE(os.stat(chemin).st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(os.stat(os.path.dirname(chemin)).st_mode), 0o700)
        s = self.ouvrir(chemin=chemin)
        s.sante(self.h.t, signer(CLE, "sante", self.h.t))
        for nom in os.listdir(os.path.dirname(chemin)):
            self.assertEqual(stat.S_IMODE(os.stat(os.path.join(os.path.dirname(chemin), nom)).st_mode), 0o600, nom)

    def test_umask_large_n_affaiblit_pas(self):
        if os.name == "nt":
            self.skipTest("modes POSIX")
        ancien = os.umask(0)
        try:
            chemin = os.path.join(self.rep.name, "autre", "etat.sqlite")
            Stockage.creer(chemin)
        finally:
            os.umask(ancien)
        self.assertEqual(stat.S_IMODE(os.stat(chemin).st_mode), 0o600)

    def _ligne(self, jeton):
        c = sqlite3.connect(self.chemin)
        try:
            return c.execute("SELECT length(contenu),empreinte,depose FROM jetons WHERE jeton=?", (jeton,)).fetchone()
        finally:
            c.close()

    def test_contenu_vide_des_le_depot_empreinte_gardee(self):
        self.assertEqual(self.envoi(jt("a")).code, 250)
        n, emp, depose = self._ligne(jt("a"))
        self.assertEqual((n, depose), (0, 1))
        self.assertEqual(len(emp), 64)
        self.assertEqual(self.envoi(jt("a")).code, 250)  # le rejeu marche toujours (empreinte)
        self.assertEqual(self.envoi(jt("a"), contenu=b"autre").code, 550)

    def test_contenu_garde_tant_que_pas_depose(self):
        self.sortie.panne = True
        self.envoi(jt("a"))
        self.assertEqual(self._ligne(jt("a"))[0], 3)

    def test_close_idempotent_et_fichier_supprimable(self):
        st = Stockage(self.chemin)
        st.close()
        st.close()
        s = Socle.ouvrir(self.chemin, self.h, self.sortie, Parametres(plafond=1, cle=CLE), monotone=self.h.monotone)
        s.close()
        s.close()


class TestDurcissements(Base):
    def test_signature_ts_entier_canonique(self):
        import hmac as h
        attendu = h.new(CLE, b"sante|100", hashlib.sha256).hexdigest()
        self.assertEqual(signer(CLE, "sante", 100), attendu)
        self.assertEqual(signer(CLE, "sante", 100.0), attendu)
        self.assertEqual(signer(CLE, "sante", 100.9), attendu)

    def test_ts_invalide_refuse(self):
        for ts in (float("nan"), float("inf"), "12", None, True):
            self.assertFalse(self.socle.sante(ts, "00" * 32))
            self.assertFalse(self.socle.reprise("00" * 32, ts))

    def test_pragma_liste_blanche(self):
        st = self.stockage_ouvert()
        self.assertEqual(st.pragma("synchronous"), 2)
        for mauvais in ("writable_schema=1", "table_info(meta)", "synchronous; DROP TABLE meta", "nom_inconnu"):
            with self.assertRaises(ValueError):
                st.pragma(mauvais)

    def test_jetons_refuses(self):
        mauvais = ["a" * 32, "0" * 32, "ab" * 16, "abcd" * 8, "x" * 31, jt("a") + "A" * 97, jt("a")[:31],
                   jt("a")[:30] + "+/", jt("a")[:30] + "= ", jt("a")[:30] + "é1", jt("a")[:30] + "\n1", ""]
        for j in mauvais:
            self.assertEqual(self.envoi(j).code, 550, repr(j))
        self.assertEqual(len(self.sortie.depots), 0)
        self.assertEqual(self.socle.recevoir(None, OK, b"x").code, 550)

    def test_jetons_acceptes(self):
        import secrets
        s = self.ouvrir(plafond=10)
        for j in (jt("a"), hashlib.sha512(b"z").hexdigest(), secrets.token_urlsafe(32), secrets.token_hex(16)):
            self.assertEqual(s.recevoir(j, OK, b"m").code, 250, j)

    def test_allumer_et_eteindre_prennent_le_verrou(self):
        pris, libre = threading.Event(), threading.Event()
        def tenir():
            with self.socle._verrou:
                pris.set()
                libre.wait(5)
        t = threading.Thread(target=tenir)
        t.start()
        pris.wait(5)
        fini = []
        def allumer():
            self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
            self.socle.eteindre_temoins("crm", "x")
            fini.append(1)
        t2 = threading.Thread(target=allumer)
        t2.start()
        t2.join(0.4)
        self.assertEqual(fini, [])  # bloqué tant que le verrou est tenu
        libre.set()
        t.join(5)
        t2.join(5)
        self.assertEqual(fini, [1])

    def test_operateur_borne_et_assaini(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertTrue(self.eteindre(operateur="alice\nFAUX|ligne" + "z" * 500))
        detail = [e[1] for e in self.socle.journal() if e[0] == "temoins-off"][-1]
        self.assertNotIn("\n", detail)
        self.assertLessEqual(len(detail), 64)
        self.assertTrue(detail.startswith("alice"))

    def test_origine_refusee_bornee_et_assainie(self):
        self.socle.eteindre_temoins("crm\n" + "x" * 500, "x")
        detail = [e[1] for e in self.socle.journal() if e[0] == "alerte-temoins-off-refuse"][-1]
        self.assertNotIn("\n", detail)
        self.assertLessEqual(len(detail), 64)

    def test_extinction_locale_hors_du_socle(self):
        self.assertFalse(hasattr(Socle, "eteindre_temoins_local"))
        self.assertFalse(self.socle.eteindre_temoins("local", "op"))
        self.assertFalse(local.eteindre_temoins_local(os.path.join(self.rep.name, "absent.sqlite"), "op"))
        self.assertFalse(local.eteindre_temoins_local(self.chemin, ""))  # opérateur obligatoire


if __name__ == "__main__":
    unittest.main()
