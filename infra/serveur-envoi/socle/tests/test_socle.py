"""Tests du socle K6 (écrits AVANT le code). Aucun envoi réel : la sortie est une fausse file."""
import os
import tempfile
import threading
import unittest
from datetime import datetime, timezone

from axion_socle.socle import Parametres, Socle, signer
from axion_socle.sortie import FausseSortie
from axion_socle.stockage import Stockage

CLE = b"cle-de-test-sans-valeur"
OK = "temoin@example.test"


def utc(*a):
    return datetime(*a, tzinfo=timezone.utc).timestamp()


class Horloge:
    def __init__(self, t):
        self.t = t

    def __call__(self):
        return self.t


class ArretBrutal(BaseException):
    """Simule un kill -9 : n'est attrapé par aucun `except Exception`."""


class Base(unittest.TestCase):
    T0 = utc(2026, 6, 15, 10, 0, 0)

    def setUp(self):
        self.rep = tempfile.TemporaryDirectory()
        self.addCleanup(self.rep.cleanup)
        self.chemin = os.path.join(self.rep.name, "etat.sqlite")
        self.h = Horloge(self.T0)
        self.sortie = FausseSortie()
        Stockage.creer(self.chemin)
        self.socle = self.ouvrir()
        self.sante()
        self.socle.eteindre_temoins("local", "op")

    def ouvrir(self, plafond=3, liste=(OK,)):
        return Socle.ouvrir(self.chemin, self.h, self.sortie,
                            Parametres(plafond=plafond, cle=CLE, liste_blanche=frozenset(liste)))

    def sante(self):
        return self.socle.sante(self.h.t, signer(CLE, "sante", self.h.t))

    def envoi(self, jeton="j" * 32, dest=OK, contenu=b"msg"):
        return self.socle.recevoir(jeton, dest, contenu)


class TestPlafond(Base):  # S17
    def test_plafond_atteint_donne_451_report_jamais_perte(self):
        for i in range(3):
            self.assertEqual(self.envoi(f"{i:032d}").code, 250)
        r = self.envoi("9" * 32)
        self.assertEqual(r.code, 451)
        self.assertIn("report", r.texte)
        self.assertEqual(len(self.sortie.depots), 3)
        # le lendemain, le même jeton passe : rien n'a été perdu
        self.h.t = utc(2026, 6, 15, 22, 0, 0)
        self.sante()
        self.assertEqual(self.envoi("9" * 32).code, 250)

    def test_50_parallele_un_seul_restant(self):
        s = self.ouvrir(plafond=1)
        codes = []
        def go(i):
            codes.append(s.recevoir(f"{i:032d}", OK, b"x").code)
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
        s = Socle.ouvrir(chemin, h, FausseSortie(), Parametres(plafond=1, cle=CLE, liste_blanche=frozenset([OK])))
        def vie(t):
            h.t = t
            self.assertTrue(s.sante(t, signer(CLE, "sante", t)))
        vie(depart)
        s.eteindre_temoins("local", "op")
        self.assertEqual(s.recevoir("a" * 32, OK, b"1").code, 250)
        if avant:
            vie(derniere)
        self.assertEqual(s.recevoir("b" * 32, OK, b"2").code, 451)  # même journée Paris
        vie(premiere)
        self.assertEqual(s.recevoir("b" * 32, OK, b"2").code, 250)  # journée suivante

    def test_horloge_reculee_ne_remet_pas_a_zero(self):
        for i in range(3):
            self.envoi(f"{i:032d}")
        self.h.t -= 86400 * 2
        self.assertEqual(self.envoi("8" * 32).code, 451)

    def test_redemarrage_le_compteur_survit(self):
        for i in range(3):
            self.envoi(f"{i:032d}")
        s2 = self.ouvrir()
        self.assertEqual(s2.recevoir("8" * 32, OK, b"x").code, 451)


class TestHommeMort(Base):  # S18
    def test_15_min_sans_sante_pause_totale(self):
        self.h.t += 899
        self.assertEqual(self.envoi("a" * 32).code, 250)
        self.h.t += 902  # > 900 s après la dernière santé valide
        self.assertEqual(self.envoi("b" * 32).code, 451)
        self.assertEqual(len(self.sortie.depots), 1)

    def test_sante_forgee_ne_rearme_rien(self):
        self.h.t += 1000
        self.assertEqual(self.envoi("a" * 32).code, 451)
        self.assertFalse(self.socle.sante(self.h.t, "00" * 32))
        self.assertEqual(self.envoi("b" * 32).code, 451)

    def test_sante_valide_seule_ne_reprend_pas_il_faut_reprise(self):
        self.h.t += 1000
        self.envoi("a" * 32)
        self.assertTrue(self.sante())
        self.assertEqual(self.envoi("b" * 32).code, 451)
        self.assertFalse(self.socle.reprise("faux"))
        self.assertTrue(self.socle.reprise(signer(CLE, "reprise", self.h.t), self.h.t))
        self.assertEqual(self.envoi("b" * 32).code, 250)

    def test_pause_survit_au_redemarrage(self):
        self.h.t += 1000
        self.envoi("a" * 32)
        self.assertEqual(self.ouvrir().recevoir("b" * 32, OK, b"x").code, 451)

    def test_sante_rejouee_refusee(self):
        t = self.h.t
        self.assertFalse(self.socle.sante(t, signer(CLE, "sante", t)))  # déjà utilisée


