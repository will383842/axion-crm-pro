"""Intégration en processus (sans Docker) : puits SMTP + IMAP du banc sur 127.0.0.1, ports éphémères. Rejoue S18 à 6."""
import asyncio, pathlib, sys, unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "services"))
import temoins
from imap_banc import faire_client_imap
from puits_smtp import faire_client

SECRET = "secret-de-test-local"
ENV = {"BANC_SECRET": SECRET, "BANC_TEMOIN_GMAIL": "inbox", "BANC_TEMOIN_YAHOO": "absent",
       "BANC_TEMOIN_OUTLOOK_COM": "junk"}
GMAIL, OUTLOOK, YAHOO = (temoins.TEMOINS[k]["adresse"] for k in ("gmail", "outlook_com", "yahoo"))


async def echange(port, lignes, attendre_220=True):
    r, w = await asyncio.open_connection("127.0.0.1", port)
    sortie = [(await r.readline()).decode().strip()]
    for l in lignes:
        w.write((l + "\r\n").encode()); await w.drain()
        sortie.append((await r.readline()).decode().strip())
    w.close()
    return sortie


async def imap(port, adresse, mdp, dossier):
    r, w = await asyncio.open_connection("127.0.0.1", port)
    await r.readline()
    async def cmd(c):
        w.write((c + "\r\n").encode()); await w.drain()
        out = []
        while True:
            l = (await r.readline()).decode().strip(); out.append(l)
            if l.startswith("a "): return out
    login = (await cmd(f'a LOGIN "{adresse}" "{mdp}"'))[-1]
    exist = None
    if " OK " in login:
        exist = [l for l in await cmd(f"a SELECT {dossier}") if l.endswith("EXISTS")][0]
    w.close()
    return login, exist


class TestS18A6(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.banc = temoins.Banc(ENV)
        self.smtp = await asyncio.start_server(faire_client("accepte", self.banc), "127.0.0.1", 0)
        self.imap = await asyncio.start_server(faire_client_imap(self.banc), "127.0.0.1", 0)
        self.ps, self.pi = (s.sockets[0].getsockname()[1] for s in (self.smtp, self.imap))

    async def asyncTearDown(self):
        for s in (self.smtp, self.imap):
            s.close(); await s.wait_closed()

    async def envoyer(self, rcpt):
        return await echange(self.ps, ["EHLO t", "MAIL FROM:<a@exp.test>", f"RCPT TO:<{rcpt}>",
                                       "DATA", "Subject: s18\r\n\r\ncorps\r\n."])

    async def test_rcpt_hors_des_6_refuse_550(self):
        for hors in ("quelquun@exemple.test", "temoin@gmail.com", "x@gmail.test"):
            rep = await echange(self.ps, ["EHLO t", "MAIL FROM:<a@exp.test>", f"RCPT TO:<{hors}>", "DATA"])
            self.assertTrue(rep[3].startswith("550"), rep)
            self.assertTrue(rep[4].startswith("503"), rep)  # aucun destinataire valide : pas de DATA

    async def test_inbox_junk_absent_via_imap(self):
        for a in (GMAIL, OUTLOOK, YAHOO):
            self.assertTrue((await self.envoyer(a))[-1].startswith("250"))
        mdp = lambda a: temoins.mot_de_passe(SECRET, a)
        self.assertEqual((await imap(self.pi, GMAIL, mdp(GMAIL), "INBOX"))[1], "* 1 EXISTS")
        self.assertEqual((await imap(self.pi, GMAIL, mdp(GMAIL), "Junk"))[1], "* 0 EXISTS")
        self.assertEqual((await imap(self.pi, OUTLOOK, mdp(OUTLOOK), "Junk"))[1], "* 1 EXISTS")
        self.assertEqual((await imap(self.pi, OUTLOOK, mdp(OUTLOOK), "INBOX"))[1], "* 0 EXISTS")
        for d in ("INBOX", "Junk"):
            self.assertEqual((await imap(self.pi, YAHOO, mdp(YAHOO), d))[1], "* 0 EXISTS")

    async def test_mauvais_mot_de_passe_refuse(self):
        login, exist = await imap(self.pi, GMAIL, "mauvais", "INBOX")
        self.assertIn(" NO ", login)
        self.assertIsNone(exist)

    def test_comportement_inconnu_refuse(self):
        with self.assertRaises(ValueError):
            temoins.Banc({"BANC_TEMOIN_GMAIL": "n_importe_quoi"})


if __name__ == "__main__":
    unittest.main()
