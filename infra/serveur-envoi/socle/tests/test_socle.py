"""Tests du socle K6a (écrits AVANT le code). Le socle DÉCIDE (250 / 451 / 550) ; KumoMTA dépose seul.
Aucun envoi réel, aucun dépôt de message : le contenu n'est jamais stocké.

Scénarios (spec 26 l.265-267) : S17 = homme mort, S18 = témoins, S19 = plafond."""
import hashlib
import hmac
import importlib
import json
import os
import sqlite3
import stat
import tempfile
import threading
import unittest
import uuid
from datetime import datetime, timezone
from unittest import mock

from axion_socle import local
from axion_socle.empreinte import empreinte_temoin, normaliser
from axion_socle.socle import Parametres, Socle
from axion_socle.stockage import Stockage

SECRET = b"cle-de-test-sans-valeur"
CLE_EMP = b"cle-empreinte-de-test"
WS = "11111111-1111-4111-8111-111111111111"
WS2 = "22222222-2222-4222-8222-222222222222"
OK = "temoin@example.test"
RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), *[os.pardir] * 4))
CONTRAT = os.path.join(RACINE, "envoi-serveur", "contrat")


def utc(*a):
    return datetime(*a, tzinfo=timezone.utc).timestamp()


def jt(x) -> str:
    """Jeton de test réaliste : 128 bits hex (jamais un motif répété)."""
    return hashlib.sha256(f"jeton-de-test-{x}".encode()).hexdigest()[:32]


def E(adresse) -> str:
    return empreinte_temoin(adresse, CLE_EMP)


def signature(secret, methode, chemin, ts, nonce, ws, corps) -> str:
    """Implémentation INDÉPENDANTE du contrat v2 (même charge que SignatureEnvoi::chargeRequete)."""
    charge = "\n".join([methode.upper(), chemin, ts, nonce, ws, corps]).encode()
    return hmac.new(secret, charge, hashlib.sha256).hexdigest()


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
        Stockage.creer(self.chemin)
        self.socle = self.ouvrir()
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.reprise(), 200)  # l'installation démarre en pause : seule la reprise ouvre
        self.assertTrue(self.eteindre())

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

    def vivre(self, duree, socle=None):
        """Le temps passe MAIS le CRM envoie sa santé toutes les 10 min : pas de pause."""
        while duree > 0:
            pas = min(600, duree)
            self.avance(pas)
            duree -= pas
            self.assertEqual(self.sante(socle=socle), 200)

    def ouvrir(self, plafond=3, plafond_espace=None, chemin=None, **kw):
        s = Socle.ouvrir(chemin or self.chemin, self.h,
                         Parametres(plafond=plafond, plafond_espace=plafond_espace or plafond, secret=SECRET,
                                    cle_empreinte=CLE_EMP, **kw), monotone=self.h.monotone)
        self._ouverts.append(s)
        return s

    # --- requêtes signées (contrat v2) -----------------------------------------------------------
    def req(self, chemin, corps="{}", ts=None, nonce=None, ws=WS, secret=SECRET, socle=None, methode="POST"):
        ts = str(int(self.h.t if ts is None else ts))
        nonce = nonce or str(uuid.uuid4())
        sig = signature(secret, methode, chemin, ts, nonce, ws, corps)
        return (socle or self.socle).traiter(methode, chemin, ts, nonce, ws, corps, f"k=1,sha256={sig}")

    def sante(self, socle=None):
        return self.req("/v1/commandes/sante", socle=socle)

    def reprise(self, socle=None):
        return self.req("/v1/commandes/reprise", socle=socle)

    def temoins(self, actif, liste, socle=None):
        return self.req("/v1/commandes/temoins", json.dumps({"actif": actif, "liste": liste}), socle=socle)

    def envoi(self, jeton=None, dest=OK, contenu=b"msg", ws=WS, socle=None):
        return (socle or self.socle).decider(ws, jt("j") if jeton is None else jeton, dest, contenu)

    def en_pause(self):
        return self.stockage_ouvert().meta("pause_hm") == "1"

    def ligne(self, jeton):
        c = sqlite3.connect(self.chemin)
        try:
            return c.execute("SELECT etat,id,empreinte FROM jetons WHERE jeton_h=?",
                             (hashlib.sha256(jeton.encode()).hexdigest(),)).fetchone()
        finally:
            c.close()


