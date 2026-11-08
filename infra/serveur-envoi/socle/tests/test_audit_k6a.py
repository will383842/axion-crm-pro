"""Tests de l'audit sécurité K6a (écrits AVANT le code, vus ROUGES). B1 rejeu contrôlé, I1 commande pause + signature de
réponse, I2 CI robuste, I4 témoins par espace (451), mineurs M1 M2 M3 M4 M8."""
import ast
import json
import os
import re
import sqlite3
import stat
import tempfile
import unittest
import uuid
from unittest import mock

from axion_socle import signature as sig
from axion_socle.empreinte import empreinte_contrat, empreinte_temoin
from axion_socle.socle import Parametres, Socle
from axion_socle.stockage import ErreurStockage, Stockage
from tests.test_socle import CLE_EMP, CONTRAT, OK, RACINE, SECRET, WS, WS2, WS3, Base, E, jt, signature

SOCLE = os.path.join(RACINE, "infra", "serveur-envoi", "socle")
WS4 = "44444444-4444-4444-8444-444444444444"


def lire(*chemin):
    with open(os.path.join(*chemin), encoding="utf-8") as f:
        return f.read()


class TestRejeuControle(Base):  # B1 : un rejeu n'est JAMAIS un passe-droit
    def confirme(self, jeton, dest=OK):
        r = self.envoi(jeton, dest)
        self.assertEqual(r.code, 250)
        self.assertTrue(self.socle.confirmer(r.id))
        return r

    def test_homme_mort_declenche_puis_rejeu_confirme_refuse(self):
        r1 = self.confirme(jt("a"))
        self.avance(1000)  # plus de 15 min sans santé : pause totale
        r2 = self.envoi(jt("a"))
        self.assertEqual(r2.code, 451)
        self.assertFalse(r2.rejoue)
        self.assertIsNone(r2.id)
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.assertEqual(r1.code, 250)

    def test_temoins_allumes_rejeu_confirme_hors_liste_refuse(self):
        self.confirme(jt("a"), "hors@example.test")
        self.assertEqual(self.temoins(True, [E(OK)]), 200)
        r = self.envoi(jt("a"), "hors@example.test")
        self.assertEqual(r.code, 451)
        self.assertFalse(r.rejoue)

    def test_rejeu_confirme_dans_la_liste_passe_et_demande_de_ne_pas_deposer(self):
        r1 = self.confirme(jt("a"))
        self.assertEqual(self.temoins(True, [E(OK)]), 200)
        r2 = self.envoi(jt("a"))
        self.assertEqual((r2.code, r2.id, r2.rejoue), (250, r1.id, True))

    def test_n_rejeux_une_seule_place_un_seul_depot(self):
        depots = 0
        for i in range(12):
            r = self.envoi(jt("a"))
            if r.code == 250 and not r.rejoue:
                depots += 1  # KumoMTA ne dépose QUE si rejoue est faux
            if i == 0:
                self.socle.confirmer(r.id)
        self.assertEqual(depots, 1)
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.assertEqual(self.socle.compteur_du_jour(WS), 1)

    def test_reacceptation_de_reserve_est_bornee_puis_451_definitif_logge(self):
        r1 = self.envoi(jt("a"))
        for _ in range(3):  # 3 ré-acceptations au plus
            self.avance(121)
            self.assertEqual(self.sante(), 200)
            self.assertIsNone(self.envoi(jt("a")).deposer)  # ambigu tant que personne ne tranche
            self.assertTrue(self.socle.trancher(r1.id, deja_depose=False))
            r = self.envoi(jt("a"))
            self.assertEqual((r.code, r.id, r.rejoue, r.deposer), (250, r1.id, False, True))
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertTrue(self.socle.trancher(r1.id, deja_depose=False))
        r = self.envoi(jt("a"))
        self.assertEqual(r.code, 451)
        self.assertIn("definitif", r.texte)
        self.avance(121)
        self.assertEqual(self.sante(), 200)
        self.assertEqual(self.envoi(jt("a")).code, 451)  # reste refusé
        self.assertEqual(self.socle.compteur_du_jour(), 1)
        self.assertTrue(any(e[0] == "jeton-reacceptations-max" for e in self.socle.journal()))

    def test_jeton_rattache_a_son_espace(self):
        self.confirme(jt("a"))
        r = self.envoi(jt("a"), ws=WS2)
        self.assertEqual(r.code, 550)
        self.assertFalse(r.rejoue)
        self.assertTrue(any(e[0] == "alerte-jeton-espace" for e in self.socle.journal()))

    def test_contrat_rejoue_fige_dans_le_readme_et_le_relais_lua(self):
        readme = lire(SOCLE, "README.md")
        lua = lire(SOCLE, "kumomta", "socle_adaptateur.lua.modele")
        for texte in (readme, lua):
            self.assertIn("rejoue", texte)
            self.assertIn("SANS mettre en file", texte)
        self.assertIn("rejoue=true", readme)
        self.assertRegex(lua, r"reponse\.rejoue")
        self.assertIn("null", lua)  # le message est jeté dans la file nulle
        self.assertIn("RÉCEPTION", readme)
        self.assertIn("pas à la livraison", readme)
        self.assertRegex(readme, r"451 d[ée]finitif")


