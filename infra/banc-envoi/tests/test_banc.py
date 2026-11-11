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


class TestSixTemoins(unittest.TestCase):
    """Fiche L8-F13 : 6 boîtes témoins (décision A10), une par fournisseur, domaines .test."""
    FOURNISSEURS = {"gmail", "google_workspace", "outlook_com", "microsoft_365", "yahoo", "orange"}

    def test_exactement_6_temoins_distincts(self):
        import temoins
        self.assertEqual(len(temoins.TEMOINS), 6)
        self.assertEqual({t["fournisseur"] for t in temoins.TEMOINS.values()}, self.FOURNISSEURS)
        self.assertEqual(len({t["adresse"] for t in temoins.TEMOINS.values()}), 6)

    def test_adresses_en_test_uniquement(self):
        import temoins
        for t in temoins.TEMOINS.values():
            self.assertRegex(t["adresse"], r"^[a-z0-9._-]+@[a-z0-9.-]+\.test$")

    def test_adresse_hors_des_6_refusee(self):
        import temoins
        banc = temoins.Banc()
        for a in ("inconnu@gmail.test", "x@gmail.com", ""):
            with self.assertRaises(temoins.AdresseRefusee):
                banc.livrer(a, "msg")

    def test_scenario_s18_a_6(self):
        import temoins
        banc = temoins.Banc()
        res = {f: banc.livrer(t["adresse"], "s18") for f, t in temoins.TEMOINS.items()}
        self.assertEqual(set(res.values()), {"inbox", "junk", "absent"})
        self.assertEqual(sum(len(b["inbox"]) for b in banc.boites.values()), list(res.values()).count("inbox"))
        self.assertEqual(sum(len(b["junk"]) for b in banc.boites.values()), list(res.values()).count("junk"))

    def test_comportement_simulable_et_invalide_refuse(self):
        import temoins
        banc = temoins.Banc()
        a = temoins.TEMOINS["yahoo"]["adresse"]
        banc.regler(a, "junk")
        self.assertEqual(banc.livrer(a, "m"), "junk")
        with self.assertRaises(ValueError):
            banc.regler(a, "n_importe_quoi")

    def test_mots_de_passe_generes_et_distincts(self):
        import temoins
        mdp = {b.mot_de_passe for b in temoins.Banc().boites.values()}
        self.assertEqual(len(mdp), 6)
        self.assertNotIn("", mdp)

    def test_imap_expose_les_6_boites(self):
        import imap_banc, temoins
        self.assertEqual(set(imap_banc.BANC.boites), {t["adresse"] for t in temoins.TEMOINS.values()})

    def test_compose_imap_interne_sans_port(self):
        s = SERVICES["imap-banc"]
        self.assertNotIn("ports", s)
        self.assertIn("networks", s, "sans networks, Compose utiliserait le réseau default (non interne)")
        self.assertEqual(list(s["networks"]), ["banc"])
        self.assertIs(COMPOSE["networks"]["banc"].get("internal"), True)


if __name__ == "__main__":
    unittest.main()
