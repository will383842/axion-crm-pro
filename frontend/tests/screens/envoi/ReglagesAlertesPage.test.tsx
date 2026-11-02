/**
 * DM2-CNF — « Alertes et avis » (console et e-mail SEULEMENT) et « Règles et
 * horaires » (on ne peut que DURCIR). Données fictives (dépôt public).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ReglagesAlertesPage } from '@/features/envoi/reglages/ReglagesAlertesPage';
import { ReglesPrudencePage } from '@/features/envoi/reglages/ReglesPrudencePage';
import { verifierDurcissement, type ReglesPrudence } from '@/features/envoi/conformite/composants/regles';
import { handlers } from '@/demo/handlers/envoi/conformite.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';
import { server } from '../../msw/server';

describe('Alertes et avis (DM2-CNF)', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  async function ouvrir() {
    return renderScreen(<ReglagesAlertesPage />, { path: '/envoi/reglages/alertes', url: '/envoi/reglages/alertes', handlers });
  }

  it('ne propose que les canaux « Console » et « E-mail »', async () => {
    await ouvrir();
    const tableau = await screen.findByRole('table', { name: /type et canal/i });
    const colonnes = within(tableau).getAllByRole('columnheader').map((c) => c.textContent?.trim());
    expect(colonnes).toEqual(['Alerte', 'Console', 'E-mail']);
    expect(screen.queryByText(/téléphone|sms|telegram|whatsapp|slack/i)).toBeNull();
  });

  it('« Envoyer une alerte de test » confirme l’envoi', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await user.click(await screen.findByRole('button', { name: 'Envoyer une alerte de test' }));
    expect(await screen.findByRole('status')).toHaveTextContent(/alerte de test/i);
  });

  it('décocher un canal se voit après rechargement', async () => {
    const user = userEvent.setup();
    const vue = await ouvrir();
    const regle = demoDb.reglesAlerte.tous().find((r) => r.canaux.includes('email'))!;
    const case_ = await screen.findByRole('checkbox', { name: `${regle.nom} : E-mail` });
    expect(case_).toBeChecked();
    await user.click(case_);
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText(/enregistré/i);
    expect(demoDb.reglesAlerte.trouver(regle.id)!.canaux).not.toContain('email');
    vue.unmount();
    await ouvrir();
    expect(await screen.findByRole('checkbox', { name: `${regle.nom} : E-mail` })).not.toBeChecked();
  });
});

describe('Règles et horaires : durcir seulement (DM2-CNF)', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  const base: ReglesPrudence = {
    pause_si_refus_pct: 2,
    pause_si_plaintes_pour_mille: 1,
    test_du_matin_bloquant: true,
    frein_reponses_par_jour: 15,
    pression_max_par_organisation_30j: 3,
    heure_de: '09:00',
    heure_a: '17:30',
    jours_ouvres_seulement: true,
  };

  it('accepte une valeur plus prudente', () => {
    expect(verifierDurcissement(base, { ...base, pause_si_refus_pct: 1.5, frein_reponses_par_jour: 10, heure_de: '10:00', heure_a: '17:00' })).toEqual([]);
  });

  it('refuse chaque assouplissement', () => {
    expect(verifierDurcissement(base, { ...base, pause_si_refus_pct: 3 })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, pause_si_plaintes_pour_mille: 2 })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, frein_reponses_par_jour: 20 })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, pression_max_par_organisation_30j: 4 })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, heure_de: '08:00' })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, heure_a: '19:00' })).toHaveLength(1);
    expect(verifierDurcissement(base, { ...base, jours_ouvres_seulement: false })).toHaveLength(1);
  });

  async function ouvrir() {
    return renderScreen(<ReglesPrudencePage />, { path: '/envoi/reglages/regles', url: '/envoi/reglages/regles', handlers });
  }

  it('l’écran refuse d’assouplir un seuil, et enregistre un durcissement', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const champ = await screen.findByRole('spinbutton', { name: /adresses refusées/i });
    const valeur = Number((champ as HTMLInputElement).value);
    await user.clear(champ);
    await user.type(champ, String(valeur + 1));
    expect(await screen.findByRole('alert')).toHaveTextContent(/durcir/i);
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();

    await user.clear(champ);
    await user.type(champ, String(valeur - 1));
    expect(screen.queryByRole('alert')).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByRole('status')).toHaveTextContent(/enregistré/i);
  });

  it('le serveur de démonstration refuse aussi un assouplissement (422)', async () => {
    server.use(...handlers);
    const reponse = await fetch('https://api.localhost/api/v1/envoi/reglages/regles', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...base, pause_si_refus_pct: 9 }),
    });
    expect(reponse.status).toBe(422);
  });
});
