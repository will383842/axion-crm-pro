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

    def test_k7_le_depot_porte_la_meta_espace_du_compte(self):
        # Le log hook (K7) relit cette meta : un rebond de ws2 ne doit jamais etre servi a ws1.
        self.auth("bob")
        self.envoyer("b@autre.example.test")
        self.assertEqual(self.file.depots[0]["meta"]["espace"], "ws2")
        self.assertTrue(self.file.depots[0]["meta"]["id_injection"])


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


class TestEntetesFrom(Base):
    """Correctifs sécurité (1)-(3) : analyse réelle des adresses, Return-Path, registre."""

    def deposer(self, tete):
        self.auth()
        return self.envoyer(corps=tete + "\r\n\r\nm\r\n")[-1]

    def test_deux_en_tetes_from_refuses(self):
        r = self.deposer("From: a@envoi.example.test\r\nFrom: x@principal.example.test")
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_message_deja_signe_dkim_refuse(self):
        # ADR-K0 D3.3 : second verrou, le serveur signe seul ; un message portant deja une
        # DKIM-Signature (mauvais reglage du CRM) est refuse en 5xx, rien n'est mis en file.
        for ent in ("DKIM-Signature: v=1; a=rsa-sha256; d=envoi.example.test; s=x; b=AAAA",
                    "dkim-signature : v=1; d=autre.example.test; b=AAAA",
                    "DKIM-SIGNATURE:\r\n v=1; d=envoi.example.test; b=AAAA"):
            self.file.depots.clear()
            r = self.deposer("From: a@envoi.example.test\r\n" + ent)
            self.assertTrue(r.startswith("550"), ent)
            self.assertIn("DKIM", r)
            self.assertEqual(self.file.depots, [], ent)

    def test_message_non_signe_accepte(self):
        r = self.deposer("From: a@envoi.example.test\r\nX-Dkim-Signature-Note: ok")
        self.assertTrue(r.startswith("250"), r)

    def test_from_replie_refuse(self):
        r = self.deposer('From: "Nom"\r\n <x@principal.example.test>')
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_nom_affiche_avec_arobase_refuse(self):
        r = self.deposer('From: "a@envoi.example.test" <x@principal.example.test>')
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_from_avec_espace_avant_deux_points_refuse(self):
        r = self.deposer("From : x@principal.example.test")
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_un_seul_domaine_interdit_parmi_plusieurs_refuse(self):
        r = self.deposer("From: a@envoi.example.test, x@principal.example.test")
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_sender_et_reply_to_interdits_refuses(self):
        for h in ("Sender: x@principal.example.test", "Reply-To: x@principal.example.test",
                  'Reply-To: "a@envoi.example.test"\r\n <x@principal.example.test>'):
            self.setUp()
            r = self.deposer("From: a@envoi.example.test\r\n" + h)
            self.assertTrue(r.startswith("550"), h)
            self.assertEqual(self.file.depots, [])

    def test_from_hors_registre_de_l_espace_refuse(self):
        # alice (ws1) ne peut pas signer avec le domaine du registre de ws2
        r = self.deposer("From: b@autre.example.test")
        self.assertTrue(r.startswith("550")); self.assertEqual(self.file.depots, [])

    def test_from_legitime_accepte(self):
        r = self.deposer('From: "Nom, Prenom" <a@envoi.example.test>')
        self.assertTrue(r.startswith("250"))

    def test_return_path_client_retire(self):
        self.auth()
        self.envoyer(corps="Return-Path: <x@dest.example.test>\r\n  suite\r\nreturn-path :<y@z.example.test>\r\n"
                           "From: a@envoi.example.test\r\n\r\nReturn-Path: corps\r\n")
        tete, _, corps = self.file.depots[0]["message"].partition(b"\r\n\r\n")
        self.assertNotIn(b"return-path", tete.lower()); self.assertNotIn(b"suite", tete)
        self.assertIn(b"From: a@envoi.example.test", tete)
        self.assertIn(b"Return-Path: corps", corps)