class TestPlafond(Base):  # S19
    def test_plafond_atteint_donne_451_report_jamais_perte(self):
        for i in range(3):
            self.assertEqual(self.envoi(jt(i)).code, 250)
        r = self.envoi(jt("9"))
        self.assertEqual(r.code, 451)
        self.assertIn("report", r.texte)
        self.assertEqual(self.socle.compteur_du_jour(), 3)
        self.vivre(12 * 3600)  # le lendemain, le même jeton passe : rien n'a été perdu
        self.regle(utc(2026, 6, 15, 22, 0, 0))
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.envoi(jt("9")).code, 250)

    def test_global_ET_par_espace(self):
        s = self.ouvrir(plafond=4, plafond_espace=2)
        self.assertEqual([self.envoi(jt(i), ws=WS, socle=s).code for i in range(3)], [250, 250, 451])
        self.assertEqual([self.envoi(jt(10 + i), ws=WS2, socle=s).code for i in range(3)], [250, 250, 451])
        # global atteint (4) : un troisième espace est refusé alors que son propre plafond est libre
        ws3 = "33333333-3333-4333-8333-333333333333"
        r = self.envoi(jt("20"), ws=ws3, socle=s)
        self.assertEqual((r.code, "global" in r.texte), (451, True))
        self.assertEqual(s.compteur_du_jour(), 4)
        self.assertEqual(s.compteur_du_jour(WS), 2)
        self.assertEqual(s.compteur_du_jour(WS2), 2)

    def test_plafond_par_espace_seul_refuse_sans_toucher_aux_autres(self):
        s = self.ouvrir(plafond=10, plafond_espace=1)
        self.assertEqual(self.envoi(jt("a"), ws=WS, socle=s).code, 250)
        r = self.envoi(jt("b"), ws=WS, socle=s)
        self.assertEqual((r.code, "espace" in r.texte), (451, True))
        self.assertEqual(self.envoi(jt("c"), ws=WS2, socle=s).code, 250)

    def test_50_parallele_un_seul_restant(self):
        s = self.ouvrir(plafond=1)
        codes = []

        def go(i):
            codes.append(s.decider(WS, jt(i), OK, b"x").code)
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
        self._bornes(utc(2026, 3, 29, 21, 59, 59), utc(2026, 3, 29, 22, 0, 0), avant=utc(2026, 3, 28, 23, 0, 0))

    def test_changement_heure_octobre_journee_de_25h(self):
        self._bornes(utc(2026, 10, 25, 22, 59, 59), utc(2026, 10, 25, 23, 0, 0), avant=utc(2026, 10, 24, 22, 0, 0))

    def _bornes(self, derniere, premiere, avant=None):
        depart = avant or derniere
        chemin = os.path.join(self.rep.name, "bornes.sqlite")
        Stockage.creer(chemin)
        h = Horloge(depart)
        s = Socle.ouvrir(chemin, h, Parametres(plafond=1, plafond_espace=1, secret=SECRET, cle_empreinte=CLE_EMP),
                         monotone=h.monotone)
        self._ouverts.append(s)

        def vie(t):
            while h.t < t:  # la santé du CRM arrive toutes les 10 min : pas de pause
                h.avance(min(600, t - h.t))
                self.assertEqual(self.req("/v1/commandes/sante", ts=h.t, socle=s), 200)
        self.assertEqual(self.req("/v1/commandes/sante", ts=h.t, socle=s), 200)
        self.assertEqual(self.req("/v1/commandes/reprise", ts=h.t, socle=s), 200)
        self.assertTrue(self.eteindre(chemin))
        self.assertEqual(s.decider(WS, jt("a"), OK, b"1").code, 250)
        if avant:
            vie(derniere)
        self.assertEqual(s.decider(WS, jt("b"), OK, b"2").code, 451)  # même journée Paris
        vie(premiere)
        self.assertEqual(s.decider(WS, jt("b"), OK, b"2").code, 250)  # journée suivante

    def test_horloge_reculee_ne_remet_pas_a_zero(self):
        for i in range(3):
            self.envoi(jt(i))
        self.saut(-86400 * 2)
        self.assertEqual(self.envoi(jt("8")).code, 451)
        self.saut(86400 * 2)
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.reprise(), 200)
        self.assertEqual(self.envoi(jt("8")).code, 451)  # le compteur du jour a survécu

    def test_redemarrage_le_compteur_survit(self):
        for i in range(3):
            self.envoi(jt(i))
        s2 = self.ouvrir()
        self.assertEqual(self.envoi(jt("8"), socle=s2).code, 451)


