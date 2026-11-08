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
SECRET_RELAIS = "r" * 40  # secret Lua -> relais (de test : jamais un vrai secret)
ENTETE_RELAIS = "X-Axion-Relais-Secret"


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
        self.srv = creer(self.relais, SECRET_RELAIS)
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
            return urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % self.srv.server_port, corps, {ENTETE_RELAIS: SECRET_RELAIS})).status
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
        self.assertEqual(list(r.alertes), ["alerte_config"])


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
        srv = creer(x, SECRET_RELAIS)
        import threading
        import urllib.error
        import urllib.request
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (srv.shutdown(), srv.server_close()))
        try:
            urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % srv.server_port, json.dumps(rec("Bounce", 550, "5.1.1")).encode(), {ENTETE_RELAIS: SECRET_RELAIS}))
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
            f.chmod(0o600)
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


class SecretLuaRelais(unittest.TestCase):
    """B1 : un processus local ne peut pas forger un rebond. Secret Lua -> relais, controle AVANT le corps."""

    def setUp(self):
        import threading
        self.puits = PuitsMemoire()
        self.relais = Relais(charger(REGLES), self.puits, source="kumo-test")
        self.srv = creer(self.relais, SECRET_RELAIS)
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (self.srv.shutdown(), self.srv.server_close()))

    def poster(self, corps, entetes=None, longueur=None):
        import http.client
        c = http.client.HTTPConnection("127.0.0.1", self.srv.server_port, timeout=5)
        c.putrequest("POST", "/")
        for k, v in (entetes or {}).items():
            c.putheader(k, v)
        c.putheader("Content-Length", str(len(corps) if longueur is None else longueur))
        c.endheaders()
        c.send(corps)
        return c.getresponse().status

    def corps(self):
        return json.dumps(rec("Bounce", 550, "5.1.1", rid="forge")).encode()

    def test_b1_sans_entete_403_et_rien_n_est_traite(self):
        self.assertEqual(self.poster(self.corps()), 403)
        self.assertEqual(self.puits.evenements, [])

    def test_b1_mauvais_secret_403_et_rien_n_est_traite(self):
        self.assertEqual(self.poster(self.corps(), {ENTETE_RELAIS: "x" * 40}), 403)
        self.assertEqual(self.poster(self.corps(), {ENTETE_RELAIS: SECRET_RELAIS[:-1]}), 403)
        self.assertEqual(self.puits.evenements, [])

    def test_b1_bon_secret_200(self):
        self.assertEqual(self.poster(self.corps(), {ENTETE_RELAIS: SECRET_RELAIS}), 200)
        self.assertEqual(len(self.puits.evenements), 1)

    def test_b1_le_secret_est_controle_avant_de_lire_le_corps(self):
        # Content-Length annonce 100 octets mais le client n'en envoie aucun : sans secret, 403 immediat (pas d'attente du corps).
        self.assertEqual(self.poster(b"", {}, longueur=100), 403)

    def test_b1_secret_absent_ou_court_le_relais_refuse_de_demarrer(self):
        for mauvais in (None, "", "court", "a" * 31, b"x" * 40):
            with self.assertRaises(ValueError):
                creer(self.relais, mauvais)

    def test_b1_fichier_secret_droits_0600_controles(self):
        from axion_loghook.serveur import lire_secret
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "secret"
            f.write_text("t" * 40)
            for mode in (0o644, 0o640, 0o604, 0o660):
                f.chmod(mode)
                with self.assertRaises(ValueError, msg=oct(mode)):
                    lire_secret(f)
            f.chmod(0o600)
            self.assertEqual(lire_secret(f), "t" * 40)

    def test_mineur_corps_vide_ou_trop_gros_400(self):
        h = {ENTETE_RELAIS: SECRET_RELAIS}
        self.assertEqual(self.poster(b"", h), 400)
        self.assertEqual(self.poster(b"", h, longueur=(1 << 20) + 1), 400)  # jamais 413 : Kumo le rejouerait sans fin (451)

    def test_mineur_le_gestionnaire_a_un_delai_maximal(self):
        self.assertIsNotNone(self.srv.RequestHandlerClass.timeout)
        self.assertLessEqual(self.srv.RequestHandlerClass.timeout, 30)


