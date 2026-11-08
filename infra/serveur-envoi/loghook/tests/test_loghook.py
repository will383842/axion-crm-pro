"""Tests K7 (RED d'abord) : classifieur TOML, relais, scenarios S06/S07/S08/S15 sur puits simules."""
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

from axion_loghook.classifieur import classifier
from axion_loghook.regles import ReglesInvalides, charger
from axion_loghook.relais import PuitsMemoire, Relais
from axion_loghook.serveur import PuitsHttp, creer

RACINE = pathlib.Path(__file__).resolve().parent.parent
REGLES = RACINE / "rebonds" / "rebonds.toml"


def rec(type_, code, statut, rid="r1", texte="", jeton=None, inj="inj-1"):
    # Enregistrement de log KumoMTA simplifie (aucune adresse reelle : domaines .test).
    c, s, d = statut.split(".") if statut else (None, None, None)
    r = {"type": type_, "id": rid, "recipient": "x@dest.example.test", "meta": {"id_injection": inj}, "response": {"code": code, "content": texte}}
    if statut:
        r["response"]["enhanced_status_code"] = {"class": int(c), "subject": int(s), "detail": int(d)}
    if jeton:
        r["meta"]["jeton"] = jeton
    return r


def relais():
    p = PuitsMemoire()
    return Relais(charger(REGLES), p, source="kumo-test", workspace="11111111-1111-4111-8111-111111111111"), p


class Classification(unittest.TestCase):
    def test_s06_rebond_dur_utilisateur_inconnu(self):
        self.assertEqual(classifier(rec("Bounce", 550, "5.1.1", texte="user unknown"), charger(REGLES)), "rebond_dur")

    def test_s07_rebond_mou_boite_pleine(self):
        self.assertEqual(classifier(rec("Bounce", 552, "5.2.2", texte="mailbox full"), charger(REGLES)), "rebond_mou")

    def test_s07_rebond_mou_reputation_par_texte(self):
        self.assertEqual(classifier(rec("Bounce", 554, "5.7.1", texte="blocked as spam"), charger(REGLES)), "rebond_mou")

    def test_s08_report_4xx(self):
        self.assertEqual(classifier(rec("TransientFailure", 451, "4.7.0", texte="try later"), charger(REGLES)), "report")

    def test_s08_expiration_est_un_rebond_mou_jamais_dur(self):
        self.assertEqual(classifier(rec("Expiration", 0, None), charger(REGLES)), "rebond_mou")

    def test_livre_et_reception_ne_sont_pas_des_rebonds(self):
        self.assertIsNone(classifier(rec("Delivery", 250, "2.0.0"), charger(REGLES)))
        self.assertIsNone(classifier(rec("Reception", 0, None), charger(REGLES)))