class TestHommeMort(Base):  # S17
    def test_15_min_sans_sante_pause_totale(self):
        self.avance(899)
        self.assertEqual(self.envoi(jt("a")).code, 250)
        self.avance(902)  # > 900 s après la dernière santé valide
        self.assertEqual(self.envoi(jt("b")).code, 451)
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_sante_forgee_ne_rearme_rien(self):
        self.avance(1000)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.req("/v1/commandes/sante", secret=b"autre"), 401)
        self.assertEqual(self.envoi(jt("b")).code, 451)

    def test_sante_valide_seule_ne_reprend_pas_il_faut_reprise(self):
        self.avance(1000)
        self.envoi(jt("a"))
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.envoi(jt("b")).code, 451)
        self.assertEqual(self.req("/v1/commandes/reprise", secret=b"faux"), 401)
        self.assertEqual(self.reprise(), 200)
        self.assertEqual(self.envoi(jt("b")).code, 250)

    def test_une_heure_sans_trafic_puis_sante_puis_envoi_refuse(self):
        """Le trou : aucune requête pendant 1 h => la pause n'était jamais constatée, la santé « réarmait »."""
        self.avance(3600)
        self.assertEqual(self.sante(), 200)  # santé VALIDE, mais elle n'efface pas l'absence
        r = self.envoi(jt("a"))
        self.assertEqual(r.code, 451)
        self.assertIn("pause", r.texte)
        self.assertEqual(self.reprise(), 200)
        self.assertEqual(self.envoi(jt("a")).code, 250)

    def test_tick_constate_la_pause_sans_trafic_et_l_ecrit_sur_disque(self):
        self.avance(901)
        self.assertFalse(self.en_pause())
        self.assertTrue(self.socle.tick())  # fil de 30 s
        self.assertTrue(self.en_pause())
        self.assertTrue(any(e[0] == "homme-mort" for e in self.socle.journal()))
        self.assertEqual(self.sante(), 200)
        self.assertTrue(self.en_pause())  # sante() ne réarme PAS

    def test_tick_pas_de_pause_en_marche_normale(self):
        for _ in range(10):
            self.avance(600)
            self.assertEqual(self.sante(), 200)
            self.assertFalse(self.socle.tick())
        self.assertFalse(self.en_pause())

    def test_tick_saut_d_horloge_ecrit_la_pause(self):
        self.avance(10)
        self.saut(5000)
        self.assertTrue(self.socle.tick())
        self.assertTrue(self.en_pause())

    def test_minuterie_appelle_tick(self):
        appels = threading.Event()
        with mock.patch.object(self.socle, "tick", side_effect=lambda: appels.set() or False):
            arret = self.socle.lancer_minuterie(intervalle=0.01)
            self.assertTrue(appels.wait(5))
            arret()

    def test_pause_survit_au_redemarrage(self):
        self.avance(1000)
        self.envoi(jt("a"))
        self.assertEqual(self.envoi(jt("b"), socle=self.ouvrir()).code, 451)

    def test_sante_rejouee_refusee(self):
        ts, nonce = str(int(self.h.t)), str(uuid.uuid4())
        self.assertEqual(self.req("/v1/commandes/sante", ts=ts, nonce=nonce), 200)
        self.assertEqual(self.req("/v1/commandes/sante", ts=ts, nonce=nonce), 409)

    def test_installation_demarre_en_pause(self):
        chemin = os.path.join(self.rep.name, "neuf.sqlite")
        Stockage.creer(chemin)
        s = self.ouvrir(chemin=chemin)
        self.assertEqual(self.sante(socle=s), 200)
        self.assertEqual(self.envoi(jt("a"), socle=s).code, 451)