class EspaceEtIdentifiant(unittest.TestCase):
    """E4 : un enregistrement sans espace valide n'est jamais acquitte puis jete ; un enregistrement sans id est refuse."""

    def test_e4_sans_espace_garde_de_cote_et_persiste(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            p = PuitsMemoire()
            x = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat)
            r = rec("Bounce", 550, "5.1.1", rid="orphelin", espace="ws1")
            self.assertIsNone(x.traiter(r))
            self.assertEqual(p.evenements, [])
            self.assertEqual([e["id"] for e in x.ecartes], ["orphelin"])
            y = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat)  # redemarrage : rien n'est perdu
            self.assertEqual([e["id"] for e in y.ecartes], ["orphelin"])

    def test_e4_rejoue_une_fois_l_espace_connu(self):
        p = PuitsMemoire()
        carte = {}
        x = Relais(charger(REGLES), p, source="kumo-test", carte_espaces=carte)
        x.traiter(rec("Bounce", 550, "5.1.1", rid="orphelin", espace="ws1"))
        carte["ws1"] = WS_A
        self.assertEqual(x.rejouer_ecartes(), 1)
        self.assertEqual([(e["id_kumo"], e["workspace_id"]) for e in p.evenements], [("orphelin", WS_A)])
        self.assertEqual(x.ecartes, [])

    def test_e4_liste_ecartee_bornee_puis_503_jamais_de_perte_silencieuse(self):
        x = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test")
        for i in range(Relais.ECARTES_MAX):
            x.traiter(rec("Bounce", 550, "5.1.1", rid="o%d" % i, espace="ws1"))
        with self.assertRaises(IOError):  # Kumo reessaiera : on ne jette pas
            x.traiter(rec("Bounce", 550, "5.1.1", rid="trop", espace="ws1"))
        self.assertEqual(len(x.ecartes), Relais.ECARTES_MAX)

    def test_e4_sans_id_refuse_en_400(self):
        x, p = relais()
        for r in (dict(rec("Bounce", 550, "5.1.1"), id=None), {k: v for k, v in rec("Bounce", 550, "5.1.1").items() if k != "id"},
                  dict(rec("Bounce", 550, "5.1.1"), id=""), dict(rec("Bounce", 550, "5.1.1"), id=12)):
            with self.assertRaises(ValueError):
                x.traiter(r)
        self.assertEqual(p.evenements, [])

    def test_e4_sans_id_repond_400_au_hook(self):
        import threading
        x, p = relais()
        srv = creer(x, SECRET_RELAIS)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (srv.shutdown(), srv.server_close()))
        import urllib.error
        import urllib.request
        corps = json.dumps({k: v for k, v in rec("Bounce", 550, "5.1.1").items() if k != "id"}).encode()
        with self.assertRaises(urllib.error.HTTPError) as e:
            urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % srv.server_port, corps, {ENTETE_RELAIS: SECRET_RELAIS}))
        self.assertEqual(e.exception.code, 400)


class EtatEmpreinteBornes(unittest.TestCase):
    def test_mineur_etat_corrompu_le_relais_refuse_de_demarrer(self):
        from axion_loghook.relais import EtatCorrompu
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            etat.write_text("{pas du json")
            with self.assertRaises(EtatCorrompu):
                Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            self.assertEqual(etat.read_text(), "{pas du json")  # rien n'est remis a zero en silence
            etat.write_text("[1, 2]")
            with self.assertRaises(EtatCorrompu):
                Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)

    def test_mineur_etat_absent_demarre_a_zero(self):
        with tempfile.TemporaryDirectory() as d:
            x = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=pathlib.Path(d) / "nouveau.json")
            self.assertEqual(x._seq, {})

    def test_mineur_empreinte_hmac_local_stable_et_pas_un_sha256_nu(self):
        import hashlib
        adresse = "x@dest.example.test"
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            e1 = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            ev1 = e1.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
            e2 = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            ev2 = e2.traiter(rec("Bounce", 550, "5.1.1", rid="b"))
        self.assertNotEqual(ev1["destinataire_empreinte"], hashlib.sha256(adresse.encode()).hexdigest())
        self.assertEqual(ev1["destinataire_empreinte"], ev2["destinataire_empreinte"])  # meme cle apres redemarrage
        autre = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test").traiter(rec("Bounce", 550, "5.1.1", rid="c"))
        self.assertNotEqual(ev1["destinataire_empreinte"], autre["destinataire_empreinte"])  # cle propre a chaque installation

    def test_mineur_listes_en_memoire_bornees(self):
        x, _ = relais()
        for i in range(Relais.MEMOIRE_MAX + 50):
            x.journal.append(("k", str(i)))
            x.alertes.append("a")
        self.assertEqual(len(x.journal), Relais.MEMOIRE_MAX)
        self.assertEqual(len(x.alertes), Relais.MEMOIRE_MAX)