class TestCommandePause(Base):  # I1 : le CRM envoie pause|reprise|retrait (ClientServeurEnvoiHmac)
    def vecteur(self):
        with open(os.path.join(CONTRAT, "vecteurs-hmac.json"), encoding="utf-8") as f:
            return json.load(f)

    def pause(self, corps=None, ws=WS):
        corps = corps if corps is not None else json.dumps({"commande_id": str(uuid.uuid4()), "commande": "pause"})
        return self.req("/v1/commandes/pause", corps, ws=ws)

    def test_vecteur_pause_via_traiter_ecrit_la_pause_sur_disque(self):
        v = self.vecteur()
        r = next(x for x in v["requetes"] if x["chemin"] == "/v1/commandes/pause")
        chemin = os.path.join(self.rep.name, "vecteur.sqlite")
        Stockage.creer(chemin)
        s = Socle.ouvrir(chemin, self.h, Parametres(plafond=3, secret=v["secret"].encode(), cle_empreinte=CLE_EMP),
                         monotone=self.h.monotone)
        self._ouverts.append(s)
        self.h.t = float(r["horodatage"])
        for c in ("sante", "reprise"):
            ts, n = str(int(self.h.t)), str(uuid.uuid4())
            e = signature(v["secret"].encode(), "POST", f"/v1/commandes/{c}", ts, n, v["workspace_id"], "{}")
            self.assertEqual(s.traiter("POST", f"/v1/commandes/{c}", ts, n, v["workspace_id"], "{}", f"k=1,sha256={e}"),
                             200)
        self.assertTrue(self.eteindre(chemin))
        self.assertEqual(s.decider(v["workspace_id"], jt("a"), OK, b"x").code, 250)
        self.assertEqual(s.traiter(r["methode"], r["chemin"], r["horodatage"], r["nonce"], v["workspace_id"],
                                   r["corps"], r["entete"]), 200)
        self.assertEqual(self.stockage_ouvert(chemin).meta("pause_hm"), "1")
        self.assertEqual(s.decider(v["workspace_id"], jt("b"), OK, b"x").code, 451)
        self.assertTrue(any(e[0] == "pause-crm" for e in s.journal()))

    def test_pause_toujours_permise_et_idempotente(self):
        self.assertEqual(self.pause(), 200)
        self.assertEqual(self.pause(), 200)  # déjà en pause : toujours 200
        self.assertTrue(self.en_pause())
        self.assertEqual(self.sante(), 200)  # la santé ne lève rien
        self.assertTrue(self.en_pause())

    def test_pause_signature_fausse_ou_nonce_rejoue_sans_effet(self):
        corps = json.dumps({"commande_id": str(uuid.uuid4()), "commande": "pause"})
        self.assertEqual(self.req("/v1/commandes/pause", corps, secret=b"faux"), 401)
        self.assertFalse(self.en_pause())

    def test_pause_corps_invalide_422_sans_effet(self):
        bons = str(uuid.uuid4())
        for corps in ("pas du json", "[]", json.dumps({"commande": "pause"}),
                      json.dumps({"commande_id": "x", "commande": "pause"}),
                      json.dumps({"commande_id": bons, "commande": "reprise"}),
                      json.dumps({"commande_id": bons, "commande": "pause", "en_trop": 1})):
            self.assertEqual(self.pause(corps), 422, corps)
        self.assertFalse(self.en_pause())

    def test_retrait_releve_de_la_passerelle_k2(self):
        corps = json.dumps({"commande_id": str(uuid.uuid4()), "commande": "retrait"})
        self.assertEqual(self.req("/v1/commandes/retrait", corps), 404)
        self.assertFalse(self.en_pause())
        readme = lire(SOCLE, "README.md")
        self.assertRegex(readme, r"retrait[^\n]*K2")

    def test_reprise_reste_refusee_sans_correction_apres_une_pause_crm(self):
        self.assertEqual(self.pause(), 200)
        self.assertEqual(self.reprise(), 200)  # reprise signée : seule façon d'ouvrir
        self.assertFalse(self.en_pause())

    def test_signature_de_reponse_du_contrat_v2(self):
        v = self.vecteur()
        for r in v["reponses"]:
            self.assertEqual(sig.signer_reponse(v["secret"].encode(), r["nonce"], r["workspace_id"], r["statut"],
                                                r["corps"]), r["signature"], r["libelle"])
            self.assertNotEqual(sig.signer_reponse(v["secret"].encode(), r["nonce"], r["workspace_id"], 500,
                                                   r["corps"]), r["signature"])
        self.assertEqual(sig.signer_reponse(b"", "n", "w", 200, ""), "")  # secret vide : aucune signature