class TestHorloge(Base):  # (B) un saut d'horloge ferme ; il n'efface rien et ne déplace pas le plafond
    def test_recul_apres_longue_absence_de_sante_reste_ferme(self):
        self.avance(1000)
        self.saut(-950)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.socle.compteur_du_jour(), 0)

    def test_recul_d_horloge_ferme_et_ne_rouvre_pas_en_revenant(self):
        self.avance(100)
        self.saut(-3600)
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.saut(3600)
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.envoi(jt("b")).code, 451)  # seule la reprise signée rouvre
        self.assertTrue(any(e[0] == "homme-mort" for e in self.socle.journal()))

    def test_saut_en_avant_ferme(self):
        self.avance(100)
        self.saut(+5000)
        self.assertEqual(self.envoi(jt("a")).code, 451)

    def test_petit_saut_en_avant_sans_depasser_15_min_ferme_quand_meme(self):
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(600)  # +10 min seulement : ni « 15 min sans santé » ni « reculée », mais l'horloge a sauté
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertTrue(any(e[0] == "homme-mort" and "saut" in e[1] for e in self.socle.journal()))

    def test_petit_saut_en_arriere_sans_depasser_la_derniere_sante_ferme_quand_meme(self):
        self.avance(300)
        self.saut(-200)  # reste après la dernière santé : seule la comparaison murale/monotone le voit
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertTrue(any(e[0] == "homme-mort" and "saut" in e[1] for e in self.socle.journal()))

    def test_redemarrage_avec_horloge_en_arriere_ferme(self):
        self.saut(-3600)
        self.assertEqual(self.envoi(jt("a"), socle=self.ouvrir()).code, 451)

    def test_scenario_saut_30_jours_jeton_conserve_meme_reponse_un_seul_depot(self):
        r1 = self.envoi(jt("J"))
        self.assertEqual(r1.code, 250)
        self.assertTrue(self.socle.confirmer(r1.id))  # KumoMTA a déposé (log hook K7)
        jour = self.socle.jour_courant()
        self.avance(60)
        self.assertEqual(self.sante(), 200)
        self.saut(30 * 86400)  # l'horloge saute de +30 jours
        self.assertEqual(self.envoi(jt("N")).code, 451)  # saut détecté : fermé
        self.assertTrue(self.en_pause())
        self.assertEqual(self.reprise(), 409)  # tant que l'horloge n'est pas corrigée, la reprise est refusée
        self.assertTrue(self.en_pause())
        self.saut(-30 * 86400)  # correction
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.envoi(jt("N")).code, 451)  # corrigée mais toujours en pause
        self.assertEqual(self.reprise(), 200)
        r2 = self.envoi(jt("J"))  # rejeu de J
        self.assertEqual((r2.code, r2.id, r2.rejoue), (250, r1.id, True))
        self.assertEqual(self.socle.compteur_du_jour(), 1)  # un seul dépôt, plafond du BON jour
        self.assertEqual(self.socle.compteur_du_jour(jour=jour), 1)
        self.assertEqual(self.socle.compteur_du_jour(jour="2026-07-15"), 0)  # rien sur le jour futur
        self.assertEqual(self.envoi(jt("N")).code, 250)

    def test_saut_en_avant_n_efface_pas_les_jetons_reserves(self):
        r1 = self.envoi(jt("J"))
        self.assertEqual(r1.code, 250)
        self.saut(30 * 86400)
        self.assertEqual(self.envoi(jt("N")).code, 451)
        self.saut(-30 * 86400)
        self.assertIsNotNone(self.ligne(jt("J")))
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.reprise(), 200)
        self.avance(130)
        self.assertEqual(self.sante(), 200)
        r2 = self.envoi(jt("J"))  # réservé non confirmé depuis > 2 min : même place, même id
        self.assertEqual((r2.code, r2.id), (250, r1.id))
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_purge_7_jours_mesuree_a_l_horloge_monotone(self):
        r1 = self.envoi(jt("J"))
        self.socle.confirmer(r1.id)
        self.saut(30 * 86400)  # l'horloge murale seule ne vieillit rien
        self.envoi(jt("N"))
        self.assertIsNotNone(self.ligne(jt("J")))
        self.saut(-30 * 86400)
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.reprise(), 200)
        self.vivre(7 * 86400 + 3600)  # vraiment 7 jours + 1 h : alors seulement le jeton est purgé
        self.envoi(jt("Z"))
        self.assertIsNone(self.ligne(jt("J")))

    def test_pas_de_faux_positif_en_marche_normale(self):
        for i in range(5):
            self.avance(300)
            self.assertEqual(self.sante(), 200)
            self.assertEqual(self.envoi(jt(self.h.t)).code, 250 if i < 3 else 451)  # plafond 3


