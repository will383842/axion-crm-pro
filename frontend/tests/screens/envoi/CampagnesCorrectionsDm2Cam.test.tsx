/**
 * DM2-CAM — corrections de la relecture : aucune ouverture dans la démo, champ
 * « Jour » / « Heure » vidé sans plantage, focus clavier conservé, boîte démo
 * au nom inventé, progression honnête.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { server } from '../../msw/server';
import { CampagnesPage } from '@/features/envoi/campagnes/CampagnesPage';
import { BarreProgression } from '@/features/envoi/campagnes/composants/BarreProgression';
import { parisVersIso } from '@/features/envoi/campagnes/composants/libelles';
import { handlers } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const lire = async (chemin: string, init?: RequestInit): Promise<string> => (await fetch(`http://localhost/api/v1${chemin}`, init)).text();

beforeEach(() => {
  demoDb.reinitialiser();
  definirEtatForce(null);
});
afterEach(() => definirEtatForce(null));

describe('Démo des campagnes — aucune ouverture exposée', () => {
  it('aucune réponse du handler ne contient « ouverture » ni « suivre_ouvertures »', async () => {
    server.use(...handlers);
    const ids = demoDb.campagnes.tous().map((c) => String(c.id));
    const reponses = [
      await lire('/envoi/campagnes?par_page=200'),
      await lire('/envoi/campagnes/calendrier'),
      ...(await Promise.all(ids.map((id) => lire(`/envoi/campagnes/${id}`)))),
      ...(await Promise.all(ids.map((id) => lire(`/envoi/campagnes/${id}/destinataires?par_page=200`)))),
      await lire(`/envoi/campagnes/${ids[0]}/dupliquer`, { method: 'POST', body: '{}' }),
    ];
    for (const texte of reponses) {
      expect(texte).not.toMatch(/ouverture|automatique_probable|raison_automatique/i);
      expect(texte).not.toContain('suivre_ouvertures');
    }
  });

  it('« Ce qui se passe » garde ses 8 derniers événements utiles (hors « remis »)', async () => {
    server.use(...handlers);
    for (const c of demoDb.campagnes.tous()) {
      const fiche = JSON.parse(await lire(`/envoi/campagnes/${c.id}`)) as { derniers_evenements: Array<{ type: string }> };
      expect(fiche.derniers_evenements.every((e) => e.type !== 'remis')).toBe(true);
      expect(JSON.stringify(fiche.derniers_evenements)).not.toContain('automatique_probable');
      const utiles = demoDb.evenementsEnvoi.filtrer((e) => e.campagne_id === String(c.id) && e.type !== 'remis').length;
      expect(fiche.derniers_evenements).toHaveLength(Math.min(8, utiles));
    }
  });
});

describe('Changer la date — champs vidés et focus', () => {
  async function ouvrirDialogueDate() {
    const user = userEvent.setup();
    await renderScreen(<CampagnesPage />, { path: '/envoi/campagnes', url: '/envoi/campagnes', handlers, landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'] });
    const ligne = await screen.findByRole('row', { name: /Fédérations — début d’année/ });
    await user.click(within(ligne).getByRole('button', { name: /^Changer la date/ }));
    const dialogue = await screen.findByRole('dialog');
    await user.click(within(dialogue).getByRole('button', { name: 'Autre…' }));
    return { user, dialogue };
  }

  it('vider « Jour » ou « Heure » ne plante pas et désactive « Reprogrammer »', async () => {
    const { user, dialogue } = await ouvrirDialogueDate();
    const jour = within(dialogue).getByLabelText('Jour');
    await user.clear(jour);
    expect(within(dialogue).getByRole('button', { name: 'Reprogrammer' })).toBeDisabled();
    const heure = within(dialogue).getByLabelText('Heure');
    await user.clear(heure);
    expect(within(dialogue).getByRole('button', { name: 'Reprogrammer' })).toBeDisabled();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('parisVersIso renvoie null pour un champ vide ou invalide', () => {
    expect(parisVersIso('', '09:00')).toBeNull();
    expect(parisVersIso('2027-01-13', '')).toBeNull();
    expect(parisVersIso('2027-13-45', 'xx')).toBeNull();
    expect(parisVersIso('2027-01-13', '09:00')).toBe('2027-01-13T08:00:00.000Z');
  });

  it('le focus reste sur le bouton après Espace (le composant n’est pas recréé)', async () => {
    const { user, dialogue } = await ouvrirDialogueDate();
    const lundi = within(dialogue).getByRole('button', { name: 'Lundi 9:00' });
    lundi.focus();
    await user.keyboard(' ');
    const apres = within(dialogue).getByRole('button', { name: 'Lundi 9:00' });
    expect(apres).toBe(lundi);
    expect(apres).toHaveFocus();
    expect(apres).toHaveAttribute('aria-pressed', 'true');
  });

  it('le focus reste sur une case de « Dupliquer » après Espace', async () => {
    const user = userEvent.setup();
    await renderScreen(<CampagnesPage />, { path: '/envoi/campagnes', url: '/envoi/campagnes', handlers, landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'] });
    const ligne = await screen.findByRole('row', { name: /Fédérations — début d’année/ });
    await user.click(within(ligne).getByRole('button', { name: /^Dupliquer/ }));
    const dialogue = await screen.findByRole('dialog');
    const case_ = within(dialogue).getByRole('checkbox', { name: /Exclure ceux qui ont déjà reçu/ });
    case_.focus();
    await user.keyboard(' ');
    const apres = within(dialogue).getByRole('checkbox', { name: /Exclure ceux qui ont déjà reçu/ });
    expect(apres).toBe(case_);
    expect(apres).toHaveFocus();
    expect(apres).toBeChecked();
  });
});

describe('Détails', () => {
  it('99,5 % n’affiche pas 100 %', () => {
    render(<BarreProgression envoyes={199} total={200} nom="Test" />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '99');
  });

  it('la boîte démo porte un nom inventé', () => {
    const boite = demoDb.boites.trouver('boi-camille');
    expect(boite?.nom_affiche).toBe('Camille · Axion (démo)');
    expect(JSON.stringify(demoDb.boites.tous())).not.toMatch(/will/i);
  });
});
