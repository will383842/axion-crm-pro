"""K8 : le modèle de shaping est validé ; un modèle invalide fait échouer la CI."""
import copy
import pathlib
import re
import subprocess
import sys
import tempfile
import tomllib
import unittest

RACINE = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(RACINE))

import valider_shaping as v  # noqa: E402

MODELE = RACINE / "shaping.modele.toml"


def sans_commentaire(texte):
    return "\n".join(l.split("#", 1)[0] for l in texte.splitlines())


def sans_entetes(texte):
    """Retire les en-têtes de table TOML ([fournisseur.gmail.tsa] n'est pas un nom de domaine)."""
    return "\n".join(l for l in sans_commentaire(texte).splitlines() if not l.lstrip().startswith("["))


def noms_reels(texte):
    """Noms de domaine hors .test / .example (RFC 2606) trouvés hors commentaires."""
    trouves = re.findall(r"\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.([a-z][a-z0-9-]*)\b", sans_entetes(texte), flags=re.I)
    return [t for t in trouves if t.lower() not in ("test", "example")]


def charge():
    with open(MODELE, "rb") as f:
        return tomllib.load(f)


class ModeleLivre(unittest.TestCase):
    def test_le_modele_livre_est_valide(self):
        self.assertEqual(v.valider(charge()), [])

    def test_les_trois_fournisseurs_du_crm_sont_presents(self):
        self.assertEqual(sorted(charge()["fournisseur"]), ["autre", "gmail", "microsoft"])

    def test_aucun_nom_de_domaine_ni_ip_litterale(self):
        texte = MODELE.read_text(encoding="utf-8")
        self.assertEqual(noms_reels(texte), [])
        self.assertIsNone(re.search(r"\b\d{1,3}(\.\d{1,3}){3}\b", sans_commentaire(texte)))

    def test_le_detecteur_de_domaines_reels_est_strict(self):
        # Tout nom de domaine réel est refusé, quelle que soit l'extension ; seuls .test et .example passent (ADR l.253).
        for reel in ("gmail.com", "mail.exemple.fr", "a.b.io", "x.dev", "foo.co.uk", 'h = "smtp.outlook.com"'):
            self.assertNotEqual(noms_reels(reel), [], reel)
        for ok in ("serveur.test", "mx.example", "valeur = 0.5", "version = 1"):
            self.assertEqual(noms_reels(ok), [], ok)


class ModelesInvalides(unittest.TestCase):
    def erreurs(self, modifie):
        m = copy.deepcopy(charge())
        modifie(m)
        return v.valider(m)

    def test_fournisseur_manquant(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"].pop("microsoft")))

    def test_debit_heure_nul_ou_negatif(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"].update(debit_max_par_heure=0)))
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"].update(debit_max_par_heure=-5)))

    def test_debit_minute_incoherent_avec_heure(self):
        # Une minute ne peut pas autoriser plus que l'heure entière.
        def casse(m):
            m["fournisseur"]["gmail"].update(debit_max_par_heure=100, debit_max_par_minute=200)
        self.assertTrue(self.erreurs(casse))

    def test_connexions_simultanees_hors_bornes(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["autre"].update(connexions_simultanees=0)))
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["autre"].update(connexions_simultanees=1000)))

    def test_booleen_refuse_comme_entier(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["autre"].update(connexions_simultanees=True)))

    def test_tsa_obligatoire_et_actif(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"].pop("tsa")))
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"]["tsa"].update(actif=False)))

    def test_tsa_reduction_hors_bornes(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"]["tsa"].update(reduction_pourcent=0)))
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"]["tsa"].update(reduction_pourcent=100)))

    def test_cle_inconnue_refusee(self):
        self.assertTrue(self.erreurs(lambda m: m["fournisseur"]["gmail"].update(inconnue=1)))

    def test_version_inconnue(self):
        self.assertTrue(self.erreurs(lambda m: m["modele"].update(version=99)))

    def test_version_booleenne_ou_flottante_refusee(self):
        # True == 1 et 1.0 == 1 en Python : sans contrôle de type, ces deux versions passeraient.
        self.assertTrue(self.erreurs(lambda m: m["modele"].update(version=True)))
        self.assertTrue(self.erreurs(lambda m: m["modele"].update(version=1.0)))


class LigneDeCommande(unittest.TestCase):
    def lancer(self, contenu):
        with tempfile.TemporaryDirectory() as d:
            p = pathlib.Path(d) / "m.toml"
            p.write_text(contenu, encoding="utf-8")
            return subprocess.run([sys.executable, str(RACINE / "valider_shaping.py"), str(p)], capture_output=True, text=True)

    def test_modele_invalide_donne_un_code_non_nul(self):
        r = self.lancer("[modele]\nversion = 1\n")
        self.assertEqual(r.returncode, 1, r.stdout + r.stderr)

    def test_toml_illisible_donne_un_code_non_nul(self):
        r = self.lancer("ceci n'est pas = = du toml")
        self.assertEqual(r.returncode, 1)

    def test_modele_livre_donne_zero(self):
        r = subprocess.run([sys.executable, str(RACINE / "valider_shaping.py"), str(MODELE)], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == "__main__":
    unittest.main()