class TestTemoins(Base):  # S19
    def test_crm_allume_et_hors_liste_refuse(self):
        self.assertEqual(self.envoi("a" * 32, "autre@example.test").code, 250)
        self.assertTrue(self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t))
        r = self.envoi("b" * 32, "autre@example.test")
        self.assertEqual(r.code, 550)
        self.assertEqual(self.envoi("c" * 32, OK).code, 250)

    def test_casse_differente_refusee(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertEqual(self.envoi("a" * 32, OK.upper()).code, 550)

    def test_extinction_distante_refusee_locale_journalisee(self):
        self.socle.allumer_temoins(signer(CLE, "temoins-on", self.h.t), self.h.t)
        self.assertFalse(self.socle.eteindre_temoins("crm", "x"))
        self.assertFalse(self.socle.eteindre_temoins("paquet", "x"))
        self.assertTrue(self.socle.temoins_actifs())
        self.assertTrue(self.socle.eteindre_temoins("local", "alice"))
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
        self.assertEqual(Stockage(self.chemin).pragma("synchronous"), 2)

    def test_jeton_ecrit_sur_disque_avant_250(self):
        vu = []
        def espion(id_):
            vu.append(Stockage(self.chemin).jeton_present("a" * 32))
        self.sortie.avant_depot = espion
        self.assertEqual(self.envoi("a" * 32).code, 250)
        self.assertEqual(vu, [True])

    def test_rejeu_meme_reponse_aucun_nouvel_envoi(self):
        r1 = self.envoi("a" * 32)
        r2 = self.envoi("a" * 32)
        self.assertEqual((r2.code, r2.id), (250, r1.id))
        self.assertTrue(r2.rejoue)
        self.assertEqual(len(self.sortie.depots), 1)
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_meme_jeton_autre_contenu_refuse_et_alerte(self):
        self.envoi("a" * 32, contenu=b"un")
        r = self.envoi("a" * 32, contenu=b"deux")
        self.assertEqual(r.code, 550)
        self.assertEqual(len(self.sortie.depots), 1)
        self.assertTrue(any(e[0] == "alerte-jeton-contenu" for e in self.socle.journal()))

    def test_jeton_accepte_7_jours(self):
        r1 = self.envoi("a" * 32)
        self.h.t += 6 * 86400 + 3600
        self.sante()
        self.assertEqual(self.envoi("a" * 32).id, r1.id)
        self.h.t += 2 * 86400
        self.sante()
        self.assertEqual(len(self.sortie.depots), 1)

    def test_jeton_trop_court_refuse(self):
        self.assertEqual(self.envoi("court").code, 550)

    def test_fichier_supprime_ferme_et_temoins(self):
        os.remove(self.chemin)
        s = self.ouvrir()
        self.assertEqual(s.recevoir("a" * 32, OK, b"x").code, 451)
        self.assertTrue(s.temoins_actifs())

    def test_fichier_illisible_ferme(self):
        with open(self.chemin, "wb") as f:
            f.write(b"pas du sqlite" * 100)
        self.assertEqual(self.ouvrir().recevoir("a" * 32, OK, b"x").code, 451)

    def test_disque_plein_451(self):
        self.socle.stockage.simuler_disque_plein = True
        self.assertEqual(self.envoi("a" * 32).code, 451)
        self.assertEqual(len(self.sortie.depots), 0)

    def test_exception_interne_ferme_jamais_accepte(self):
        self.sortie.panne = True
        self.assertEqual(self.envoi("a" * 32).code, 451)


class TestS21ArretBrutal(Base):
    def _crash_puis_reprise(self, point):
        def boom(*_):
            raise ArretBrutal()
        setattr(self.sortie, point, boom)
        with self.assertRaises(ArretBrutal):
            self.envoi("a" * 32)  # arrêt avant l'accusé 250
        setattr(self.sortie, point, None)
        s2 = self.ouvrir()  # redémarrage
        s2.sante(self.h.t + 1, signer(CLE, "sante", self.h.t + 1))
        self.h.t += 1
        s2.recuperer()
        r = s2.recevoir("a" * 32, OK, b"msg")  # le client rejoue
        self.assertEqual(r.code, 250)
        self.assertEqual(len(self.sortie.depots), 1, "zéro perte, zéro doublon")
        self.assertEqual(s2.compteur_du_jour(), 1)

    def test_arret_avant_depot(self):
        self._crash_puis_reprise("avant_depot")

    def test_arret_apres_depot_avant_accuse(self):
        self._crash_puis_reprise("apres_depot")


if __name__ == "__main__":
    unittest.main()
