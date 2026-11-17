/**
 * B8-B — liste des campagnes : « Bloquée, pourquoi ». Le serveur dit `blocage`
 * (B8-A) ; la liste le montre en texte (lisible sans couleur) avec un lien vers
 * le suivi. Aucun code brut à l'écran. Filtre « Bloquées » : page chargée seulement.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TableauCampagnes } from '@/features/envoi/campagnes/composants/TableauCampagnes';
import { PHRASE_BLOCAGE_GENERIQUE, PHRASES_BLOCAGE } from '@/features/envoi/campagnes/composants/libelles';
import { handlers } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import type { Campagne } from '@/features/envoi/campagnes/api';
import { renderScreen } from '../../helpers/renderScreen';

type Blocage = NonNullable<Campagne['blocage']>;
const CODES: Array<Blocage['code']> = [
  'reglage_non_applique',
  'preparation_bloquee',
  'aucun_destinataire',
  'frein_arret_general',
  'frein_pause_automatique',
  'sequence_en_pause',
];

function campagne(id: string, nom: string, blocage: Campagne['blocage']): Campagne {
  const base = demoDb.campagnes.tous().find((c) => c.statut === 'en_cours')!;
  return { ...base, id, nom, statut: 'en_cours', envoi_ferme: false, version: 1, blocage } as unknown as Campagne;
}

async function monter(liste: Campagne[]) {
  return renderScreen(<TableauCampagnes campagnes={liste} maintenant="2026-01-12T10:00:00.000Z" />, {
    path: '/envoi/campagnes',
    url: '/envoi/campagnes',
    handlers,
    landingRoutes: ['/envoi/campagnes/$campagneId', '/envoi/campagnes/nouvelle'],
  });
}

const ligne = (nom: string) => screen.getByRole('row', { name: new RegExp(nom) });

describe('B8-B — liste : pastille « Bloquée » et raison en texte', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it.each(CODES)('code %s : pastille « Bloquée », phrase courte, jamais le code brut', async (code) => {
    await monter([campagne('c-1', 'Camp un', { code, message: 'texte du serveur' })]);
    const l = ligne('Camp un');
    expect(within(l).getByText('Bloquée')).toBeInTheDocument();
    expect(l).toHaveTextContent(PHRASES_BLOCAGE[code]!);
    expect(l).not.toHaveTextContent(code);
  });

  it('chaque code a sa propre phrase (7 libellés exigés : 6 codes connus + repli générique)', () => {
    const phrases = CODES.map((c) => PHRASES_BLOCAGE[c]);
    expect(new Set(phrases).size).toBe(CODES.length);
    expect(PHRASE_BLOCAGE_GENERIQUE).not.toBe('');
    expect(phrases).not.toContain(PHRASE_BLOCAGE_GENERIQUE);
  });

  it('blocage nul : aucune pastille ni phrase', async () => {
    await monter([campagne('c-1', 'Camp un', null)]);
    expect(screen.queryByText('Bloquée')).not.toBeInTheDocument();
    expect(screen.queryByTestId('blocage-ligne')).not.toBeInTheDocument();
  });

  it('motif inconnu : phrase générique, jamais le code brut', async () => {
    await monter([campagne('c-1', 'Camp un', { code: 'code_du_futur' as Blocage['code'], message: 'x' })]);
    const l = ligne('Camp un');
    expect(l).toHaveTextContent('Bloquée');
    expect(l).toHaveTextContent(PHRASE_BLOCAGE_GENERIQUE);
    expect(l).not.toHaveTextContent('code_du_futur');
  });

  it('un lien mène au suivi de la campagne', async () => {
    await monter([campagne('c-7', 'Camp sept', { code: 'sequence_en_pause', message: 'm' })]);
    const lien = within(ligne('Camp sept')).getByRole('link', { name: /Voir pourquoi.*Camp sept/ });
    expect(lien).toHaveAttribute('href', '/envoi/campagnes/c-7');
  });

  it('lisible sans couleur : le texte « Bloquée » est dans le contenu, pas seulement dans une teinte', async () => {
    await monter([campagne('c-1', 'Camp un', { code: 'aucun_destinataire', message: 'm' })]);
    const zone = within(ligne('Camp un')).getByTestId('blocage-ligne');
    expect(zone.textContent).toMatch(/Bloquée/);
    expect(zone.textContent).toContain(PHRASES_BLOCAGE.aucun_destinataire!);
  });
});

describe('B8-B — filtre « Bloquées » (page chargée seulement)', () => {
  it('ne garde que les campagnes bloquées, puis rend la liste complète', async () => {
    const user = userEvent.setup();
    await monter([
      campagne('c-1', 'Libre', null),
      campagne('c-2', 'Coincée', { code: 'frein_arret_general', message: 'm' }),
      campagne('c-3', 'Autre libre', null),
    ]);
    const bouton = screen.getByRole('button', { name: /Bloquées/ });
    expect(bouton).toHaveAttribute('aria-pressed', 'false');
    expect(bouton).toHaveTextContent('1');
    await user.click(bouton);
    expect(bouton).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('row', { name: /Coincée/ })).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /Libre/ })).not.toBeInTheDocument();
    await user.click(bouton);
    expect(screen.getByRole('row', { name: /Autre libre/ })).toBeInTheDocument();
  });

  it('filtre actif sans aucune bloquée : message clair, pas de tableau vide muet', async () => {
    const user = userEvent.setup();
    await monter([campagne('c-1', 'Libre', null)]);
    await user.click(screen.getByRole('button', { name: /Bloquées/ }));
    expect(screen.getByRole('status')).toHaveTextContent('Aucune campagne bloquée sur cette page.');
  });
});
