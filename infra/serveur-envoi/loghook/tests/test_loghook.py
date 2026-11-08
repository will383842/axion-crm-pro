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


WS_A = "11111111-1111-4111-8111-111111111111"
WS_B = "22222222-2222-4222-8222-222222222222"


def rec(type_, code, statut, rid="r1", texte="", jeton=None, inj="inj-1", espace=WS_A):
    """Enregistrement fidele au journal KumoMTA (doc de reference « Log Record ») : type, id, sender, recipient,
    queue, site, size, response{code, enhanced_code{class,subject,detail}, content, command}, timestamp, created,
    num_attempts, meta... Aucune adresse reelle : domaines .test."""
    r = {
        "type": type_, "id": rid, "sender": "envoi@exp.example.test", "recipient": "x@dest.example.test",
        "queue": "campagne:dest.example.test", "site": "dest.example.test", "size": 1200,
        "response": {"code": code, "content": texte, "command": "RCPT TO:<x@dest.example.test>"},
        "peer_address": {"name": "mx.dest.example.test", "addr": "192.0.2.10"},
        "timestamp": 1_800_000_000, "created": 1_799_999_990, "num_attempts": 1,
        "bounce_classification": "Uncategorized", "egress_pool": "pool-test", "egress_source": "src-test",
        "meta": {"id_injection": inj, "espace": espace},
    }
    if statut:
        c, s_, d = statut.split(".")
        r["response"]["enhanced_code"] = {"class": int(c), "subject": int(s_), "detail": int(d)}
    if jeton:
        r["meta"]["jeton"] = jeton
    return r


def relais():
    p = PuitsMemoire()
    return Relais(charger(REGLES), p, source="kumo-test"), p


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
                x = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat)
                x.traiter(rec("Bounce", 550, "5.1.1", rid="n%s" % len(p.evenements)))
                x.traiter(rec("Delivery", 250, "2.0.0", rid="m"))
            self.assertEqual(json.loads(etat.read_text())["seq"], {WS_A: 4})

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
        puits = PuitsHttp("http://127.0.0.1:%d/loghook.php" % self.fausse.server_port, secret="s" * 32)
        self.relais = Relais(charger(REGLES), puits, source="kumo-test")
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
            PuitsHttp("http://203.0.113.9:80/x", secret="s" * 32)


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