class TestSignature(Base):  # (D) contrat v2, exactement comme SignatureEnvoi::chargeRequete
    def vecteurs(self):
        with open(os.path.join(CONTRAT, "vecteurs-hmac.json"), encoding="utf-8") as f:
            return json.load(f)

    def socle_de(self, secret):
        chemin = os.path.join(self.rep.name, f"v-{uuid.uuid4().hex}.sqlite")
        Stockage.creer(chemin)
        s = Socle.ouvrir(chemin, self.h, Parametres(plafond=1, plafond_espace=1, secret=secret.encode(),
                                                   cle_empreinte=CLE_EMP), monotone=self.h.monotone)
        self._ouverts.append(s)
        return s

    def test_vecteurs_communs_crm_serveur(self):
        """Lit le MÊME fichier que le test Pest du CRM. Si la charge change d'un côté, ceci rougit."""
        v = self.vecteurs()
        self.assertTrue(v["requetes"])
        for r in v["requetes"]:
            s = self.socle_de(v["secret"])
            self.h.t = float(r["horodatage"])
            ok = s.verifier_requete(r["methode"], r["chemin"], r["horodatage"], r["nonce"], v["workspace_id"],
                                    r["corps"], r["entete"])
            self.assertEqual(ok, 200, r["libelle"])
            self.assertEqual(s.verifier_requete(r["methode"], r["chemin"], r["horodatage"], r["nonce"],
                                                v["workspace_id"], r["corps"], r["entete"]), 409, r["libelle"])
            # un seul champ change => 401
            variantes = [("methode", "PUT" if r["methode"] != "PUT" else "POST"), ("chemin", r["chemin"] + "x"),
                         ("horodatage", str(int(r["horodatage"]) + 1)), ("corps", r["corps"] + " "),
                         ("workspace_id", WS2), ("nonce", str(uuid.uuid4()))]
            for champ, valeur in variantes:
                args = dict(methode=r["methode"], chemin=r["chemin"], horodatage=r["horodatage"],
                            nonce=r["nonce"], workspace_id=v["workspace_id"], corps=r["corps"])
                args[champ] = valeur
                s2 = self.socle_de(v["secret"])
                self.h.t = float(args["horodatage"])
                self.assertEqual(s2.verifier_requete(args["methode"], args["chemin"], args["horodatage"],
                                                     args["nonce"], args["workspace_id"], args["corps"],
                                                     r["entete"]), 401, f"{r['libelle']}/{champ}")

    def test_vecteur_de_la_charge_independante(self):
        v = self.vecteurs()["requetes"][0]
        attendu = signature(self.vecteurs()["secret"].encode(), v["methode"], v["chemin"], v["horodatage"],
                            v["nonce"], self.vecteurs()["workspace_id"], v["corps"])
        self.assertEqual(attendu, v["signature"])

    def test_ancienne_signature_commande_ts_supprimee(self):
        mod = importlib.import_module("axion_socle.socle")
        self.assertFalse(hasattr(mod, "signer"))
        self.assertFalse(hasattr(Socle, "allumer_temoins"))
        self.assertFalse(hasattr(Socle, "sante"))

    def test_secret_vide_refuse(self):
        s = self.socle_de("x")
        s.p = Parametres(plafond=1, plafond_espace=1, secret=b"", cle_empreinte=CLE_EMP)
        ts, nonce = str(int(self.h.t)), str(uuid.uuid4())
        sig = signature(b"", "POST", "/v1/commandes/sante", ts, nonce, WS, "{}")
        self.assertEqual(s.traiter("POST", "/v1/commandes/sante", ts, nonce, WS, "{}", sig), 401)

    def test_fenetre_5_minutes(self):
        for delta, attendu in ((299, 200), (-299, 200), (301, 401), (-301, 401)):
            self.assertEqual(self.req("/v1/commandes/sante", ts=self.h.t + delta), attendu, delta)

    def test_horodatage_non_entier_refuse(self):
        for ts in ("12.5", "abc", "", "-5", "1e3", " 1"):
            sig = signature(SECRET, "POST", "/v1/commandes/sante", ts, str(uuid.uuid4()), WS, "{}")
            self.assertEqual(self.socle.traiter("POST", "/v1/commandes/sante", ts, str(uuid.uuid4()), WS, "{}", sig),
                             401, repr(ts))

    def test_nonce_et_workspace_doivent_etre_des_uuid(self):
        self.assertEqual(self.req("/v1/commandes/sante", nonce="pas-un-uuid"), 401)
        self.assertEqual(self.req("/v1/commandes/sante", ws="espace"), 401)
        self.assertEqual(self.req("/v1/commandes/sante", ws=""), 401)

    def test_signature_fausse_ne_consomme_pas_le_nonce(self):
        nonce = str(uuid.uuid4())
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce, secret=b"faux"), 401)
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce), 200)

    def test_formes_d_entete(self):
        for fabriquer in (lambda s: f"k=7,sha256={s}", lambda s: s, lambda s: f"sha256={s}"):
            ts, nonce = str(int(self.h.t)), str(uuid.uuid4())
            sig = signature(SECRET, "POST", "/v1/commandes/sante", ts, nonce, WS, "{}")
            self.assertEqual(self.socle.traiter("POST", "/v1/commandes/sante", ts, nonce, WS, "{}", fabriquer(sig)), 200)
        self.assertEqual(self.socle.traiter("POST", "/v1/commandes/sante", ts, str(uuid.uuid4()), WS, "{}", None), 401)

    def test_comparaison_en_temps_constant(self):
        with mock.patch("axion_socle.signature.hmac.compare_digest", wraps=hmac.compare_digest) as cmp:
            self.assertEqual(self.sante(), 200)
            self.assertTrue(cmp.called)

    def test_nonces_gardes_10_minutes_sur_disque(self):
        nonce = str(uuid.uuid4())
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce), 200)
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce, socle=self.ouvrir()), 409)  # gardé sur disque
        self.avance(590)  # 9 min 50 s : toujours gardé
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce), 409)
        self.avance(20)  # 10 min 10 s : oublié (la fenêtre de 5 min interdit de toute façon le vieil horodatage)
        self.assertEqual(self.req("/v1/commandes/sante", nonce=nonce), 200)

    def test_chemin_inconnu_et_methode(self):
        self.assertEqual(self.req("/v1/commandes/inconnue"), 404)
        self.assertEqual(self.req("/v1/commandes/sante", methode="GET"), 405)

    def test_stockage_indisponible_503(self):
        self.socle.stockage.simuler_disque_plein = True
        self.assertEqual(self.sante(), 503)


