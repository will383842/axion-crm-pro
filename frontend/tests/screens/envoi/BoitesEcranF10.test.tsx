/**
 * L8-F10 — écran des boîtes d'envoi : liste, fiche, suspendre / réactiver selon
 * les permissions, 409 en clair, ajout désactivé (501 au contrat), aucun champ secret.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { BoitePage } from '@/features/envoi/infrastructure/BoitePage';
import { BoitesPage } from '@/features/envoi/infrastructure/BoitesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../helpers/renderScreen';
import { apiUrl } from '../../msw/handlers';

const moi = (permissions: string[]) => http.get(apiUrl('/auth/me'), () => HttpResponse.json({ permissions }));
const TOUT = ['users.manage', 'workspaces.manage'];

async function monterListe(permissions: string[] = TOUT) {
  const vue = await renderScreen(<BoitesPage />, { path: '/envoi/boites', handlers: [moi(permissions), ...handlers] });
  await screen.findByText('claire@prospection-a.example');
  return vue;
}

async function monterFiche(id: string, adresse: string, permissions: string[] = TOUT) {
  const vue = await renderScreen(<BoitePage />, { path: '/envoi/boites/$boiteId', url: `/envoi/boites/${id}`, handlers: [moi(permissions), ...handlers] });
  await screen.findByRole('heading', { level: 1, name: adresse });
  return vue;
}

describe('L8-F10 — liste des boîtes', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('liste les boîtes avec statut, quota et refus, sans chauffe propre', async () => {
    await monterListe();
    expect(screen.getByRole('heading', { level: 1, name: 'Boîtes d’envoi' })).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: /@prospection-/ })).toHaveLength(demoDb.boites.tous().length);
    expect(screen.queryByText(/palier/i)).toBeNull();
  });

  it('affiche un bouton d’ajout désactivé « bientôt disponible », sans aucun formulaire', async () => {
    const u = userEvent.setup();
    await monterListe();
    const bouton = screen.getByRole('button', { name: 'Ajout de boîte : bientôt disponible' });
    expect(bouton).toBeDisabled();
    await u.click(bouton);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('button', { name: /Tester la connexion/ })).toBeNull();
  });

  it('est accessible : un seul titre de page, tout lien et bouton nommé', async () => {
    await monterListe();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    for (const el of [...screen.getAllByRole('link'), ...screen.getAllByRole('button')]) {
      expect(el).toHaveAccessibleName();
    }
  });
});

describe('L8-F10 — fiche d’une boîte', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('résume la chauffe du domaine dans la fiche', async () => {
    await monterFiche('boi-paul', 'paul@prospection-c.example');
    const bloc = await screen.findByRole('region', { name: 'Chauffe' });
    await waitFor(() => expect(within(bloc).getByText(/P2/)).toBeInTheDocument());
    expect(within(bloc).getByText(/6 jours/)).toBeInTheDocument();
    expect(within(bloc).getByText(/20/)).toBeInTheDocument();
  });

  it('libelle le champ des réglages « Pied de message », jamais « Signature »', async () => {
    await monterFiche('boi-claire', 'claire@prospection-a.example');
    expect(await screen.findByLabelText('Pied de message')).toBeInTheDocument();
    expect(screen.queryByLabelText('Signature')).toBeNull();
    expect(screen.queryByText(/^Signature/)).toBeNull();
  });

  it('est accessible : titre unique, champs étiquetés, boutons nommés', async () => {
    await monterFiche('boi-claire', 'claire@prospection-a.example');
    await screen.findByLabelText('Pied de message');
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    for (const el of [...screen.getAllByRole('textbox'), ...screen.getAllByRole('checkbox'), ...screen.getAllByRole('button')]) {
      expect(el).toHaveAccessibleName();
    }
  });
});

describe('L8-F10 — suspendre et réactiver', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('« Suspendre » exige users.manage', async () => {
    const u = userEvent.setup();
    await monterFiche('boi-claire', 'claire@prospection-a.example', ['workspaces.manage']);
    const bouton = await screen.findByRole('button', { name: 'Suspendre' });
    await waitFor(() => expect(bouton).toBeDisabled());
    expect(screen.getByText(/users\.manage/)).toBeInTheDocument();
    await u.click(bouton);
    expect(demoDb.boites.trouver('boi-claire')?.statut).toBe('prete');
  });

  it('suspend la boîte avec users.manage', async () => {
    const u = userEvent.setup();
    await monterFiche('boi-claire', 'claire@prospection-a.example', ['users.manage']);
    const bouton = await screen.findByRole('button', { name: 'Suspendre' });
    await waitFor(() => expect(bouton).toBeEnabled());
    await u.click(bouton);
    expect(await screen.findByRole('button', { name: 'Réactiver' })).toBeInTheDocument();
    expect(demoDb.boites.trouver('boi-claire')?.statut).toBe('suspendue');
  });

  it('« Réactiver » exige workspaces.manage', async () => {
    await monterFiche('boi-marc', 'marc@prospection-d.example', ['users.manage']);
    const bouton = await screen.findByRole('button', { name: 'Réactiver' });
    await waitFor(() => expect(bouton).toBeDisabled());
    expect(screen.getByText(/workspaces\.manage/)).toBeInTheDocument();
  });

  it('réactive une boîte suspendue avec workspaces.manage', async () => {
    const u = userEvent.setup();
    await monterFiche('boi-marc', 'marc@prospection-d.example', ['workspaces.manage']);
    const bouton = await screen.findByRole('button', { name: 'Réactiver' });
    await waitFor(() => expect(bouton).toBeEnabled());
    await u.click(bouton);
    expect(await screen.findByRole('button', { name: 'Suspendre' })).toBeInTheDocument();
    expect(demoDb.boites.trouver('boi-marc')?.statut).not.toBe('suspendue');
  });

  it('affiche le 409 en clair quand le frein est actif', async () => {
    const u = userEvent.setup();
    demoDb.etatEnvois.modifier({ etat: 'arrete' });
    await monterFiche('boi-marc', 'marc@prospection-d.example', TOUT);
    const bouton = await screen.findByRole('button', { name: 'Réactiver' });
    await waitFor(() => expect(bouton).toBeEnabled());
    await u.click(bouton);
    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent('impossible tant que le frein est actif');
    expect(demoDb.boites.trouver('boi-marc')?.statut).toBe('suspendue');
  });
});

describe('L8-F10 — aucun secret à l’écran', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('ni liste ni fiche n’ont de champ d’identifiant, d’hôte, de port ou de mot de passe', async () => {
    const liste = await monterListe();
    const interdit = /mot de passe|identifiant|h[ôo]te|\bport\b|imap|smtp|password/i;
    expect(document.body.textContent ?? '').not.toMatch(interdit);
    expect(document.querySelector('input[type="password"]')).toBeNull();
    liste.unmount();
    await monterFiche('boi-claire', 'claire@prospection-a.example');
    await screen.findByLabelText('Pied de message');
    expect(document.body.textContent ?? '').not.toMatch(interdit);
    expect(document.querySelector('input[type="password"]')).toBeNull();
    expect(document.querySelector('input[name*="host" i], input[name*="port" i], input[name*="pass" i]')).toBeNull();
  });
});