class TestDurcissement(Base):
    def test_verbe_hors_liste_blanche_refuse(self):
        self.auth()
        self.cmd("MAIL FROM:<a@envoi.example.test>")
        for v in ("RAZ", "FIN", "LIRE_DATA", "TLS_ETABLI", "AUTH_CREDS", "ligne"):
            self.assertTrue(self.cmd(v)[0].startswith("502"), v)
        self.assertEqual(self.s.mail_from, "a@envoi.example.test")  # RAZ n'a rien réinitialisé

    def test_reseau_joker_refuse_a_la_config(self):
        for n in ("0.0.0.0/0", "::/0"):
            with self.assertRaises(ValueError):
                cfg(ips_autorisees=[n])

    def test_dot_unstuffing_toute_ligne_commencant_par_point(self):
        self.auth()
        self.cmd("MAIL FROM:<a@envoi.example.test>"); self.cmd("RCPT TO:<d@dest.example.test>"); self.cmd("DATA")
        for l in (b"Subject: s\r\n", b"\r\n", b"..deux\r\n", b".un\r\n", b"normal\r\n"):
            self.s.ligne(l)
        self.assertTrue(self.s.ligne(b".\r\n")[0][0].startswith("250"))
        self.assertEqual(self.file.depots[0]["message"], b"Subject: s\r\n\r\n.deux\r\nun\r\nnormal\r\n")

    def test_empreinte_de_secours_meme_cout_que_les_vrais_comptes(self):
        c = cfg()
        it = lambda e: int(e.split("$")[1])
        self.assertEqual(it(c.empreinte_secours), it(c.comptes["alice"]["hash"]))
        appels = []
        import axion_injection.session as sess
        orig = sess.verifier
        sess.verifier = lambda m, e: (appels.append(it(e)), orig(m, e))[1]
        try:
            s = Session(c, self.file, IP_OK); s.accueil(); s.tls_etabli()
            s.ligne(("AUTH PLAIN " + plain("", "inconnu") + "\r\n").encode())
        finally:
            sess.verifier = orig
        self.assertEqual(appels, [it(c.comptes["alice"]["hash"])])

    def test_echecs_par_ip_bloquent_les_nouvelles_sessions(self):
        from axion_injection.limiteur import LimiteurEchecs
        lim = LimiteurEchecs(max_echecs=4, fenetre=60)
        delais = []
        for _ in range(4):
            s = Session(cfg(), self.file, IP_OK, lim); s.accueil(); s.tls_etabli()
            s.ligne(("AUTH PLAIN " + plain("", "alice", "faux") + "\r\n").encode())
            delais.append(s.delai)
        self.assertEqual(delais, sorted(delais)); self.assertGreater(delais[-1], delais[0])
        rep, act = Session(cfg(), self.file, IP_OK, lim).accueil()
        self.assertEqual(act, "close"); self.assertTrue(rep[0].startswith("421"))
        # une autre IP n'est pas touchée
        self.assertIsNone(Session(cfg(), self.file, "192.0.2.77", lim).accueil()[1])


