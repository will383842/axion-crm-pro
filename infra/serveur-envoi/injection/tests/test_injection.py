import asyncio
import base64
import os
import shutil
import ssl
import subprocess
import tempfile
import unittest

from axion_injection.config import Config, hacher
from axion_injection.file_kumo import FausseFile
from axion_injection.serveur import demarrer
from axion_injection.session import Session

IP_OK, IP_KO = "192.0.2.10", "203.0.113.9"
MDP = "mdp-de-test"  # valeur de test, pas un secret


def cfg(**kw):
    base = dict(
        ip_ecoute="192.0.2.1", ips_autorisees=["192.0.2.0/24"],
        comptes={"alice": {"hash": hacher(MDP, b"selselsel", 1000), "espace": "ws1"},
                 "bob": {"hash": hacher(MDP, b"selselsel", 1000), "espace": "ws2"}},
        registre={"ws1": ["a@envoi.example.test"], "ws2": ["b@autre.example.test"]},
        domaines_envoi={"envoi.example.test", "autre.example.test"},
        domaines_interdits={"principal.example.test"})
    base.update(kw)
    return Config(**base)


def plain(authz, authc, mdp=MDP):
    return base64.b64encode(f"{authz}\0{authc}\0{mdp}".encode()).decode()


class Base(unittest.TestCase):
    def setUp(self):
        self.file = FausseFile()
        self.s = Session(cfg(), self.file, IP_OK)
        self.s.accueil()

    def cmd(self, t):
        return self.s.ligne(t.encode() + b"\r\n")[0]

    def tls(self):
        self.cmd("STARTTLS"); self.s.tls_etabli()

    def auth(self, user="alice"):
        self.tls()
        self.assertTrue(self.cmd("AUTH PLAIN " + plain("", user))[0].startswith("235"))

    def envoyer(self, mf="a@envoi.example.test", corps="Subject: x\r\n\r\nsalut\r\n"):
        r = [self.cmd(f"MAIL FROM:<{mf}>")[0]]
        if r[0].startswith("250"):
            r.append(self.cmd("RCPT TO:<d@dest.example.test>")[0])
            r.append(self.cmd("DATA")[0])
            for l in corps.split("\r\n")[:-1]:
                self.s.ligne(l.encode() + b"\r\n")
            r.append(self.s.ligne(b".\r\n")[0][0])
        return r


class TestRefus(Base):
    def test_auth_sans_tls_refuse(self):
        self.assertTrue(self.cmd("AUTH PLAIN " + plain("", "alice"))[0].startswith("530"))
        self.assertIsNone(self.s.authc)

    def test_ehlo_sans_tls_n_annonce_pas_auth(self):
        self.assertNotIn("AUTH", " ".join(self.cmd("EHLO c")))

    def test_mail_sans_tls_refuse(self):
        self.assertTrue(self.cmd("MAIL FROM:<a@envoi.example.test>")[0].startswith("530"))

    def test_authz_different_de_authc_refuse(self):
        self.tls()
        self.assertTrue(self.cmd("AUTH PLAIN " + plain("bob", "alice"))[0].startswith("535"))
        self.assertIsNone(self.s.authc)

    def test_mauvais_mot_de_passe_puis_fermeture(self):
        self.tls()
        for _ in range(2):
            self.assertTrue(self.cmd("AUTH PLAIN " + plain("", "alice", "faux"))[0].startswith("535"))
        self.assertEqual(self.s.ligne(("AUTH PLAIN " + plain("", "alice", "faux") + "\r\n").encode())[1], "close")

    def test_mail_sans_auth_refuse(self):
        self.tls()
        self.assertTrue(self.cmd("MAIL FROM:<a@envoi.example.test>")[0].startswith("530"))

    def test_mail_from_hors_registre_refuse(self):
        self.auth()
        self.assertTrue(self.envoyer("autre@envoi.example.test")[0].startswith("550"))

    def test_registre_d_un_autre_espace_refuse(self):
        self.auth("alice")
        self.assertTrue(self.envoyer("b@autre.example.test")[0].startswith("550"))

    def test_domaine_envoi_inconnu_refuse(self):
        s = Session(cfg(registre={"ws1": ["a@inconnu.example.test"]}), self.file, IP_OK)
        s.accueil(); s.tls_etabli()
        s.ligne(("AUTH PLAIN " + plain("", "alice") + "\r\n").encode())
        self.assertTrue(s.ligne(b"MAIL FROM:<a@inconnu.example.test>\r\n")[0][0].startswith("550"))

    def test_domaine_principal_refuse_en_5xx_meme_dans_registre(self):
        for adr in ("x@principal.example.test", "x@PRINCIPAL.example.test.", "x@sous.principal.example.test"):
            s = Session(cfg(registre={"ws1": [adr]}, domaines_envoi={"principal.example.test", "sous.principal.example.test"}),
                        self.file, IP_OK)
            s.accueil(); s.tls_etabli()
            s.ligne(("AUTH PLAIN " + plain("", "alice") + "\r\n").encode())
            self.assertTrue(s.ligne(f"MAIL FROM:<{adr}>\r\n".encode())[0][0].startswith("550"), adr)

    def test_from_entete_domaine_principal_refuse(self):
        self.auth()
        r = self.envoyer(corps="From: x@principal.example.test\r\n\r\nm\r\n")
        self.assertTrue(r[-1].startswith("550"))
        self.assertEqual(self.file.depots, [])

    def test_ip_non_autorisee_coupee(self):
        s = Session(cfg(), self.file, IP_KO)
        self.assertEqual(s.accueil()[1], "close")

    def test_relais_ouvert_impossible(self):
        # sans AUTH, ou authentifié avec un expéditeur / domaine quelconque : rien en file
        self.tls()
        self.envoyer("x@dest.example.test")
        self.auth_si_besoin = None
        s2 = Session(cfg(), self.file, IP_OK); s2.accueil(); s2.tls_etabli()
        s2.ligne(("AUTH PLAIN " + plain("", "alice") + "\r\n").encode())
        for mf in ("x@dest.example.test", "a@envoi.example.test.evil.example.test", "a@sous.envoi.example.test"):
            self.assertTrue(s2.ligne(f"MAIL FROM:<{mf}>\r\n".encode())[0][0].startswith("550"), mf)
        self.assertEqual(self.file.depots, [])

    def test_fin_de_ligne_sans_cr_refusee(self):
        self.auth()
        self.cmd("MAIL FROM:<a@envoi.example.test>"); self.cmd("RCPT TO:<d@dest.example.test>"); self.cmd("DATA")
        rep, act = self.s.ligne(b"x\n.\n\r\n")
        self.assertEqual(act, "close"); self.assertEqual(self.file.depots, [])

    def test_un_seul_destinataire(self):
        self.auth(); self.cmd("MAIL FROM:<a@envoi.example.test>"); self.cmd("RCPT TO:<d@dest.example.test>")
        self.assertTrue(self.cmd("RCPT TO:<e@dest.example.test>")[0].startswith("452"))

    def test_panne_file_donne_4xx(self):
        s = Session(cfg(), FausseFile(panne=True), IP_OK); s.accueil(); s.tls_etabli()
        s.ligne(("AUTH PLAIN " + plain("", "alice") + "\r\n").encode())
        for c in ("MAIL FROM:<a@envoi.example.test>", "RCPT TO:<d@dest.example.test>", "DATA"):
            s.ligne(c.encode() + b"\r\n")
        self.assertTrue(s.ligne(b".\r\n")[0][0].startswith("451"))

    def test_joker_refuse_a_la_config(self):
        with self.assertRaises(ValueError):
            cfg(ip_ecoute="0.0.0.0")