class Relayage(unittest.TestCase):
    def test_s06_evenement_rebond_dur_sans_adresse_en_clair(self):
        r, p = relais()
        r.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
        self.assertEqual([e["type"] for e in p.evenements], ["rebond_dur"])
        self.assertNotIn("dest.example.test", json.dumps(p.evenements))
        self.assertEqual(p.evenements[0]["id_injection"], "inj-1")

    def test_s07_s08_types(self):
        r, p = relais()
        r.traiter(rec("Bounce", 552, "5.2.2", rid="a"))
        r.traiter(rec("TransientFailure", 451, "4.7.0", rid="b"))
        r.traiter(rec("Delivery", 250, "2.0.0", rid="c"))
        r.traiter(rec("Reception", 0, None, rid="d", jeton="j" * 32))
        self.assertEqual([e["type"] for e in p.evenements], ["rebond_mou", "report", "livre", "reception"])
        self.assertEqual(p.evenements[3]["jeton"], "j" * 32)

    def test_numerotation_source_continue_apres_redemarrage(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "src_seq"
            for _ in range(2):  # deux processus successifs
                p = PuitsMemoire()
                x = Relais(charger(REGLES), p, source="kumo-test", workspace="w", fichier_etat=etat)
                x.traiter(rec("Bounce", 550, "5.1.1", rid="n%s" % len(p.evenements)))
                x.traiter(rec("Delivery", 250, "2.0.0", rid="m"))
            self.assertEqual(etat.read_text().strip(), "4")

    def test_type_inconnu_ignore_et_journalise(self):
        r, p = relais()
        r.traiter(rec("Rejection", 550, "5.7.1"))
        self.assertEqual(p.evenements, [])
        self.assertEqual(r.ignores, 1)

    def test_s15_puits_en_panne_ne_perd_rien(self):
        r, p = relais()
        p.panne = True
        with self.assertRaises(IOError):
            r.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
        p.panne = False
        r.traiter(rec("Bounce", 550, "5.1.1", rid="a"))  # rejeu : meme id_kumo, meme src_seq
        self.assertEqual([(e["id_kumo"], e["src_seq"]) for e in p.evenements], [("a", 1)])


class RèglesToml(unittest.TestCase):
    def valider(self, texte):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "x.toml"
            f.write_text(texte)
            return subprocess.run([sys.executable, "-m", "axion_loghook.valider_toml", str(f)], cwd=RACINE, capture_output=True, text=True)

    def test_regles_livrees_valides(self):
        r = subprocess.run([sys.executable, "-m", "axion_loghook.valider_toml", str(RACINE / "rebonds")], cwd=RACINE, capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_toml_invalide_ci_rouge(self):
        self.assertNotEqual(self.valider("[[regle]\nnom = ").returncode, 0)

    def test_classe_inconnue_ci_rouge(self):
        self.assertNotEqual(self.valider('defaut_bounce="rebond_dur"\ndefaut_transitoire="report"\n[[regle]]\nnom="a"\nclasse="supprimer_tout"\nstatut_prefixe="5.1"\n').returncode, 0)

    def test_defaut_absent_ci_rouge(self):
        self.assertNotEqual(self.valider('[[regle]]\nnom="a"\nclasse="rebond_dur"\nstatut_prefixe="5.1"\n').returncode, 0)

    def test_dossier_vide_ci_rouge(self):
        with tempfile.TemporaryDirectory() as d:
            r = subprocess.run([sys.executable, "-m", "axion_loghook.valider_toml", d], cwd=RACINE, capture_output=True, text=True)
            self.assertNotEqual(r.returncode, 0)

    def test_charger_refuse_defaut_absent_ou_inconnu(self):
        for texte in ('defaut_transitoire="report"\n', 'defaut_bounce="tout_supprimer"\ndefaut_transitoire="report"\n'):
            with tempfile.TemporaryDirectory() as d:
                f = pathlib.Path(d) / "x.toml"
                f.write_text(texte)
                with self.assertRaises(ReglesInvalides):
                    charger(f)

    def test_charger_refuse_toml_invalide(self):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "x.toml"
            f.write_text("pas = du toml [")
            with self.assertRaises(ReglesInvalides):
                charger(f)


class Chaine(unittest.TestCase):
    """Kumo -> serveur local -> PuitsHttp -> fausse passerelle (sockets reelles sur 127.0.0.1)."""

    def setUp(self):
        import threading
        from http.server import BaseHTTPRequestHandler, HTTPServer
        recus, etat = [], {"code": 503}

        class Fausse(BaseHTTPRequestHandler):
            def do_POST(self):
                corps = self.rfile.read(int(self.headers["Content-Length"]))
                if etat["code"] == 200:
                    recus.append(json.loads(corps))
                self.send_response(etat["code"])
                self.send_header("Content-Length", "0")
                self.end_headers()

            def log_message(self, *a):
                pass

        self.recus, self.etat = recus, etat
        self.fausse = HTTPServer(("127.0.0.1", 0), Fausse)
        puits = PuitsHttp("http://127.0.0.1:%d/loghook.php" % self.fausse.server_port)
        self.relais = Relais(charger(REGLES), puits, source="kumo-test", workspace="11111111-1111-4111-8111-111111111111")
        self.srv = creer(self.relais)
        for s_ in (self.fausse, self.srv):
            threading.Thread(target=s_.serve_forever, daemon=True).start()

    def tearDown(self):
        for s_ in (self.fausse, self.srv):
            s_.shutdown()
            s_.server_close()

    def poster(self, corps):
        import urllib.error
        import urllib.request
        try:
            return urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % self.srv.server_port, corps, {})).status
        except urllib.error.HTTPError as e:
            return e.code

    def test_s15_passerelle_en_panne_503_puis_rejeu_sans_perte(self):
        corps = json.dumps(rec("Bounce", 550, "5.1.1", rid="z")).encode()
        self.assertEqual(self.poster(corps), 503)
        self.etat["code"] = 200
        self.assertEqual(self.poster(corps), 200)
        self.assertEqual([(e["id_kumo"], e["src_seq"], e["type"]) for e in self.recus], [("z", 1, "rebond_dur")])

    def test_json_invalide_400(self):
        self.assertEqual(self.poster(b"{pas du json"), 400)

    def test_puits_http_refuse_une_autre_adresse_que_127_0_0_1(self):
        with self.assertRaises(ValueError):
            PuitsHttp("http://203.0.113.9:80/x")


class HookLua(unittest.TestCase):
    LUA = (RACINE.parent / "kumomta" / "loghook.lua").read_text()

    def test_relais_local_seulement_et_reessai_si_indisponible(self):
        self.assertIn("http://127.0.0.1:2580/", self.LUA)
        self.assertIn("kumo.reject(451", self.LUA)

    def test_les_cinq_types_utiles_seulement(self):
        for t in ("Reception", "Delivery", "Bounce", "TransientFailure", "Expiration"):
            self.assertRegex(self.LUA, t + r" = \{ enable = true")
        self.assertIn("Any = { enable = false }", self.LUA)

    def test_charge_par_init_lua(self):
        self.assertIn("require 'loghook'", (RACINE.parent / "kumomta" / "init.lua").read_text())


if __name__ == "__main__":
    unittest.main()