class FormatReelKumo(unittest.TestCase):
    """B2 : le journal KumoMTA ecrit response.enhanced_code (pas enhanced_status_code)."""

    def c(self, *a, **k):
        return classifier(rec(*a, **k), charger(REGLES))

    def test_b2_5_2_2_boite_pleine_est_un_rebond_mou(self):
        self.assertEqual(self.c("Bounce", 552, "5.2.2", texte="mailbox full"), "rebond_mou")

    def test_b2_5_7_reputation_n_est_jamais_un_rebond_dur_de_la_personne(self):
        self.assertNotEqual(self.c("Bounce", 554, "5.7.1", texte="blocked using blacklist"), "rebond_dur")
        self.assertEqual(self.c("Bounce", 554, "5.7.1", texte="blocked using blacklist"), "rebond_mou")

    def test_b2_5_7_sans_texte_connu_n_est_pas_dur(self):
        self.assertNotEqual(self.c("Bounce", 554, "5.7.1", texte="refus"), "rebond_dur")

    def test_b2_5_1_1_est_dur(self):
        self.assertEqual(self.c("Bounce", 550, "5.1.1", texte="user unknown"), "rebond_dur")

    def test_b2_l_ancien_nom_de_champ_est_encore_lu(self):
        r = rec("Bounce", 552, None, texte="full")
        r["response"]["enhanced_status_code"] = {"class": 5, "subject": 2, "detail": 2}
        self.assertEqual(classifier(r, charger(REGLES)), "rebond_mou")

    def test_b2_la_fabrique_reproduit_le_format_kumo(self):
        r = rec("Bounce", 550, "5.1.1")
        for cle in ("type", "id", "sender", "recipient", "queue", "site", "size", "timestamp", "created", "num_attempts"):
            self.assertIn(cle, r)
        self.assertEqual(r["response"]["enhanced_code"], {"class": 5, "subject": 1, "detail": 1})
        self.assertNotIn("enhanced_status_code", r["response"])

    def test_i7_bounce_sans_regle_est_inconnu_ni_dur_ni_mou(self):
        self.assertEqual(self.c("Bounce", 550, "5.9.9", texte="autre"), "inconnu")
        self.assertEqual(self.c("Bounce", 550, None, texte="sans statut"), "inconnu")

    def test_i7_inconnu_est_remonte_et_journalise(self):
        r, p = relais()
        r.traiter(rec("Bounce", 550, "5.9.9", rid="u"))
        self.assertEqual([e["type"] for e in p.evenements], ["inconnu"])
        self.assertEqual(r.journal[-1][0], "inconnu")

    def test_i7_defaut_bounce_dur_refuse_par_le_validateur(self):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "x.toml"
            f.write_text('defaut_bounce="rebond_dur"\ndefaut_transitoire="report"\n')
            with self.assertRaises(ReglesInvalides):
                charger(f)

    def test_i7_5_7_26_est_une_alerte_de_configuration(self):
        self.assertEqual(self.c("Bounce", 550, "5.7.26", texte="unauthenticated sender, dmarc"), "alerte_config")
        r, p = relais()
        r.traiter(rec("Bounce", 550, "5.7.26", rid="c"))
        self.assertEqual([e["type"] for e in p.evenements], ["alerte_config"])
        self.assertEqual(r.alertes, ["alerte_config"])


class ReglesDurcies(unittest.TestCase):
    def refus(self, texte):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "x.toml"
            f.write_text(texte)
            with self.assertRaises(ReglesInvalides):
                charger(f)

    ENTETE = 'defaut_bounce="inconnu"\ndefaut_transitoire="report"\n'

    def test_expiration_jamais_dure(self):
        self.refus(self.ENTETE + '[[regle]]\nnom="a"\nclasse="rebond_dur"\ntype="Expiration"\n')

    def test_cle_inconnue_refusee_dans_une_regle(self):
        self.refus(self.ENTETE + '[[regle]]\nnom="a"\nclasse="rebond_mou"\nstatut_prefixe="5.2"\nstatut_prefix="5.1"\n')

    def test_cle_inconnue_refusee_au_sommet(self):
        self.refus(self.ENTETE + 'defaut_expiration="rebond_dur"\n')

    def test_i7_5_7_26_ne_peut_pas_devenir_un_rebond_dur(self):
        self.refus(self.ENTETE + '[[regle]]\nnom="a"\nclasse="rebond_dur"\nstatut_prefixe="5.7.26"\n')