class TestTemoins(Base):  # S18 (E) liste blanche d'EMPREINTES, jamais d'adresses en clair
    def actifs(self, *adresses):
        self.assertEqual(self.temoins(True, [E(a) for a in adresses]), 200)

    def test_crm_allume_et_hors_liste_refuse(self):
        self.assertEqual(self.envoi(jt("a"), "autre@example.test").code, 250)
        self.actifs(OK)
        r = self.envoi(jt("b"), "autre@example.test")
        self.assertEqual(r.code, 550)
        self.assertEqual(self.envoi(jt("c"), OK).code, 250)

    def test_normalisation_remplace_la_comparaison_a_l_identique(self):
        self.actifs(OK)
        self.assertEqual(self.envoi(jt("a"), OK.upper()).code, 250)  # la casse est normalisée
        self.assertEqual(self.envoi(jt("b"), " Temoin @ Example.TEST. ").code, 250)
        self.assertEqual(self.envoi(jt("c"), "autre@example.test").code, 550)

    def test_adresses_en_clair_refusees(self):
        for liste in ([OK], ["abc"], [E(OK)[:63]], [E(OK).upper()], [E(OK), 7]):
            self.assertEqual(self.temoins(True, liste), 422, repr(liste))
        self.assertTrue(self.socle.temoins_actifs() is False)

    def test_taille_max_10(self):
        self.assertEqual(self.temoins(True, [E(f"t{i}@example.test") for i in range(11)]), 422)
        self.assertEqual(self.temoins(True, [E(f"t{i}@example.test") for i in range(10)]), 200)

    def test_corps_invalide(self):
        for corps in ("pas du json", "[]", '{"actif": "oui", "liste": []}', '{"actif": true}',
                      '{"liste": []}', '{"actif": true, "liste": "x"}'):
            self.assertEqual(self.req("/v1/commandes/temoins", corps), 422, corps)

    def test_deja_actif_une_nouvelle_liste_ne_peut_que_reduire(self):
        a, b, c = "a@example.test", "b@example.test", "c@example.test"
        self.assertEqual(self.temoins(True, [E(a), E(b)]), 200)
        self.assertEqual(self.temoins(True, [E(b), E(c)]), 200)  # intersection : seul b reste
        self.assertEqual(self.socle.liste_temoins(), frozenset([E(b)]))
        self.assertEqual(self.envoi(jt("1"), c).code, 550)
        self.assertEqual(self.envoi(jt("2"), a).code, 550)
        self.assertEqual(self.envoi(jt("3"), b).code, 250)
        self.assertEqual(self.temoins(True, []), 200)  # vider est une réduction
        self.assertEqual(self.envoi(jt("4"), b).code, 550)

    def test_deja_actif_extinction_par_le_crm_refusee_et_journalisee(self):
        self.actifs(OK)
        self.assertEqual(self.temoins(False, []), 403)
        self.assertEqual(self.temoins(False, [E(OK)]), 403)
        self.assertTrue(self.socle.temoins_actifs())
        self.assertTrue(any(e[0] == "alerte-temoins-crm-refuse" for e in self.socle.journal()))

    def test_extinction_distante_refusee_locale_journalisee(self):
        self.actifs(OK)
        self.assertFalse(self.socle.eteindre_temoins("crm", "x"))
        self.assertFalse(self.socle.eteindre_temoins("local", "x"))
        self.assertTrue(self.socle.temoins_actifs())
        self.assertTrue(self.eteindre(operateur="alice"))
        self.assertFalse(self.socle.temoins_actifs())
        j = self.socle.journal()
        self.assertTrue(any(e[0] == "temoins-off" and "alice" in e[1] for e in j))
        self.assertTrue(any(e[0] == "alerte-temoins-off-refuse" for e in j))

    def test_agrandir_passe_par_la_console_locale_et_se_journalise(self):
        self.actifs(OK)
        nouvelle = [E(OK), E("autre@example.test")]
        self.assertTrue(local.definir_temoins_local(self.chemin, "bob", True, nouvelle))
        self.assertEqual(self.socle.liste_temoins(), frozenset(nouvelle))
        self.assertEqual(self.envoi(jt("a"), "autre@example.test").code, 250)
        self.assertTrue(any(e[0] == "temoins-liste-locale" and "bob" in e[1] for e in self.socle.journal()))
        self.assertFalse(local.definir_temoins_local(self.chemin, "", True, nouvelle))
        self.assertFalse(local.definir_temoins_local(self.chemin, "bob", True, ["en@clair.test"]))
        self.assertFalse(local.definir_temoins_local(self.chemin, "bob", True, [E(f"{i}@x.test") for i in range(11)]))

    def test_inactif_le_crm_peut_allumer_avec_une_liste(self):
        self.assertFalse(self.socle.temoins_actifs())
        self.actifs(OK, "autre@example.test")
        self.assertEqual(len(self.socle.liste_temoins()), 2)

    def test_actif_survit_au_redemarrage(self):
        self.actifs(OK)
        s2 = self.ouvrir()
        self.assertTrue(s2.temoins_actifs())
        self.assertEqual(s2.liste_temoins(), frozenset([E(OK)]))

    def test_allumage_non_signe_refuse(self):
        self.assertEqual(self.req("/v1/commandes/temoins", json.dumps({"actif": True, "liste": []}), secret=b"x"), 401)
        self.assertFalse(self.socle.temoins_actifs())

    def test_cle_d_empreinte_vide_refuse_tout_en_mode_temoins(self):
        s = self.ouvrir()
        s.p = Parametres(plafond=3, plafond_espace=3, secret=SECRET, cle_empreinte=b"")
        self.actifs(OK)
        self.assertEqual(self.envoi(jt("a"), OK, socle=s).code, 550)

    def test_rejeu_reserve_respecte_les_temoins(self):
        r = self.envoi(jt("x"), "reserve@example.test")
        self.assertEqual(r.code, 250)
        self.actifs(OK)
        self.vivre(130)
        self.assertEqual(self.envoi(jt("x"), "reserve@example.test").code, 550)

    def test_vecteurs_d_empreintes_communs_crm_serveur(self):
        with open(os.path.join(CONTRAT, "vecteurs-empreintes-temoins.json"), encoding="utf-8") as f:
            v = json.load(f)
        self.assertTrue(v["cas"])
        for c in v["cas"]:
            self.assertEqual(normaliser(c["adresse"]), c["normalisee"], c["libelle"])
            self.assertEqual(empreinte_temoin(c["adresse"], v["cle"].encode()), c["empreinte"], c["libelle"])

    def test_normalisation_cas_limites(self):
        self.assertEqual(normaliser("sans-arobase"), "sans-arobase")
        self.assertEqual(normaliser("a@"), "a@")
        self.assertEqual(normaliser("a@@b.c."), "a@@b.c")
        self.assertEqual(normaliser("a@" + "é" * 80 + ".fr"), "a@" + "é" * 80 + ".fr")  # IDNA impossible : inchangé