class TestTemoinsParEspace(Base):  # I4
    def test_drapeau_et_liste_par_espace(self):
        self.assertEqual(self.temoins(True, [E(OK)], ws=WS), 200)
        self.assertTrue(self.socle.temoins_actifs(WS))
        self.assertFalse(self.socle.temoins_actifs(WS2))
        self.assertEqual(self.socle.liste_temoins(WS2), frozenset())
        self.assertEqual(self.envoi(jt("a"), "autre@example.test", ws=WS2).code, 250)
        self.assertEqual(self.envoi(jt("b"), "autre@example.test", ws=WS).code, 451)
        self.assertEqual(self.envoi(jt("c"), OK, ws=WS).code, 250)

    def test_espace_jamais_configure_est_ferme(self):
        r = self.envoi(jt("a"), OK, ws=WS4)
        self.assertEqual(r.code, 451)
        self.assertTrue(self.socle.temoins_actifs(WS4))

    def test_liste_d_un_espace_ne_vaut_pas_pour_un_autre(self):
        self.assertEqual(self.temoins(True, [E(OK)], ws=WS), 200)
        self.assertEqual(self.temoins(True, [E("autre@example.test")], ws=WS2), 200)
        self.assertEqual(self.envoi(jt("a"), OK, ws=WS2).code, 451)
        self.assertEqual(self.envoi(jt("b"), OK, ws=WS).code, 250)

    def test_extinction_locale_par_espace(self):
        self.assertEqual(self.temoins(True, [], ws=WS), 200)
        self.assertEqual(self.temoins(True, [], ws=WS2), 200)
        self.assertTrue(self.eteindre(ws=WS))
        self.assertFalse(self.socle.temoins_actifs(WS))
        self.assertTrue(self.socle.temoins_actifs(WS2))

    def test_refus_temoins_est_451_report_jamais_550(self):
        self.assertEqual(self.temoins(True, [E(OK)]), 200)
        r = self.envoi(jt("a"), "autre@example.test")
        self.assertEqual(r.code, 451)
        self.assertIn("report", r.texte)
        self.assertIn("temoins", r.texte)
        self.assertEqual(self.socle.compteur_du_jour(), 0)  # aucune place consommée
        self.assertIn("451", lire(SOCLE, "README.md").split("Mode témoins")[1])

    def test_rejeu_reserve_ancien_respecte_les_temoins_de_son_espace(self):
        self.envoi(jt("x"), "reserve@example.test", ws=WS2)
        self.assertEqual(self.temoins(True, [E(OK)], ws=WS2), 200)
        self.vivre(130)
        self.assertEqual(self.envoi(jt("x"), "reserve@example.test", ws=WS2).code, 451)
        self.assertEqual(self.socle.compteur_du_jour(WS2), 1)


