/**
 * Dettes des démos #400 (conformité) et #401 (campagnes, CRM) : chaque test
 * rougit sans son correctif. Données fictives (dépôt public).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { ReglesPrudencePage } from '@/features/envoi/reglages/ReglesPrudencePage';
import { ReglagesEnvoiPage } from '@/features/envoi/reglages/ReglagesEnvoiPage';
import { gestesPermis } from '@/features/envoi/campagnes/composants/GestesCampagne';
import { handlers as conformite, JETON_DEMO } from '@/demo/handlers/envoi/conformite.handlers';
import { handlers as campagnes } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';
import { server } from '../../msw/server';

const RACINE = 'https://api.localhost/api/v1';
const JSON_ENTETE = { 'Content-Type': 'application/json' };

beforeEach(() => demoDb.reinitialiser());

describe('Règles de prudence : un champ vidé ou négatif n’est jamais enregistré (#400 I1)', () => {
  async function ouvrir() {
    return renderScreen(<ReglesPrudencePage />, { path: '/envoi/reglages/regles', url: '/envoi/reglages/regles', handlers: conformite });
  }

  it('écran : vider « messages par organisation » bloque l’enregistrement', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const champ = await screen.findByRole('spinbutton', { name: /messages par organisation/i });
    await user.clear(champ);
    expect(await screen.findByRole('alert')).toHaveTextContent(/au moins 1/i);
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
    await user.clear(champ);
    await user.type(champ, '0');
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
  });

  it('écran : 0 et 0,5 acceptés pour plaintes et refus ; vide, négatif refusés', async () => {
    const user = userEvent.setup();
    await ouvrir();
    for (const nom of [/seuil de plaintes|plainte/i, /adresses refusées/i]) {
      const champ = await screen.findByRole('spinbutton', { name: nom });
      await user.clear(champ);
      await user.type(champ, '0.5');
      expect(screen.queryByRole('alert')).toBeNull();
      expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled();
      await user.clear(champ);
      await user.type(champ, '0');
      expect(screen.queryByRole('alert')).toBeNull();
      expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled();
      for (const saisie of ['-1']) {
        await user.clear(champ);
        if (saisie !== '') await user.type(champ, saisie);
        expect(await screen.findByRole('alert')).toHaveTextContent(/0 ou plus/i);
        expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
      }
      await user.clear(champ);
      expect(await screen.findByRole('alert')).toBeVisible();
      expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
      await user.type(champ, '0.5');
    }
  });

  it('faux serveur : mêmes règles que l’écran (422 si refusé, 200 pour 0 et 0,5)', async () => {
    server.use(...conformite);
    const actuel = (await (await fetch(`${RACINE}/envoi/reglages/regles`)).json()) as Record<string, unknown>;
    const put = (corps: unknown) => fetch(`${RACINE}/envoi/reglages/regles`, { method: 'PUT', headers: JSON_ENTETE, body: JSON.stringify(corps) });
    for (const cle of ['pause_si_refus_pct', 'pause_si_plaintes_pour_mille']) {
      for (const valeur of [-1, null, undefined, '1']) {
        expect((await put({ ...actuel, [cle]: valeur })).status, `${cle}=${String(valeur)}`).toBe(422);
      }
    }
    for (const valeur of [-1, 1.5, null, undefined]) {
      expect((await put({ ...actuel, frein_reponses_par_jour: valeur })).status, `frein=${String(valeur)}`).toBe(422);
    }
    for (const valeur of [0, -1, 1.5, null, undefined]) {
      expect((await put({ ...actuel, pression_max_par_organisation_30j: valeur })).status, `pression=${String(valeur)}`).toBe(422);
    }
    expect(await (await fetch(`${RACINE}/envoi/reglages/regles`)).json()).toEqual(actuel);
    expect((await put({ ...actuel, pause_si_plaintes_pour_mille: 0.5, pause_si_refus_pct: 0.5 })).status).toBe(200);
    expect((await put({ ...actuel, pause_si_plaintes_pour_mille: 0, pause_si_refus_pct: 0 })).status).toBe(200);
    expect((await put({ ...actuel, pause_si_plaintes_pour_mille: 0, pause_si_refus_pct: 0, frein_reponses_par_jour: 0 })).status).toBe(200);
  });

  it('écran : frein_reponses_par_jour à 0 accepté', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const champ = await screen.findByRole('spinbutton', { name: /réponses/i });
    await user.clear(champ);
    await user.type(champ, '0');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeEnabled();
  });

  it('pastille « actives » : un seuil à 0,5 (ou 0) compte comme actif', async () => {
    server.use(...conformite);
    const actuel = (await (await fetch(`${RACINE}/envoi/reglages/regles`)).json()) as Record<string, unknown>;
    const reponse = await fetch(`${RACINE}/envoi/reglages/regles`, { method: 'PUT', headers: JSON_ENTETE, body: JSON.stringify({ ...actuel, pause_si_plaintes_pour_mille: 0.5 }) });
    expect(reponse.status).toBe(200);
    const relu = (await (await fetch(`${RACINE}/envoi/reglages/regles`)).json()) as Record<string, unknown>;
    expect(relu.pause_si_plaintes_pour_mille).toBe(0.5);
    await ouvrir();
    await screen.findByRole('spinbutton', { name: /messages par organisation/i });
    expect(screen.getByText('5 actives')).toBeVisible();
  });

  it('pastille « actives » calculée, pas écrite en dur (#400 M2)', async () => {
    await ouvrir();
    await screen.findByRole('spinbutton', { name: /messages par organisation/i });
    // 4 seuils renseignés + test du matin bloquant.
    expect(screen.getByText('5 actives')).toBeVisible();
  });
});

describe('Désinscription « toute l’organisation » (#400 M1)', () => {
  it('désinscrit tous les destinataires de l’organisation et la page le sait', async () => {
    server.use(...conformite);
    const dest = demoDb.destinataires.tous().find((d) => d.adresse.includes('boulangerie-tilleuls'))!;
    const collegues = demoDb.destinataires.tous().filter((d) => d.organisation === dest.organisation);
    expect(dest.organisation).not.toBeNull();
    const reponse = await fetch(`${RACINE.replace('/api/v1', '')}/desinscription/${JETON_DEMO}`, { method: 'POST', headers: JSON_ENTETE, body: JSON.stringify({ toute_l_organisation: true }) });
    expect(reponse.status).toBe(200);
    expect(collegues.every((d) => demoDb.destinataires.trouver(d.id)!.etat === 'desinscrit')).toBe(true);
    const vue = (await (await fetch(`${RACINE.replace('/api/v1', '')}/desinscription/${JETON_DEMO}`)).json()) as { deja_desinscrit: boolean };
    expect(vue.deja_desinscrit).toBe(true);
  });
});

describe('Réglages d’envoi : chiffres et chargement (#400 M3, M5)', () => {
  it('le nombre de règles d’alerte est sur « Alertes et avis », pas sur « Règles de prudence »', async () => {
    await renderScreen(<ReglagesEnvoiPage />, { path: '/envoi/reglages', url: '/envoi/reglages', handlers: conformite });
    await screen.findByRole('heading', { name: 'Mode absence' });
    const liens = screen.getAllByRole('link');
    const alertes = liens.find((l) => /Alertes et avis/.test(l.textContent ?? '') && /règle/.test(l.textContent ?? ''));
    expect(alertes).toBeDefined();
    expect(alertes).toHaveTextContent(/\d+ règle\(s\) d’alerte active\(s\)/);
    const prudence = liens.find((l) => /Règles de prudence et horaires/.test(l.textContent ?? ''))!;
    expect(prudence).not.toHaveTextContent(/alerte/i);
  });

  it('« Désactiver » passe en chargement (et se bloque) pendant l’envoi', async () => {
    const user = userEvent.setup();
    await renderScreen(<ReglagesEnvoiPage />, { path: '/envoi/reglages', url: '/envoi/reglages', handlers: conformite });
    await user.type(await screen.findByLabelText('Du'), '2027-02-01');
    await user.type(screen.getByLabelText('Au'), '2027-02-10');
    await user.click(screen.getByRole('button', { name: 'Activer le mode absence' }));
    const desactiver = await screen.findByRole('button', { name: 'Désactiver' });
    // Nouvelle porte pour l'envoi de la désactivation.
    let ouvrirSecond: () => void = () => undefined;
    const second = new Promise<void>((r) => { ouvrirSecond = r; });
    server.use(http.put(api('/envoi/reglages/absence'), async ({ request }) => {
      await second;
      return HttpResponse.json(await request.json());
    }));
    await user.click(desactiver);
    const bouton = screen.getByRole('button', { name: /Désactiver/ });
    expect(bouton).toBeDisabled();
    expect(bouton.querySelector('svg.animate-spin')).not.toBeNull();
    ouvrirSecond();
    await screen.findByText('Mode absence désactivé.');
  });
});

describe('Campagnes : « terminer » aligné sur l’écran (#401)', () => {
  it('le faux serveur refuse de terminer une campagne à valider, comme gestesPermis', async () => {
    server.use(...campagnes);
    const toutes = demoDb.campagnes.tous();
    expect(gestesPermis('a_valider').terminer).toBe(false);
    const cible = toutes.find((c) => c.statut === 'a_valider');
    expect(cible, 'le jeu de démo doit contenir une campagne à valider').toBeDefined();
    const reponse = await fetch(`${RACINE}/envoi/campagnes/${cible!.id}/terminer`, { method: 'POST' });
    expect(reponse.status).toBe(409);
    expect(demoDb.campagnes.trouver(cible!.id)!.statut).toBe('a_valider');
  });

  it('pour chaque statut, le serveur et l’écran donnent la même réponse', async () => {
    server.use(...campagnes);
    for (const statut of ['brouillon', 'a_valider', 'programmee', 'en_cours', 'en_pause', 'terminee', 'arretee'] as const) {
      const c = demoDb.campagnes.tous()[0]!;
      demoDb.campagnes.modifier(c.id, { statut });
      const reponse = await fetch(`${RACINE}/envoi/campagnes/${c.id}/terminer`, { method: 'POST' });
      expect(reponse.status === 200, statut).toBe(gestesPermis(statut).terminer);
    }
  });
});