@unittest.skipUnless(shutil.which("openssl"), "openssl absent")
class TestSocketDurci(unittest.TestCase):
    async def serveur(self, **kw):
        d = tempfile.mkdtemp(); self.addCleanup(shutil.rmtree, d, True)
        c, k = os.path.join(d, "c.pem"), os.path.join(d, "k.pem")
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", k, "-out", c,
                        "-subj", "/CN=example.test", "-days", "1"], check=True, capture_output=True)
        self.file = FausseFile()
        srv = await demarrer(cfg(ip_ecoute="127.0.0.1", port=0, cert=c, cle=k, ips_autorisees=["127.0.0.0/8"], **kw), self.file)
        return srv, srv.sockets[0].getsockname()[1]

    def test_starttls_et_auth_dans_le_meme_paquet_refuse(self):
        async def run():
            srv, port = await self.serveur()
            r, w = await asyncio.open_connection("127.0.0.1", port)
            await r.readline()
            w.write(b"STARTTLS\r\nAUTH PLAIN " + plain("", "alice").encode() + b"\r\n"); await w.drain()
            tout = b""
            try:
                tout = await asyncio.wait_for(r.read(), 5)     # doit se terminer : connexion coupée
            except asyncio.TimeoutError:
                self.fail("connexion non coupée")
            self.assertNotIn(b"235", tout)
            self.assertNotIn(b"220 2.0.0 pret", tout)
            self.assertTrue(tout.startswith(b"5") or tout == b"", tout)
            self.assertEqual(self.file.depots, [])
            srv.close(); await srv.wait_closed()
        asyncio.run(run())

    def test_connexions_simultanees_plafonnees(self):
        async def run():
            srv, port = await self.serveur(max_connexions=1)
            r1, w1 = await asyncio.open_connection("127.0.0.1", port); await r1.readline()
            r2, w2 = await asyncio.open_connection("127.0.0.1", port)
            self.assertTrue((await asyncio.wait_for(r2.readline(), 5)).startswith(b"421"))
            w1.close(); w2.close(); srv.close()
        asyncio.run(run())

    def test_duree_maximale_de_session(self):
        async def run():
            srv, port = await self.serveur(duree_max_session=0.6)
            r, w = await asyncio.open_connection("127.0.0.1", port); await r.readline()
            fin = False
            for _ in range(40):                      # goutte-à-goutte : la ligne ne dépasse jamais 300 s
                w.write(b"NOOP\r\n")
                try:
                    await w.drain()
                    if not await asyncio.wait_for(r.readline(), 2):
                        fin = True; break
                except (ConnectionError, asyncio.TimeoutError):
                    fin = True; break
                await asyncio.sleep(0.1)
            self.assertTrue(fin, "session non coupée")
            srv.close()
        asyncio.run(run())

    def test_pbkdf2_ne_bloque_pas_la_boucle(self):
        async def run():
            lourd = hacher(MDP, b"selselsel", 1_500_000)
            srv, port = await self.serveur(comptes={"alice": {"hash": lourd, "espace": "ws1"}})
            r, w = await asyncio.open_connection("127.0.0.1", port); await r.readline()
            w.write(b"STARTTLS\r\n"); await w.drain(); await r.readline()
            ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
            await w.start_tls(ctx)
            maxi, stop = 0.0, False

            async def pouls():
                nonlocal maxi
                t = asyncio.get_running_loop().time()
                while not stop:
                    await asyncio.sleep(0.01)
                    n = asyncio.get_running_loop().time(); maxi = max(maxi, n - t); t = n
            tache = asyncio.create_task(pouls())
            w.write(b"AUTH PLAIN " + plain("", "alice").encode() + b"\r\n"); await w.drain()
            self.assertTrue((await asyncio.wait_for(r.readline(), 30)).startswith(b"235"))
            stop = True; await tache
            self.assertLess(maxi, 0.15, "boucle asyncio bloquée par PBKDF2")
            w.close(); srv.close()
        asyncio.run(run())