class TestAcceptation(Base):
    def test_x_tenant_retire_et_espace_impose(self):
        self.auth("alice")
        corps = "X-Tenant: ws2\r\n  suite\r\nx-tenant : ws2\r\nSubject: s\r\n\r\nX-Tenant: dans-le-corps\r\n"
        self.assertTrue(self.envoyer(corps=corps)[-1].startswith("250"))
        d = self.file.depots[0]
        self.assertEqual(d["espace"], "ws1")
        tete, _, corps_sortie = d["message"].partition(b"\r\n\r\n")
        self.assertNotIn(b"x-tenant", tete.lower())
        self.assertNotIn(b"suite", tete)
        self.assertIn(b"Subject: s", tete)
        self.assertIn(b"X-Tenant: dans-le-corps", corps_sortie)  # le corps n'est pas touché

    def test_nominal_deux_espaces(self):
        self.auth("bob")
        self.assertTrue(self.envoyer("b@autre.example.test")[-1].startswith("250"))
        self.assertEqual(self.file.depots[0]["espace"], "ws2")


@unittest.skipUnless(shutil.which("openssl"), "openssl absent")
class TestSocket(unittest.TestCase):
    def test_starttls_bout_en_bout(self):
        async def run():
            d = tempfile.mkdtemp()
            c, k = os.path.join(d, "c.pem"), os.path.join(d, "k.pem")
            subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", k, "-out", c,
                            "-subj", "/CN=example.test", "-days", "1"], check=True, capture_output=True)
            file = FausseFile()
            srv = await demarrer(cfg(ip_ecoute="127.0.0.1", port=0, cert=c, cle=k, ips_autorisees=["127.0.0.0/8"]), file)
            port = srv.sockets[0].getsockname()[1]
            r, w = await asyncio.open_connection("127.0.0.1", port)
            lire = lambda: r.readline()
            self.assertTrue((await lire()).startswith(b"220"))
            w.write(b"AUTH PLAIN " + plain("", "alice").encode() + b"\r\n"); await w.drain()
            self.assertTrue((await lire()).startswith(b"530"))
            w.write(b"STARTTLS\r\n"); await w.drain()
            self.assertTrue((await lire()).startswith(b"220"))
            ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
            await w.start_tls(ctx)
            w.write(b"AUTH PLAIN " + plain("", "alice").encode() + b"\r\n"); await w.drain()
            self.assertTrue((await lire()).startswith(b"235"))
            w.close(); srv.close(); await srv.wait_closed(); shutil.rmtree(d)
        asyncio.run(run())


if __name__ == "__main__":
    unittest.main()