class TestJetons(Base):  # (A) le socle décide ; jeton à deux états
    def test_fsync_synchronous_full(self):
        self.assertEqual(self.stockage_ouvert().pragma("synchronous"), 2)

    def test_reserve_sur_disque_avant_le_250(self):
        vu = []

        def espion():
            vu.append(self.ligne(jt("a")))
        self.socle._apres_reserve = espion
        self.assertEqual(self.envoi(jt("a")).code, 250)
        self.assertEqual(vu[0][0], "reserve")

    def test_jeton_a_deux_etats_et_une_place_de_plafond(self):
        r = self.envoi(jt("a"))
        etat, id_, _ = self.ligne(jt("a"))
        self.assertEqual((etat, id_), ("reserve", r.id))
        self.assertTrue(r.id and len(r.id) == 24)
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.assertTrue(self.socle.confirmer(r.id))
        self.assertEqual(self.ligne(jt("a"))[0], "confirme")
        self.assertFalse(self.socle.confirmer("inconnu"))
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_rejeu_confirme_250_meme_id(self):
        r1 = self.envoi(jt("a"))
        self.socle.confirmer(r1.id)
        self.vivre(3600)
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.id, r2.rejoue), (250, r1.id, True))
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_rejeu_reserve_depuis_moins_de_2_min_451(self):
        r1 = self.envoi(jt("a"))
        self.avance(119)
        r2 = self.envoi(jt("a"))
        self.assertEqual(r2.code, 451)
        self.assertIsNone(r2.id)
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.assertEqual(self.ligne(jt("a"))[1], r1.id)

    def test_rejeu_reserve_depuis_plus_de_2_min_accepte_sur_la_meme_place(self):
        r1 = self.envoi(jt("a"))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.id, r2.rejoue), (250, r1.id, True))
        self.assertEqual(self.socle.compteur_du_jour(), 1)  # pas une seconde place
        self.assertEqual(self.envoi(jt("a")).code, 451)  # la fenêtre de 2 min repart de zéro

    def test_rejeu_reserve_ancien_respecte_pause_et_plafond_inchange(self):
        self.envoi(jt("a"))
        self.avance(1000)  # 15 min sans santé : pause
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.assertEqual(self.socle.compteur_du_jour(), 1)

    def test_arret_brutal_apres_reserve_avant_250_rattrape_par_le_rejeu(self):
        def boom():
            raise ArretBrutal()
        self.socle._apres_reserve = boom
        with self.assertRaises(ArretBrutal):
            self.envoi(jt("a"))  # kill -9 avant l'accusé 250
        s2 = self.ouvrir()  # redémarrage
        self.assertEqual(self.sante(socle=s2), 200)
        self.assertEqual(self.reprise(socle=s2), 200)
        self.assertEqual(self.envoi(jt("a"), socle=s2).code, 451)  # reprise < 2 min : un dépôt pourrait être en cours
        self.avance(125)
        self.assertEqual(self.sante(socle=s2), 200)
        r = self.envoi(jt("a"), socle=s2)
        self.assertEqual(r.code, 250)
        self.assertEqual(s2.compteur_du_jour(), 1, "zéro doublon de plafond")

    def test_meme_jeton_autre_contenu_refuse_et_alerte(self):
        self.envoi(jt("a"), contenu=b"un")
        r = self.envoi(jt("a"), contenu=b"deux")
        self.assertEqual(r.code, 550)
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        j = [e for e in self.socle.journal() if e[0] == "alerte-jeton-contenu"]
        self.assertTrue(j)
        self.assertNotIn(jt("a")[:8], j[0][1])  # le jeton n'est pas journalisé

    def test_jeton_accepte_7_jours(self):
        r1 = self.envoi(jt("a"))
        self.socle.confirmer(r1.id)
        self.vivre(6 * 86400 + 3600)
        self.assertEqual(self.envoi(jt("a")).id, r1.id)
        self.vivre(2 * 86400)
        self.assertEqual(self.envoi(jt("a")).code, 250)  # purgé : nouvelle place
        self.assertNotEqual(self.ligne(jt("a"))[1], r1.id)

    def test_jeton_trop_court_refuse(self):
        self.assertEqual(self.envoi("court").code, 550)

    def test_fichier_supprime_ferme_et_temoins(self):
        os.remove(self.chemin)
        s = self.ouvrir()
        self.assertEqual(self.envoi(jt("a"), socle=s).code, 451)
        self.assertTrue(s.temoins_actifs())

    def test_fichier_illisible_ferme(self):
        with open(self.chemin, "wb") as f:
            f.write(b"pas du sqlite" * 100)
        self.assertEqual(self.envoi(jt("a"), socle=self.ouvrir()).code, 451)

    def test_disque_plein_451(self):
        self.socle.stockage.simuler_disque_plein = True
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.socle.stockage.simuler_disque_plein = False
        self.assertEqual(self.socle.compteur_du_jour(), 0)

    def test_exception_interne_ferme_jamais_accepte(self):
        with mock.patch.object(self.socle, "_decider", side_effect=RuntimeError("boum")):
            self.assertEqual(self.envoi(jt("a")).code, 451)