class TestSuiteK4(Base):
    """Lot K4 suite : correctifs de l'audit (tests écrits avant les correctifs)."""

    def deposer(self, tete):
        self.auth()
        return self.envoyer(corps=tete + "\r\n\r\nm\r\n")[-1]

    def test_file_de_production_refuse_toujours_en_451(self):
        from axion_injection import serveur
        from axion_injection.file_kumo import ErreurFile
        f = serveur.file_production()
        self.assertNotIsInstance(f, FausseFile)
        with self.assertRaises(ErreurFile):
            f.deposer("ws1", "a@envoi.example.test", "d@dest.example.test", b"x")
        s = Session(cfg(), f, IP_OK); s.accueil(); s.tls_etabli()
        s.ligne(("AUTH PLAIN " + plain("", "alice") + "\r\n").encode())
        for c in ("MAIL FROM:<a@envoi.example.test>", "RCPT TO:<d@dest.example.test>", "DATA"):
            s.ligne(c.encode() + b"\r\n")
        self.assertTrue(s.ligne(b".\r\n")[0][0].startswith("451"))

    def test_principal_n_utilise_pas_la_fausse_file(self):
        import inspect
        from axion_injection import serveur
        self.assertNotIn("FausseFile", inspect.getsource(serveur))

    def test_getaddresses_sans_strict_message_refuse(self):
        import axion_injection.session as sess
        orig = sess.getaddresses
        sess.getaddresses = lambda valeurs: [("", "a@envoi.example.test")]   # ancien Python : pas de strict
        try:
            self.assertIsNone(sess.adresses("a@envoi.example.test"))
            r = self.deposer("From: a@envoi.example.test")
            with self.assertRaises(RuntimeError):
                sess.verifier_python()
        finally:
            sess.getaddresses = orig
        self.assertTrue(r.startswith("550"))
        self.assertEqual(self.file.depots, [])

    def test_demarrage_refuse_sans_strict(self):
        import axion_injection.session as sess
        orig = sess.getaddresses
        sess.getaddresses = lambda valeurs: []
        try:
            with self.assertRaises(RuntimeError):
                asyncio.run(demarrer(cfg(ip_ecoute="127.0.0.1", port=0), self.file))
        finally:
            sess.getaddresses = orig

    def test_deuxieme_mail_from_refuse_503(self):
        self.auth()
        self.assertTrue(self.cmd("MAIL FROM:<a@envoi.example.test>")[0].startswith("250"))
        self.cmd("RCPT TO:<d@dest.example.test>")
        self.assertTrue(self.cmd("MAIL FROM:<a@envoi.example.test>")[0].startswith("503"))
        self.cmd("RSET")
        self.assertTrue(self.cmd("MAIL FROM:<a@envoi.example.test>")[0].startswith("250"))

    def test_resent_sender_reply_to_hors_registre_refuses(self):
        for h in ("Resent-From: b@autre.example.test", "Resent-Sender: b@autre.example.test",
                  "Sender: b@autre.example.test", "Reply-To: b@autre.example.test",
                  "Reply-To: x@dest.example.test", "Resent-From: x@principal.example.test"):
            self.setUp()
            r = self.deposer("From: a@envoi.example.test\r\n" + h)
            self.assertTrue(r.startswith("550"), h)
            self.assertEqual(self.file.depots, [], h)

    def test_resent_sender_reply_to_du_registre_acceptes(self):
        r = self.deposer("From: a@envoi.example.test\r\nSender: a@envoi.example.test\r\n"
                         "Reply-To: a@envoi.example.test\r\nResent-From: a@envoi.example.test")
        self.assertTrue(r.startswith("250"))

    def test_reseaux_couvrant_tout_refuses(self):
        for lst in (["0.0.0.0/1", "128.0.0.0/1"], ["::/1", "8000::/1"],
                    ["0.0.0.0/2", "64.0.0.0/2", "128.0.0.0/1"]):
            with self.assertRaises(ValueError, msg=str(lst)):
                cfg(ips_autorisees=lst)
        cfg(ips_autorisees=["0.0.0.0/1"])      # une moitie seule reste permise

    def test_auth_en_deux_temps(self):
        self.tls()
        self.assertEqual(self.cmd("AUTH PLAIN"), ["334 "])
        self.assertTrue(self.s.ligne((plain("", "alice") + "\r\n").encode())[0][0].startswith("235"))
        self.assertEqual(self.s.authc, "alice")

    def test_auth_en_deux_temps_mauvais_mdp(self):
        self.tls()
        self.cmd("AUTH PLAIN")
        self.assertTrue(self.s.ligne((plain("", "alice", "faux") + "\r\n").encode())[0][0].startswith("535"))
        self.assertIsNone(self.s.authc)