class MixageDEspaces(unittest.TestCase):
    """I1 : le hook remonte l'espace de la session d'injection K4 ; le relais numerote PAR espace."""

    def test_rebond_de_l_espace_b_jamais_servi_a_l_espace_a(self):
        r, p = relais()
        r.traiter(rec("Bounce", 550, "5.1.1", rid="a1", espace=WS_A))
        r.traiter(rec("Bounce", 550, "5.1.1", rid="b1", espace=WS_B))
        par = {e["id_kumo"]: e["workspace_id"] for e in p.evenements}
        self.assertEqual(par, {"a1": WS_A, "b1": WS_B})

    def test_numerotation_source_par_espace_sans_trou(self):
        r, p = relais()
        for i, ws in enumerate([WS_A, WS_B, WS_A, WS_B, WS_A]):
            r.traiter(rec("Delivery", 250, "2.0.0", rid="m%d" % i, espace=ws))
        for ws in (WS_A, WS_B):
            nums = [e["src_seq"] for e in p.evenements if e["workspace_id"] == ws]
            self.assertEqual(nums, list(range(1, len(nums) + 1)))

    def test_espace_absent_ou_mal_forme_rien_n_est_attribue(self):
        r, p = relais()
        sans = rec("Bounce", 550, "5.1.1", rid="s")
        del sans["meta"]["espace"]
        r.traiter(sans)
        r.traiter(rec("Bounce", 550, "5.1.1", rid="t", espace="ws1"))
        self.assertEqual(p.evenements, [])
        self.assertEqual(r.sans_espace, 2)

    def test_carte_des_espaces_k4(self):
        p = PuitsMemoire()
        x = Relais(charger(REGLES), p, source="kumo-test", carte_espaces={"ws1": WS_B})
        x.traiter(rec("Delivery", 250, "2.0.0", rid="c", espace="ws1"))
        self.assertEqual(p.evenements[0]["workspace_id"], WS_B)

    def test_k4_ecrit_la_meta_espace_et_le_hook_la_remonte(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location("fk", RACINE.parent / "injection" / "axion_injection" / "file_kumo.py")
        fk = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fk)
        m = fk.meta_depot("ws2", "inj-9")
        self.assertEqual(m, {"espace": "ws2", "id_injection": "inj-9"})
        lua = (RACINE.parent / "kumomta" / "loghook.lua").read_text()
        for cle in fk.META_LOGHOOK:
            self.assertIn("'%s'" % cle, lua)


class RedemarrageSansPerte(unittest.TestCase):
    def test_numero_en_attente_survit_au_redemarrage(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            p = PuitsMemoire()
            p.panne = True
            x = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat)
            with self.assertRaises(IOError):
                x.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
            p.panne = False
            y = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat)  # redemarrage
            y.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
            self.assertEqual([(e["id_kumo"], e["src_seq"]) for e in p.evenements], [("a", 1)])


class PasserelleRefuse(unittest.TestCase):
    """Un 400 de la passerelle est definitif : jamais relance sans fin (503 => Kumo reessaie)."""

    def monter(self, code, secret="s" * 32):
        import threading
        from http.server import BaseHTTPRequestHandler, HTTPServer
        entetes = []

        class F(BaseHTTPRequestHandler):
            def do_POST(self):
                self.rfile.read(int(self.headers["Content-Length"]))
                entetes.append(self.headers.get("X-Axion-Loghook-Secret"))
                self.send_response(code)
                self.send_header("Content-Length", "0")
                self.end_headers()

            def log_message(self, *a):
                pass

        f = HTTPServer(("127.0.0.1", 0), F)
        threading.Thread(target=f.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (f.shutdown(), f.server_close()))
        puits = PuitsHttp("http://127.0.0.1:%d/loghook.php" % f.server_port, secret=secret)
        return puits, entetes

    def test_400_n_est_pas_un_ioerror_retentable(self):
        from axion_loghook.serveur import EvenementRefuse
        puits, _ = self.monter(400)
        with self.assertRaises(EvenementRefuse):
            puits.envoyer({"type": "livre"})

    def test_400_repond_400_au_hook_et_oublie_le_numero(self):
        puits, _ = self.monter(400)
        x = Relais(charger(REGLES), puits, source="kumo-test")
        srv = creer(x)
        import threading
        import urllib.error
        import urllib.request
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (srv.shutdown(), srv.server_close()))
        try:
            urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % srv.server_port, json.dumps(rec("Bounce", 550, "5.1.1")).encode(), {}))
            code = 200
        except urllib.error.HTTPError as e:
            code = e.code
        self.assertEqual(code, 400)
        self.assertEqual(x._en_attente, {})

    def test_secret_partage_envoye_en_entete(self):
        puits, entetes = self.monter(200)
        puits.envoyer({"type": "livre"})
        self.assertEqual(entetes, ["s" * 32])

    def test_secret_absent_ou_trop_court_refuse_au_demarrage(self):
        with self.assertRaises(ValueError):
            PuitsHttp("http://127.0.0.1:1/x", secret="")
        with self.assertRaises(ValueError):
            PuitsHttp("http://127.0.0.1:1/x", secret="court")

    def test_secret_lu_dans_un_fichier_hors_depot(self):
        from axion_loghook.serveur import lire_secret
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "secret"
            f.write_text("t" * 40 + "\n")
            self.assertEqual(lire_secret(f), "t" * 40)
            with self.assertRaises(ValueError):
                lire_secret(pathlib.Path(d) / "absent")