class TestSocleSeulementDecide(Base):  # (A) KumoMTA dépose seul
    def test_aucune_api_de_depot(self):
        for nom in ("recuperer", "_deposer", "deposer"):
            self.assertFalse(hasattr(Socle, nom), nom)
        with self.assertRaises(ImportError):
            importlib.import_module("axion_socle.sortie")
        self.assertFalse(hasattr(Stockage, "en_attente"))

    def test_aucun_contenu_ni_adresse_ni_jeton_en_clair_sur_disque(self):
        self.envoi(jt("a"), "personne.reelle@example.test", b"CORPS-SECRET-DU-MESSAGE")
        colonnes = {r[1] for r in sqlite3.connect(self.chemin).execute("PRAGMA table_info(jetons)")}
        self.assertNotIn("contenu", colonnes)
        self.assertNotIn("destinataire", colonnes)
        self.assertNotIn("jeton", colonnes)
        for nom in os.listdir(self.rep.name):
            with open(os.path.join(self.rep.name, nom), "rb") as f:
                brut = f.read()
            for secret in (b"CORPS-SECRET-DU-MESSAGE", b"personne.reelle", jt("a").encode()):
                self.assertNotIn(secret, brut, nom)

    def test_empreinte_du_message_pas_du_contenu(self):
        self.envoi(jt("a"), contenu=b"x")
        emp = self.ligne(jt("a"))[2]
        self.assertEqual(len(emp), 64)
        self.assertNotEqual(emp, hashlib.sha256(OK.encode() + b"\0x").hexdigest())  # salée par la clé


class TestStockagePermissions(Base):
    def test_fichier_0600_dossier_0700(self):
        if os.name == "nt":
            self.skipTest("modes POSIX")
        chemin = os.path.join(self.rep.name, "sous", "etat.sqlite")
        Stockage.creer(chemin)
        self.assertEqual(stat.S_IMODE(os.stat(chemin).st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(os.stat(os.path.dirname(chemin)).st_mode), 0o700)
        s = self.ouvrir(chemin=chemin)
        self.assertEqual(self.sante(socle=s), 200)
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

    def test_close_idempotent_et_fichier_supprimable(self):
        st = Stockage(self.chemin)
        st.close()
        st.close()
        s = self.ouvrir()
        s.close()
        s.close()


class TestDurcissements(Base):
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
        self.assertEqual(self.socle.compteur_du_jour(), 0)
        self.assertEqual(self.socle.decider(WS, None, OK, b"x").code, 550)

    def test_jetons_acceptes(self):
        import secrets
        s = self.ouvrir(plafond=10)
        for j in (jt("a"), hashlib.sha512(b"z").hexdigest(), secrets.token_urlsafe(32), secrets.token_hex(16)):
            self.assertEqual(s.decider(WS, j, OK, b"m").code, 250, j)

    def test_espace_invalide_refuse(self):
        for ws in ("", "espace", None):
            self.assertEqual(self.socle.decider(ws, jt("a"), OK, b"x").code, 550, repr(ws))

    def test_commande_temoins_prend_le_verrou(self):
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
            self.temoins(True, [])
            self.socle.eteindre_temoins("crm", "x")
            fini.append(1)
        t2 = threading.Thread(target=allumer)
        t2.start()
        t2.join(0.4)
        self.assertEqual(fini, [])
        libre.set()
        t.join(5)
        t2.join(5)
        self.assertEqual(fini, [1])

    def test_operateur_borne_et_assaini(self):
        self.temoins(True, [])
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
        self.assertFalse(local.eteindre_temoins_local(self.chemin, ""))


if __name__ == "__main__":
    unittest.main()