@unittest.skipUnless(shutil.which("openssl"), "openssl absent")
class TestSocketSuiteK4(unittest.TestCase):
    serveur = TestSocketDurci.serveur

    async def ouvrir(self, port, tls=True):
        r, w = await asyncio.open_connection("127.0.0.1", port)
        await r.readline()
        if tls:
            w.write(b"STARTTLS\r\n"); await w.drain(); await r.readline()
            ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
            await w.start_tls(ctx)
        return r, w

    def test_auth_en_deux_temps_sur_vraie_connexion(self):
        async def run():
            srv, port = await self.serveur()
            r, w = await self.ouvrir(port)
            w.write(b"AUTH PLAIN\r\n"); await w.drain()
            self.assertEqual(await asyncio.wait_for(r.readline(), 5), b"334 \r\n")
            w.write(plain("", "alice").encode() + b"\r\n"); await w.drain()
            self.assertTrue((await asyncio.wait_for(r.readline(), 5)).startswith(b"235"))
            w.close(); srv.close()
        asyncio.run(run())

    def test_delai_du_limiteur_sur_vraie_connexion(self):
        async def run():
            srv, port = await self.serveur()
            r, w = await self.ouvrir(port)
            loop = asyncio.get_running_loop()
            t0 = loop.time()
            durees = []
            for _ in range(2):
                t = loop.time()
                w.write(b"AUTH PLAIN " + plain("", "alice", "faux").encode() + b"\r\n"); await w.drain()
                self.assertTrue((await asyncio.wait_for(r.readline(), 5)).startswith(b"535"))
                durees.append(loop.time() - t)
            self.assertGreaterEqual(durees[0], 0.045)       # 0,05 s au 1er echec
            self.assertGreaterEqual(durees[1], 0.095)       # 0,1 s au 2e echec
            w.close(); srv.close()
        asyncio.run(run())

    def test_100_connexions_3_essais_bloquees_avant_10_verifications(self):
        import axion_injection.session as sess
        appels = []
        orig = sess.verifier
        sess.verifier = lambda m, e: (appels.append(1), orig(m, e))[1]
        self.addCleanup(setattr, sess, "verifier", orig)

        async def client(port, refus):
            try:
                r, w = await self.ouvrir(port)
                for _ in range(3):
                    w.write(b"AUTH PLAIN " + plain("", "alice", "faux").encode() + b"\r\n"); await w.drain()
                    ligne = await asyncio.wait_for(r.readline(), 60)
                    if ligne.startswith(b"535"):
                        refus.append(1)
                    if not ligne or ligne.startswith(b"421"):
                        break
                w.close()
            except (ConnectionError, ssl.SSLError):
                pass

        async def run():
            srv, port = await self.serveur(max_connexions=200)
            refus = []
            await asyncio.gather(*[client(port, refus) for _ in range(100)])
            srv.close()
            return refus
        refus = asyncio.run(run())
        self.assertLessEqual(len(appels), 10, "PBKDF2 execute %d fois" % len(appels))
        self.assertLessEqual(len(refus), 10)
        self.assertGreaterEqual(len(appels), 1)

    def test_place_liberee_apres_fermeture(self):
        async def run():
            srv, port = await self.serveur(max_connexions=1)
            r1, w1 = await asyncio.open_connection("127.0.0.1", port); await r1.readline()
            r2, w2 = await asyncio.open_connection("127.0.0.1", port)
            self.assertTrue((await asyncio.wait_for(r2.readline(), 5)).startswith(b"421"))
            w1.close()
            for _ in range(50):
                r3, w3 = await asyncio.open_connection("127.0.0.1", port)
                if (await asyncio.wait_for(r3.readline(), 5)).startswith(b"220"):
                    w3.close(); srv.close(); return
                w3.close(); await asyncio.sleep(0.1)
            self.fail("place non liberee")
        asyncio.run(run())

    def test_drain_bloque_ne_retient_pas_la_connexion(self):
        from axion_injection.serveur import _client
        class W:
            def write(self, b): pass
            async def drain(self): await asyncio.Event().wait()
            def close(self): self.ferme = True
            def get_extra_info(self, k): return ("192.0.2.10", 1)
        async def run():
            etat = {"n": 0}
            c = cfg(delai_ecriture=0.2)
            await asyncio.wait_for(_client(c, FausseFile(), None, None, etat, type("R", (), {"_buffer": b""})(), W()), 3)
            return etat["n"]
        self.assertEqual(asyncio.run(run()), 0)

    def test_compteur_decremente_si_accueil_leve(self):
        from axion_injection.serveur import _client
        class W:
            def write(self, b): pass
            async def drain(self): pass
            def close(self): pass
            def get_extra_info(self, k): return ("192.0.2.10", 1)
        class L:
            def bloque(self, ip): raise RuntimeError("panne")
        async def run():
            etat = {"n": 0}
            with self.assertRaises(RuntimeError):
                await _client(cfg(), FausseFile(), None, L(), etat, type("R", (), {"_buffer": b""})(), W())
            return etat["n"]
        self.assertEqual(asyncio.run(run()), 0)


if __name__ == "__main__":
    unittest.main()
