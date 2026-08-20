/**
 * D26-003 — QUATRE ONGLETS, UNE VINGTAINE DE COMMANDES, UNE SEULE PRODUIT UN EFFET.
 *
 * ═══ LE DEFAUT MESURE (agent 26, `07-settings-commandes-mortes.txt`) ═══
 *
 *  • ONGLET WORKSPACE — le formulaire poste vers `PUT /workspace`, qui rend
 *    `501` (`WorkspaceController::update` = `notImplemented('3')`). L'utilisateur
 *    ne recoit que `toast.error('Erreur mise a jour')` : le message du serveur
 *    n'est JAMAIS affiche. Le seul formulaire de l'ecran ne peut pas reussir, et
 *    l'ecran ne dit pas pourquoi.
 *  • ONGLET INTEGRATIONS — « Renouveler » et « Configurer » n'ont pas de
 *    `onClick` : 14 boutons (7 x 2) dont le clic ne produit rien. La pastille
 *    `MaskedSecret value="sk-•••••"` est une CONSTANTE LITTERALE : « Afficher »
 *    revele une chaine inventee. Et `status: 'configured'` est ecrit en dur
 *    dans le frontend : l'ecran affirme « Configure » sans avoir lu la moindre
 *    variable d'environnement.
 *  • ONGLET OBSERVABILITE — le champ « DSN Sentry » n'a ni `name`, ni
 *    `onChange`, ni `<form>`, ni bouton : tout ce qui y est saisi est jete. Et
 *    il ne POURRAIT pas marcher : `src/lib/sentry.ts:13` lit
 *    `import.meta.env.VITE_SENTRY_DSN`, une valeur figee AU BUILD.
 *  • ONGLET APPARENCE — `density` est un `useState` local : il ne pilote que la
 *    couleur de ses propres boutons, ne persiste pas, et est perdu au
 *    changement d'onglet.
 *
 * ═══ LA REGLE QUE CETTE GARDE APPLIQUE ═══
 *
 * « Un bouton qui ne fait rien est pire qu'un bouton absent. » Pour chaque
 * commande : soit elle est BRANCHEE, soit l'ecran DIT que la fonction n'est pas
 * disponible. Rien ne reste muet.
 *
 * ⚠️ Les sous-chaines cherchees sont SANS LETTRE ACCENTUEE ni apostrophe
 * typographique — un controle sur du texte francais ne doit pas rougir sur une
 * normalisation Unicode.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SettingsPage } from '@/features/settings/SettingsPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse } from '../msw/handlers';

const WORKSPACE = {
  id: 'ws-1',
  name: 'Axion IA',
  slug: 'axion-ia',
  cost_cap_eur: 50,
  settings: {},
};

/** Le corps EXACT de `ApiController::notImplemented('3')`. */
const CORPS_501 = {
  error: 'not_implemented',
  message: 'Endpoint a implementer en Sprint 3.',
  sprint: '3',
};

function texteEcran(): string {
  return document.body.textContent ?? '';
}

async function monter(handlers = [getJson('/workspace', WORKSPACE)]): Promise<void> {
  await renderScreen(<SettingsPage />, { path: '/settings', handlers });
}

async function ouvrirOnglet(nom: RegExp): Promise<void> {
  const user = userEvent.setup();
  await user.click(await screen.findByRole('tab', { name: nom }));
}

// ---------------------------------------------------------------------------
// 1. Workspace — le seul formulaire de l'ecran
// ---------------------------------------------------------------------------

describe('D26-003 · Workspace — un enregistrement qui echoue doit le DIRE', () => {
  it('TEMOIN — le formulaire est bien la, rempli par la reponse du serveur', async () => {
    await monter();

    const nom = await screen.findByLabelText(/Nom/);
    expect(nom).toHaveValue('Axion IA');
    expect(screen.getByRole('button', { name: /Enregistrer/ })).toBeInTheDocument();
  });

  it('501 : l’ecran affiche le message DU SERVEUR et le code, pas un « Erreur » anonyme', async () => {
    await monter([
      getJson('/workspace', WORKSPACE),
      http.put(apiUrl('/workspace'), () => HttpResponse.json(CORPS_501, { status: 501 })),
    ]);
    const user = userEvent.setup();

    await screen.findByLabelText(/Nom/);
    await user.click(screen.getByRole('button', { name: /Enregistrer/ }));

    // Le message reste A L'ECRAN — un toast qui s'evapore en 4 s ne repond pas
    // a « pourquoi mon plafond de cout n'est-il jamais enregistre ? ».
    await waitFor(() => {
      expect(texteEcran()).toContain('Sprint 3');
    });
    expect(texteEcran()).toContain('code HTTP 501');
    expect(texteEcran()).toContain('Modifications non enregistr');
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });

  it('TEMOIN — quand le serveur accepte, aucun message d’echec ne s’affiche', async () => {
    await monter([
      getJson('/workspace', WORKSPACE),
      http.put(apiUrl('/workspace'), () => HttpResponse.json(WORKSPACE)),
    ]);
    const user = userEvent.setup();

    await screen.findByLabelText(/Nom/);
    await user.click(screen.getByRole('button', { name: /Enregistrer/ }));

    await waitFor(() => {
      expect(texteEcran()).not.toContain('Modifications non enregistr');
    });
    expect(texteEcran()).not.toContain('code HTTP');
  });

  it('500 a la LECTURE : l’onglet ne reste pas « Chargement… » pour l’eternite', async () => {
    await monter([http.get(apiUrl('/workspace'), () => new HttpResponse(null, { status: 500 }))]);

    await waitFor(() => {
      expect(texteEcran()).toContain('serveur est en panne');
    });
    expect(texteEcran()).toContain('code HTTP 500');
    expect(texteEcran()).not.toContain('Chargement');
  });
});