class RejeuEtEtatDurcis(unittest.TestCase):
    """Revue PR #472 : rejeu sans perte, etat en 0600 sans adresse en clair, cle hexadecimale, secret sans blanc de tete."""

    def _orphelin(self, carte, **kw):
        p = PuitsMemoire()
        x = Relais(charger(REGLES), p, source="kumo-test", carte_espaces=carte, **kw)
        x.traiter(rec("Bounce", 550, "5.1.1", rid="dur1", espace="ws1"))
        carte["ws1"] = WS_A
        return x, p

    def test_bloquant_panne_pendant_le_rejeu_ne_perd_pas_le_rebond_dur(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            x, p = self._orphelin({}, fichier_etat=etat)
            p.panne = True
            self.assertEqual(x.rejouer_ecartes(), 0)  # passerelle en panne : rien n'est rejoue...
            self.assertEqual([e["id"] for e in x.ecartes], ["dur1"])  # ...et rien n'est perdu
            y = Relais(charger(REGLES), p, source="kumo-test", fichier_etat=etat, carte_espaces={"ws1": WS_A})
            self.assertEqual([e["id"] for e in y.ecartes], ["dur1"])  # meme apres redemarrage
            p.panne = False
            self.assertEqual(y.rejouer_ecartes(), 1)
            self.assertEqual([(e["id_kumo"], e["type"], e["workspace_id"]) for e in p.evenements], [("dur1", "rebond_dur", WS_A)])
            self.assertEqual(y.ecartes, [])
            self.assertEqual(json.loads(etat.read_text())["ecartes"], [])

    def test_bloquant_refus_definitif_pendant_le_rejeu_retire_l_enregistrement(self):
        class Refuse:
            def envoyer(self, ev):
                raise EvenementRefuse("400")
        from axion_loghook.relais import EvenementRefuse  # noqa: F811
        carte = {}
        x = Relais(charger(REGLES), Refuse(), source="kumo-test", carte_espaces=carte)
        x.traiter(rec("Bounce", 550, "5.1.1", rid="dur1", espace="ws1"))
        carte["ws1"] = WS_A
        x.rejouer_ecartes()
        self.assertEqual(x.ecartes, [])  # refus definitif : pas de rejeu infini

    def test_important_fichier_d_etat_en_0600_meme_sous_umask_large(self):
        import os
        ancien = os.umask(0o000)
        self.addCleanup(os.umask, ancien)
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            x = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            x.traiter(rec("Bounce", 550, "5.1.1", rid="a"))
            self.assertEqual(etat.stat().st_mode & 0o777, 0o600)
            self.assertFalse(pathlib.Path(str(etat) + ".tmp").exists())
            etat.chmod(0o644)  # etat herite d'une ancienne version : resserre au demarrage
            Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            self.assertEqual(etat.stat().st_mode & 0o777, 0o600)

    def test_important_ecartes_sans_adresse_ni_texte_de_reponse(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            x = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            x.traiter(rec("Bounce", 550, "5.1.1", rid="o", texte="user unknown secret-texte", espace="ws1"))
            brut = etat.read_text()
            for interdit in ("x@dest.example.test", "dest.example.test", "secret-texte", "recipient", "sender"):
                self.assertNotIn(interdit, brut)

    def test_important_ancien_etat_avec_adresse_en_clair_est_reduit_au_demarrage(self):
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            cle = "ab" * 32
            etat.write_text(json.dumps({"seq": {}, "attente": {}, "cle": cle,
                                        "ecartes": [rec("Bounce", 550, "5.1.1", rid="vieux", espace="ws1")]}))
            x = Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)
            self.assertNotIn("x@dest.example.test", etat.read_text())
            self.assertEqual([e["id"] for e in x.ecartes], ["vieux"])
            x.carte["ws1"] = WS_A
            self.assertEqual(x.rejouer_ecartes(), 1)

    def test_mineur_cle_d_etat_non_hexadecimale_refusee(self):
        from axion_loghook.relais import EtatCorrompu
        with tempfile.TemporaryDirectory() as d:
            etat = pathlib.Path(d) / "etat.json"
            etat.write_text(json.dumps({"seq": {}, "attente": {}, "ecartes": [], "cle": "z" * 64}))
            with self.assertRaises(EtatCorrompu):
                Relais(charger(REGLES), PuitsMemoire(), source="kumo-test", fichier_etat=etat)

    def test_mineur_secret_commencant_par_un_blanc_refuse_cote_python(self):
        import os
        from axion_loghook.serveur import lire_secret
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "s"
            f.write_text(" " + "s" * 40)
            os.chmod(f, 0o600)
            with self.assertRaises(ValueError):
                lire_secret(f)
            f.write_text("s" * 40 + "\n")  # blancs de fin : toleres des deux cotes
            self.assertEqual(lire_secret(f), "s" * 40)
        with self.assertRaises(ValueError):
            creer(relais()[0], " " + "s" * 40)

    def test_mineur_secret_commencant_par_un_blanc_refuse_cote_lua(self):
        lua = (RACINE.parent / "kumomta" / "loghook.lua").read_text()
        self.assertRegex(lua, r"match\('\^%s'\)")

    def test_mineur_serveur_multifil_et_connexions_bornees(self):
        import socket
        import threading
        import urllib.request
        from http.server import ThreadingHTTPServer
        x, _ = relais()
        srv = creer(x, SECRET_RELAIS, max_connexions=2)
        self.assertIsInstance(srv, ThreadingHTTPServer)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(lambda: (srv.shutdown(), srv.server_close()))
        muets = [socket.create_connection(("127.0.0.1", srv.server_port)) for _ in range(2)]
        self.addCleanup(lambda: [m.close() for m in muets])
        import time
        time.sleep(0.3)
        trop = socket.create_connection(("127.0.0.1", srv.server_port))
        self.addCleanup(trop.close)
        trop.settimeout(3)
        self.assertTrue(trop.recv(64).startswith(b"HTTP/1.1 503"))  # au-dela de la borne : 503, Kumo reessaie
        muets.pop().close()
        time.sleep(0.3)
        corps = json.dumps(rec("Bounce", 550, "5.1.1")).encode()
        r = urllib.request.urlopen(urllib.request.Request("http://127.0.0.1:%d/" % srv.server_port, corps, {ENTETE_RELAIS: SECRET_RELAIS}), timeout=5)
        self.assertEqual(r.status, 200)  # un client muet ne bloque pas les autres


class HookLuaDurci(unittest.TestCase):
    LUA = (RACINE.parent / "kumomta" / "loghook.lua").read_text()
    INSTALLER = (RACINE.parent / "installer.sh").read_text()
    COMPOSE = (RACINE.parent / "docker-compose.yml").read_text()

    def test_b1_le_hook_lit_le_secret_dans_un_fichier_et_l_envoie_en_entete(self):
        self.assertIn("X-Axion-Relais-Secret", self.LUA)
        self.assertIn(":header('X-Axion-Relais-Secret'", self.LUA)
        self.assertIn("/etc/axion-envoi/loghook/relais.secret", self.LUA)
        self.assertIn("io.open", self.LUA)

    def test_b1_secret_absent_ou_court_le_hook_n_envoie_rien_et_reessaie(self):
        self.assertRegex(self.LUA, r"#secret < 32")

    def test_i2_get_queue_config_rend_une_config_pour_les_autres_files(self):
        self.assertIn("return kumo.make_queue_config {}", self.LUA)

    def test_i2_file_du_hook_a_une_expiration(self):
        self.assertRegex(self.LUA, r"max_age\s*=")
        self.assertRegex(self.LUA, r"max_retry_interval\s*=")

    def test_i2_interrupteur_desactive_par_defaut(self):
        self.assertIn("AXION_LOGHOOK_ACTIF", self.LUA)
        self.assertRegex(self.LUA, r"os\.getenv\('AXION_LOGHOOK_ACTIF'\) == '1'")
        self.assertIn("AXION_LOGHOOK_ACTIF: ${AXION_LOGHOOK_ACTIF:-0}", self.COMPOSE)

    def test_i2_installer_ne_l_active_que_sur_drapeau_explicite(self):
        self.assertIn("--avec-loghook", self.INSTALLER)
        self.assertRegex(self.INSTALLER, r"AXION_LOGHOOK_ACTIF=\$\{?LOGHOOK|AXION_LOGHOOK_ACTIF=\$LOGHOOK")
        self.assertRegex(self.INSTALLER, r"LOGHOOK=0")

    def test_i2_le_secret_du_hook_est_monte_en_lecture_seule(self):
        self.assertIn("/etc/axion-envoi/loghook:/etc/axion-envoi/loghook:ro", self.COMPOSE)

    def test_i2_installer_n_active_jamais_sans_drapeau_a_l_execution(self):
        import os
        import subprocess
        with tempfile.TemporaryDirectory() as d:
            sh = pathlib.Path(d) / "etat.sh"
            sh.write_text("")
            ligne = [l for l in self.INSTALLER.splitlines() if "LOGHOOK=0" in l]
            self.assertTrue(ligne)
            r = subprocess.run(["bash", "-c", "set -u; %s\nfor a in \"$@\"; do :; done; echo $LOGHOOK" % ligne[0]],
                               capture_output=True, text=True, env={**os.environ})
            self.assertEqual(r.stdout.strip(), "0")

    def _drapeaux(self, *args):
        """Execute VRAIMENT les deux lignes de l'installateur qui lisent les drapeaux ; rend « REEL LOGHOOK »."""
        import subprocess
        lignes = [l.split("#")[0] for l in self.INSTALLER.splitlines() if l.startswith(("REEL=0", "LOGHOOK=0"))]
        self.assertEqual(len(lignes), 2)
        r = subprocess.run(["bash", "-c", "set -u\n%s\necho $REEL $LOGHOOK" % "\n".join(lignes), "x", *args], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        return r.stdout.strip()

    def test_i2_fort_avec_loghook_donne_1_et_oui_seul_donne_0(self):
        self.assertEqual(self._drapeaux(), "0 0")
        self.assertEqual(self._drapeaux("--avec-loghook"), "0 1")  # a blanc, hook demande
        self.assertEqual(self._drapeaux("--oui"), "1 0")  # reel SANS --avec-loghook : hook inactif

    def test_mineur_oui_est_reconnu_dans_les_deux_ordres(self):
        self.assertEqual(self._drapeaux("--avec-loghook", "--oui"), "1 1")
        self.assertEqual(self._drapeaux("--oui", "--avec-loghook"), "1 1")

    def test_important_dossier_du_secret_lisible_par_kumo_groupe_10025(self):
        import re
        self.assertRegex(self.INSTALLER, r"install -d -m 0750 -o root -g 10025 /etc/axion-envoi/loghook")
        self.assertNotRegex(self.INSTALLER, r"install -d -m 0750 /etc/axion-envoi/loghook")
        self.assertIn("group_add: [\"10025\"]", self.COMPOSE)  # le conteneur Kumo porte bien ce groupe

    def test_important_expiration_de_la_file_documentee_comme_perte(self):
        self.assertIn("K7-D11", self.LUA)
        self.assertIn("scheduled_count", self.LUA)


class RelaisDansLaDoc(unittest.TestCase):
    README = (RACINE / "README.md").read_text()
    RACINE_DEPOT = RACINE.parent.parent.parent

    def test_dettes_k7_d6_d9_et_secret_nommes(self):
        for mot in ("K7-D6", "K7-D9", "X-Axion-Relais-Secret", "AXION_LOGHOOK_ACTIF", "--avec-loghook", "ESCALADE"):
            self.assertIn(mot, self.README, mot)

    def test_e3_la_phrase_d_observation_n_affirme_pas_plus_que_les_tests(self):
        self.assertNotIn("n'en compte AUCUN", self.README)
        self.assertIn("K7-D9", self.README)

    def test_escalade_observation_nommee_sans_changer_le_comportement(self):
        self.assertIn("ESCALADE", self.README)
        compteur = (self.RACINE_DEPOT / "backend" / "app" / "Envoi" / "Chauffe" / "CompteurRetours.php").read_text()
        self.assertIn("$evenement->observation", compteur)  # comportement inchange : decision de l'architecte et du responsable


class DettesNommees(unittest.TestCase):
    README = (RACINE / "README.md").read_text()

    def test_dettes(self):
        for mot in ("K7-D5", "K7-D6", "K7-D7", "K7-D8", "K7-D9", "AppliquerRetour", "loghook_vus", "systemd", "ESCALADE"):
            self.assertIn(mot, self.README, mot)


if __name__ == "__main__":
    unittest.main()
