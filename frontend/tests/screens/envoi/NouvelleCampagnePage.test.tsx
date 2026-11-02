/**
 * DM2-ASS — l'assistant « Nouvelle campagne » en 5 étapes, contre le faux
 * serveur de démo (mêmes handlers que la démo et l'e2e).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { handlers } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';
import { IDENTIFIANTS_DEMO } from '@/demo/donnees';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/nouvelle';

async function ouvrir(url = `${CHEMIN}?etiquette=salon-artisans-2026`) {
  const view = await renderScreen(<NouvelleCampagnePage />, {
    path: CHEMIN,
    url,
    handlers,
    landingRoutes: ['/envoi/campagnes/$campagneId'],
  });
  await screen.findByRole('heading', { level: 1, name: 'Nouvelle campagne' });
  return { ...view, user: userEvent.setup() };
}

type Utilisateur = ReturnType<typeof userEvent.setup>;

async function continuer(user: Utilisateur, nom: RegExp) {
  const bouton = await screen.findByRole('button', { name: nom });
  await waitFor(() => { expect(bouton).toBeEnabled(); });
  await user.click(bouton);
}

describe('NouvelleCampagnePage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('?etiquette=salon-artisans-2026 pré-remplit la cible, avec ses joignables', async () => {
    await ouvrir();
    const radio = await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    expect(radio).toBeChecked();
    // 434 adresses, 46 retirées d'office : 388 joignables (maquette validée).
    expect(await screen.findByText(/46 adresses retirées d’office/)).toBeVisible();
    expect(screen.getByText('388')).toBeVisible();
  });

  it('un champ obligatoire vide bloque l’étape 2, puis une valeur de repli la débloque', async () => {
    demoDb.messagesTypes.ajouter({
      id: 'msg-test-rdv',
      nom: 'Prise de rendez-vous',
      version_courante: 1,
      utilise_par: 0,
      versions: [{ numero: 1, objet: 'Un rendez-vous ?', objet_b: null, corps: 'Bonjour {prénom},\n\nPrenez rendez-vous : {lien_rdv}\n\nClaire', modifie_le: '2027-01-11T10:00:00.000Z' }],
    });
    const { user } = await ouvrir();
    await continuer(user, /Continuer : message/);
    await user.selectOptions(await screen.findByLabelText('Message 1'), 'msg-test-rdv');
    await continuer(user, /Continuer : quand/);

    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent(/lien_rdv/);
    expect(screen.queryByRole('heading', { name: 'Quand et par où ?' })).not.toBeInTheDocument();

    await user.type(screen.getByLabelText(/Valeur de repli : \{lien_rdv\}/), 'https://rdv.example/axion');
    await continuer(user, /Continuer : quand/);
    expect(await screen.findByRole('heading', { name: 'Quand et par où ?' })).toBeVisible();
  });

  it('« Demain 9:00 » règle le départ', async () => {
    const { user } = await ouvrir();
    await continuer(user, /Continuer : message/);
    await continuer(user, /Continuer : quand/);
    await user.click(await screen.findByRole('button', { name: 'Demain 9:00' }));
    expect(screen.getByLabelText('Jour')).toHaveValue('2027-01-13');
    expect(screen.getByLabelText('Heure')).toHaveValue('09:00');
  });

  it('un contrôle rouge empêche l’étape 5', async () => {
    const { user } = await ouvrir();
    await continuer(user, /Continuer : message/);
    await continuer(user, /Continuer : quand/);
    await user.click(await screen.findByRole('button', { name: 'Demain 9:00' }));
    // Aucune boîte choisie : le contrôle « Boîtes prêtes » est bloquant.
    for (const boite of await screen.findAllByRole('checkbox', { name: /@prospection-/ })) {
      if ((boite as HTMLInputElement).checked) await user.click(boite);
    }
    await continuer(user, /Continuer : vérification/);
    const controles = await screen.findByRole('list', { name: 'Contrôles avant lancement' });
    expect(await within(controles).findByText(/Boîtes prêtes/)).toBeVisible();
    expect(await screen.findByText(/à corriger/i)).toBeVisible();
    expect(screen.getByRole('button', { name: /Continuer : lancement/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /Lancement/ })).toBeDisabled();
  });

  it('le code faux est refusé ; le bon programme la campagne ; « Annuler » la remet en brouillon', async () => {
    const { user } = await ouvrir();
    await continuer(user, /Continuer : message/);
    await continuer(user, /Continuer : quand/);
    await user.click(await screen.findByRole('button', { name: 'Demain 9:00' }));
    await continuer(user, /Continuer : vérification/);
    await continuer(user, /Continuer : lancement/);

    await user.click(await screen.findByRole('button', { name: 'Envoyer le code par e-mail' }));
    const champ = await screen.findByLabelText('Code reçu par e-mail');

    await user.type(champ, '000000');
    await user.click(screen.getByRole('button', { name: 'Valider et programmer' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/code/i);
    expect(demoDb.campagnes.filtrer((c) => c.nom.includes('salon-artisans-2026'))).toHaveLength(0);

    await user.clear(champ);
    await user.type(champ, IDENTIFIANTS_DEMO.code);
    await user.click(screen.getByRole('button', { name: 'Valider et programmer' }));
    expect(await screen.findByText(/Campagne programmée/)).toBeVisible();
    const creees = demoDb.campagnes.filtrer((c) => c.cible.reference === 'salon-artisans-2026' && c.statut === 'programmee');
    expect(creees.length).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: /Annuler la validation/ }));
    expect(await screen.findByText(/remise en brouillon/)).toBeVisible();
    const campagneId = creees[creees.length - 1]!.id;
    expect(demoDb.campagnes.trouver(campagneId)?.statut).toBe('brouillon');
  });

  it('la case « Suivre les ouvertures » est décochée par défaut, avec une phrase d’information claire', async () => {
    const { user } = await ouvrir();
    await continuer(user, /Continuer : message/);
    const interrupteur = await screen.findByRole('switch', { name: /Suivre les ouvertures/ });
    expect(interrupteur).not.toBeChecked();
    expect(screen.getByText(/estimation/i)).toBeVisible();
    expect(screen.getByText(/jamais suivis/i)).toBeVisible();
  });

  it('sans cible dans l’espace : état vide', async () => {
    definirEtatForce('vide');
    try {
      await renderScreen(<NouvelleCampagnePage />, { path: CHEMIN, url: CHEMIN, handlers });
      expect(await screen.findByText('Rien à cibler')).toBeVisible();
    } finally {
      definirEtatForce(null);
    }
  });
});
