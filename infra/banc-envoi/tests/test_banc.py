"""Tests du banc d'envoi K10 (stdlib + PyYAML). Lancer : python3 -m unittest discover -s infra/banc-envoi/tests"""
import pathlib, re, sys, unittest
import yaml

BANC = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BANC / "services"))
COMPOSE = yaml.safe_load((BANC / "docker-compose.yml").read_text())
SERVICES = COMPOSE["services"]


class TestIsolation(unittest.TestCase):
    def test_reseau_interne(self):
        nets = COMPOSE["networks"]
        self.assertTrue(nets, "au moins un réseau")
        for nom, conf in nets.items():
            self.assertIs(conf.get("internal"), True, f"réseau {nom} doit être internal: true")

    def test_aucun_port_publie_et_aucun_reseau_externe(self):
        for nom, s in SERVICES.items():
            self.assertNotIn("ports", s, f"{nom} ne publie aucun port")
            self.assertNotIn("network_mode", s, f"{nom} : pas de network_mode")
            for r in (s.get("networks") or []):
                self.assertIn(r, COMPOSE["networks"])

    def test_services_attendus(self):
        for n in ("dns-banc", "puits-accepte", "puits-4xx", "puits-5xx", "puits-lent",
                  "imap-banc", "compteur", "sonde", "temoin-hors-banc", "sonde-temoin"):
            self.assertIn(n, SERVICES)


class TestImagesFigees(unittest.TestCase):
    def test_image_par_digest(self):
        for nom, s in SERVICES.items():
            self.assertRegex(s["image"], r"@sha256:[0-9a-f]{64}$", f"{nom} : image non figée par empreinte")

    def test_dockerfile_par_digest(self):
        for f in BANC.glob("**/Dockerfile*"):
            for l in f.read_text().splitlines():
                if l.startswith("FROM "):
                    self.assertRegex(l, r"@sha256:[0-9a-f]{64}", f"{f.name}: {l}")


class TestSonde(unittest.TestCase):
    def test_zero_connexion_sortante(self):
        import sonde
        tentatives = []
        def refuse(h, p, t):
            tentatives.append((h, p)); return False
        r = sonde.verifier(refuse)
        self.assertEqual(r["sortantes"], 0)
        self.assertEqual(len(tentatives), len(sonde.CIBLES))
        self.assertEqual(sonde.code_sortie(r), 0)

    def test_connexion_sortante_detectee(self):
        import sonde
        r = sonde.verifier(lambda h, p, t: True)
        self.assertEqual(r["sortantes"], len(sonde.CIBLES))
        self.assertNotEqual(sonde.code_sortie(r), 0)

    def test_cibles_sont_reservees(self):
        import sonde
        for h, _ in sonde.CIBLES:
            if (h, _) == sonde.TEMOIN:
                continue
            self.assertRegex(h, r"^(192\.0\.2\.|198\.51\.100\.|203\.0\.113\.)|\.invalid$|\.test$")


class TestTemoinEtDns(unittest.TestCase):
    def test_temoin_hors_du_reseau_banc(self):
        t = SERVICES["temoin-hors-banc"]["networks"]
        self.assertIn("hors-banc", t)
        self.assertNotIn("banc", t)
        self.assertIn(sonde_temoin_ip(), str(t))
        self.assertNotIn("hors-banc", SERVICES["sonde"].get("networks", ["banc"]))

    def test_sonde_banc_vise_le_temoin_et_sonde_temoin_hors_banc(self):
        import sonde
        self.assertIn(sonde.TEMOIN, sonde.CIBLES)
        self.assertEqual(sonde.verifier_temoin(lambda h, p, t: True), {"temoin_joignable": True})
        self.assertEqual(sonde.verifier_temoin(lambda h, p, t: False), {"temoin_joignable": False})
        self.assertEqual(SERVICES["sonde-temoin"]["networks"], ["hors-banc"])

    def test_puits_utilisent_dns_banc(self):
        for n in ("puits-accepte", "puits-4xx", "puits-5xx", "puits-lent"):
            self.assertEqual(SERVICES[n]["dns"], ["10.99.0.2"], n)
        self.assertNotIn("dns", SERVICES["sonde"])


def sonde_temoin_ip():
    import sonde
    return sonde.TEMOIN[0]


class TestPuits(unittest.TestCase):
    def test_reponses_par_mode(self):
        import puits_smtp
        self.assertTrue(puits_smtp.reponse_data("accepte").startswith("250"))
        self.assertTrue(puits_smtp.reponse_data("4xx").startswith("4"))
        self.assertTrue(puits_smtp.reponse_data("5xx").startswith("5"))
        self.assertTrue(puits_smtp.reponse_data("lent").startswith("250"))
        self.assertGreaterEqual(puits_smtp.delai("lent"), 5)
        self.assertEqual(puits_smtp.delai("accepte"), 0)


if __name__ == "__main__":
    unittest.main()