class BancLuaEtMontage(unittest.TestCase):
    """B1 : tout fichier require par init.lua doit etre monte ET installe."""

    KUMO = RACINE.parent / "kumomta"
    COMPOSE = (RACINE.parent / "docker-compose.yml").read_text()
    INSTALLER = (RACINE.parent / "installer.sh").read_text()

    def requis(self):
        import re
        init = (self.KUMO / "init.lua").read_text()
        return sorted(set(re.findall(r"^(?:local\s+\w+\s*=\s*)?require\s+'([a-z_]+)'", init, re.M)) - {"kumo"})

    def test_b1_les_require_sont_detectes(self):
        self.assertIn("loghook", self.requis())
        self.assertIn("dkim", self.requis())

    def test_b1_chaque_require_est_monte_dans_compose(self):
        for nom in self.requis():
            self.assertIn("./kumomta/%s.lua:/opt/kumomta/etc/policy/%s.lua:ro" % (nom, nom), self.COMPOSE, nom)

    def test_b1_chaque_require_est_compare_et_installe(self):
        for nom in self.requis():
            self.assertIn('cmp -s "$SRC/kumomta/%s.lua" "$CIBLE/kumomta/%s.lua"' % (nom, nom), self.INSTALLER, nom)
            self.assertRegex(self.INSTALLER, r'install -m 0644 "\$SRC/kumomta/%s\.lua" "\$CIBLE/kumomta/%s\.lua"' % (nom, nom))

    def test_b1_le_fichier_existe(self):
        for nom in self.requis():
            self.assertTrue((self.KUMO / (nom + ".lua")).exists(), nom)


class HookLuaB3(unittest.TestCase):
    LUA = (RACINE.parent / "kumomta" / "loghook.lua").read_text()

    def test_garde_anti_boucle_et_booleens_explicites(self):
        self.assertIn("should_enqueue_log_record", self.LUA)
        self.assertIn("log_record.queue ~= 'axion_loghook'", self.LUA)
        self.assertIn("return true", self.LUA)
        self.assertIn("return false", self.LUA)

    def test_file_axion_loghook_en_custom_lua(self):
        self.assertIn("get_queue_config", self.LUA)
        self.assertIn("protocol = { custom_lua = { constructor = 'make.axion_loghook' } }", self.LUA)

    def test_pas_de_double_enregistrement_de_init(self):
        self.assertEqual(self.LUA.count("kumo.on('init'"), 0, "init.lua enregistre deja `init` : loghook.lua expose une fonction")
        self.assertIn("configure_log_hook", self.LUA)

    def test_meta_espace_remontee(self):
        self.assertIn("'espace'", self.LUA)

    def test_erreur_400_du_relais_n_est_pas_relancee(self):
        self.assertRegex(self.LUA, r"status_code\(\) == 400")

    def test_dette_banc_k10_nommee(self):
        self.assertIn("à prouver au banc K10", self.LUA)
        self.assertIn("à prouver au banc K10", (RACINE / "README.md").read_text())


class DettesNommees(unittest.TestCase):
    README = (RACINE / "README.md").read_text()

    def test_dettes(self):
        for mot in ("K7-D5", "K7-D6", "K7-D7", "K7-D8", "AppliquerRetour", "loghook_vus", "systemd", "ESCALADE"):
            self.assertIn(mot, self.README, mot)


if __name__ == "__main__":
    unittest.main()