// ---------------------------------------------------------------------------
// 2. Integrations — 14 boutons morts, un secret invente, un etat affirme
// ---------------------------------------------------------------------------

describe('D26-003 · Integrations — ni bouton mort, ni secret invente, ni etat affirme', () => {
  it('TEMOIN — l’onglet reste informatif : les integrations sont toujours listees', async () => {
    await monter();
    await ouvrirOnglet(/Integrations|Intégrations/);

    await waitFor(() => {
      expect(texteEcran()).toContain('INSEE Sirene');
    });
    expect(texteEcran()).toContain('INSEE_API_KEY');
    expect(texteEcran()).toContain('Webshare');
  });

  it('les 14 boutons sans gestionnaire ont disparu', async () => {
    await monter();
    await ouvrirOnglet(/Integrations|Intégrations/);

    await waitFor(() => {
      expect(texteEcran()).toContain('INSEE Sirene');
    });
    expect(screen.queryAllByRole('button', { name: /Renouveler/ })).toHaveLength(0);
    expect(screen.queryAllByRole('button', { name: /Configurer/ })).toHaveLength(0);
  });

  it('le faux secret « sk-••••• » n’est plus revele par personne', async () => {
    await monter();
    await ouvrirOnglet(/Integrations|Intégrations/);

    await waitFor(() => {
      expect(texteEcran()).toContain('INSEE Sirene');
    });
    // La valeur etait une constante litterale du frontend : « Afficher »
    // revelait une chaine que personne n'avait jamais configuree.
    expect(texteEcran()).not.toContain('sk-');
    expect(screen.queryAllByRole('button', { name: /Afficher|Masquer/ })).toHaveLength(0);
  });

  it('l’ecran n’affirme plus « Configure » : il dit qu’il NE PEUT PAS savoir', async () => {
    await monter();
    await ouvrirOnglet(/Integrations|Intégrations/);

    await waitFor(() => {
      expect(texteEcran()).toContain('INSEE Sirene');
    });
    // Aucune route d'API n'expose l'etat des cles : l'affirmer etait une
    // invention du frontend. Le dire est la seule position tenable.
    expect(texteEcran()).toContain('ne peut donc pas dire');
    expect(screen.queryAllByText(/^Configur[ée]$/)).toHaveLength(0);
  });
});

// ---------------------------------------------------------------------------
// 3. Observabilite — un champ sans destination
// ---------------------------------------------------------------------------

describe('D26-003 · Observabilite — plus de champ dont la saisie est jetee', () => {
  it('TEMOIN — les liens d’observabilite sont toujours la', async () => {
    await monter();
    await ouvrirOnglet(/Observabilit/);

    await waitFor(() => {
      expect(texteEcran()).toContain('Grafana');
    });
    expect(screen.getByRole('link', { name: /Prometheus/ })).toBeInTheDocument();
  });

  it('le champ « DSN Sentry » a disparu, et l’ecran dit ou se regle vraiment le DSN', async () => {
    await monter();
    await ouvrirOnglet(/Observabilit/);

    await waitFor(() => {
      expect(texteEcran()).toContain('Grafana');
    });
    expect(screen.queryByPlaceholderText(/sentry\.io/)).toBeNull();
    expect(screen.queryAllByRole('textbox')).toHaveLength(0);
    // `src/lib/sentry.ts` lit `import.meta.env.VITE_SENTRY_DSN` : la valeur est
    // figee au build de l'image, aucune saisie a l'ecran ne peut la changer.
    expect(texteEcran()).toContain('au moment du build');
  });
});

// ---------------------------------------------------------------------------
// 4. Apparence — une densite qui ne densifiait rien
// ---------------------------------------------------------------------------

describe('D26-003 · Apparence — la densite agit et survit', () => {
  it('TEMOIN — au depart, la densite est « confortable »', async () => {
    await monter();
    await ouvrirOnglet(/Apparence/);

    await waitFor(() => {
      expect(document.documentElement.getAttribute('data-density')).toBe('comfortable');
    });
  });

  it('choisir « Compacte » ecrit sur le document ET persiste', async () => {
    await monter();
    await ouvrirOnglet(/Apparence/);
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: /Compacte/ }));

    // 1. L'effet REEL : l'attribut que la feuille de style consulte.
    await waitFor(() => {
      expect(document.documentElement.getAttribute('data-density')).toBe('compact');
    });
    // 2. La persistance : sans elle, le choix meurt au changement d'onglet.
    expect(window.localStorage.getItem('axion-crm:density')).toBe('compact');
  });

  it('le choix est relu au montage suivant', async () => {
    window.localStorage.setItem('axion-crm:density', 'compact');
    await monter();

    await waitFor(() => {
      expect(document.documentElement.getAttribute('data-density')).toBe('compact');
    });
  });
});