class TestMineurs(Base):
    def sql(self, requete, *args):
        c = sqlite3.connect(self.chemin)
        try:
            c.execute(requete, args)
            c.commit()
        finally:
            c.close()

    def test_m1_meta_pause_absente_ferme(self):
        self.sql("DELETE FROM meta WHERE cle='pause_hm'")
        self.assertEqual(self.envoi(jt("a")).code, 451)

    def test_m1_meta_temoins_absente_ou_illisible_ferme(self):
        self.sql("DELETE FROM meta WHERE cle LIKE 'temoins%'")
        self.assertTrue(self.socle.temoins_actifs(WS))
        self.assertEqual(self.envoi(jt("a")).code, 451)
        self.sql("INSERT OR REPLACE INTO meta VALUES(?, 'peut-etre')", f"temoins:{WS}")
        self.assertTrue(self.socle.temoins_actifs(WS))  # seule la valeur '0' ouvre
        self.assertEqual(self.envoi(jt("b")).code, 451)

    def test_m2_statut_500_documente_et_rendu(self):
        with mock.patch.object(self.socle, "_sante", side_effect=RuntimeError("boum")):
            self.assertEqual(self.sante(), 500)
        self.assertRegex(lire(SOCLE, "README.md"), r"Statuts[^\n]*\b500\b")

    @unittest.skipIf(os.name == "nt", "modes POSIX")
    def test_m3_droits_du_fichier_verifies_a_l_ouverture(self):
        self.socle.close()
        os.chmod(self.chemin, 0o644)
        with self.assertRaises(ErreurStockage):
            Stockage(self.chemin)
        s = self.ouvrir()
        self.assertTrue(s.ferme)
        self.assertEqual(self.envoi(jt("a"), socle=s).code, 451)
        os.chmod(self.chemin, 0o600)
        self.assertFalse(self.ouvrir().ferme)
        self.assertEqual(stat.S_IMODE(os.stat(self.chemin).st_mode), 0o600)

    def test_m4_reseau_n_importe_pas_local_et_n_ecrit_pas_dans_meta(self):
        d = os.path.join(SOCLE, "axion_socle")
        self.assertEqual(violations_reseau(d), [])
        with tempfile.TemporaryDirectory() as t:  # le détecteur DOIT rougir sur un module fautif
            for nom, src in {"a.py": "from . import local\n", "b.py": "import axion_socle.local\n",
                             "c.py": "from .local import eteindre_temoins_local\n",
                             "d.py": "def f(c):\n    c.execute(\"UPDATE meta SET valeur='0' WHERE cle='temoins'\")\n",
                             "e.py": "def f(c):\n    c.execute('INSERT OR REPLACE INTO meta VALUES(1,2)')\n",
                             "f.py": "import importlib\nimportlib.import_module('axion_socle.local')\n"}.items():
                with open(os.path.join(t, nom), "w", encoding="utf-8") as f:
                    f.write(src)
            assert_toutes = {v.split(":")[0] for v in violations_reseau(t)}
            self.assertEqual(assert_toutes, {"a.py", "b.py", "c.py", "d.py", "e.py", "f.py"})

    def test_m8_kelvin_et_non_ascii_jamais_confondus_avec_un_temoin(self):
        self.assertEqual(self.temoins(True, [E("kelvin@example.test")]), 200)
        self.assertIsNone(empreinte_temoin("Kelvin@example.test", CLE_EMP))  # K = signe KELVIN
        self.assertIsNone(empreinte_temoin("kelvin@Kelvin.test", CLE_EMP))
        self.assertEqual(self.envoi(jt("a"), "Kelvin@example.test").code, 451)
        self.assertEqual(self.envoi(jt("b"), "KELVIN@Example.TEST").code, 250)  # ASCII : normalisé comme avant
        # le contrat partagé (vecteurs CRM) n'est PAS modifié : même empreinte qu'avant
        self.assertEqual(empreinte_contrat("ÉLODIE@Example.test", CLE_EMP), empreinte_contrat("élodie@example.test", CLE_EMP))


class TestCI(unittest.TestCase):  # I2
    def test_le_controle_exige_tourne_sur_toute_pull_request(self):
        txt = lire(RACINE, ".github", "workflows", "serveur-envoi-socle.yml")
        pr = txt.split("pull_request:")[1].split("push:")[0]
        self.assertNotIn("paths", pr, "un filtre paths laisse le contrôle exigé « en attente » sur les autres PR")
        self.assertIn("name: Tests du socle d'envoi", txt)


def violations_reseau(dossier):
    """Modules « réseau » = tous sauf local.py et stockage.py : ni import de `local`, ni SQL sur `meta`."""
    res = []
    for nom in sorted(os.listdir(dossier)):
        if not nom.endswith(".py") or nom in ("local.py", "stockage.py"):
            continue
        src = lire(dossier, nom)
        for noeud in ast.walk(ast.parse(src)):
            if isinstance(noeud, ast.ImportFrom) and ((noeud.module or "").split(".")[-1] == "local"
                                                       or any(a.name == "local" for a in noeud.names)):
                res.append(f"{nom}:import local")
            if isinstance(noeud, ast.Import) and any(a.name.split(".")[-1] == "local" for a in noeud.names):
                res.append(f"{nom}:import local")
            if isinstance(noeud, ast.Constant) and isinstance(noeud.value, str):
                v = noeud.value
                if re.search(r"(?i)\b(insert\s+(or\s+\w+\s+)?into|update|delete\s+from|replace\s+into)\s+meta\b", v):
                    res.append(f"{nom}:ecriture meta")
                if v == "axion_socle.local" or v.endswith(".local"):
                    res.append(f"{nom}:import dynamique local")
    return res


if __name__ == "__main__":
    unittest.main()
