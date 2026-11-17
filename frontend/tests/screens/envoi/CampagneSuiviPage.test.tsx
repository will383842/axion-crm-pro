/**
 * DM2-CAM — suivi d'une campagne : progression, destinataires et état de chacun,
 * test A/B. Décision produit : aucun suivi des clics et aucune ouverture
 * affichée ; les réponses sont l'indicateur principal.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { cleanup, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse, type HttpHandler } from 'msw';
import { CampagneSuiviPage } from '@/features/envoi/campagnes/CampagneSuiviPage';
import { handlers, ficheDemo } from '@/demo/handlers/envoi/campagnes.handlers';
import { api } from '@/demo/outils';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

/** Surcharges d'un test : montées AVANT les handlers de démo (`renderScreen` les empile à l'ouverture), la plus récente d'abord. */
let surcharges: HttpHandler[] = [];
function surcharger(...h: HttpHandler[]) {
  surcharges = [...h, ...surcharges];
}

async function ouvrir(id: string, query = '') {
  return renderScreen(<CampagneSuiviPage />, {
    path: '/envoi/campagnes/$campagneId',
    url: `/envoi/campagnes/${id}${query}`,
    handlers: [...surcharges, ...handlers],
    landingRoutes: ['/envoi/campagnes', '/envoi/campagnes/nouvelle'],
  });
}

describe('CampagneSuiviPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
    surcharges = [];
  });

  it('montre la progression et les réponses comme indicateur principal', async () => {
    await ouvrir('cmp-01');
    expect(await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' })).toBeInTheDocument();
    expect(screen.getByText('En cours')).toBeInTheDocument();
    expect(screen.getByText(/168 envoyés sur 290/)).toBeInTheDocument();
    const reponses = screen.getByTestId('tuile-reponses');
    expect(reponses).toHaveTextContent('9');
    expect(reponses).toHaveTextContent('3 intéressées');
  });

  it('onglet Destinataires : filtrable par état, Yves Roche « refusé : adresse inexistante »', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^Refusés/ }));
    const yves = await screen.findByRole('row', { name: /Yves Roche/ });
    expect(within(yves).getByText('Adresse invalide')).toBeInTheDocument();
    expect(yves).toHaveTextContent('Adresse inexistante');
    // Le filtre écarte les autres états.
    expect(screen.queryByRole('row', { name: /Paul Morin/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^Ont répondu/ }));
    const paul = await screen.findByRole('row', { name: /Paul Morin/ });
    expect(within(paul).getByText('A répondu')).toBeInTheDocument();
    expect(screen.queryByRole('row', { name: /Yves Roche/ })).not.toBeInTheDocument();
  });

  it('chaque destinataire a un état en français', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^En file/ }));
    const lignes = await screen.findAllByRole('row');
    expect(lignes.length).toBeGreaterThan(2);
    expect(screen.getAllByText('En file').length).toBeGreaterThan(1);
  });

  it('une campagne n’affiche jamais d’ouverture, qu’elle ait ou non la case cochée', async () => {
    const user = userEvent.setup();
    for (const id of ['cmp-08', 'cmp-01']) {
      cleanup();
      await ouvrir(id);
      await screen.findByRole('heading', { level: 1 });
      expect(screen.queryByTestId('tuile-ouvertures')).not.toBeInTheDocument();
      await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
      await screen.findAllByRole('row');
      expect(screen.queryByRole('columnheader', { name: /ouverture/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('combobox', { name: /ouverture/i })).not.toBeInTheDocument();
      expect(document.body.textContent).not.toMatch(/ouverture|pixel/i);
      expect(document.body.textContent).not.toMatch(/\bclics?\b/i);
    }
  });

  it('contact exclu (opposé) : aucune ouverture affichée', async () => {
    const cible = demoDb.destinataires.tous().find((d) => d.campagne_id === 'cmp-01' && d.adresse.startsWith('mathilde.bertin@'));
    expect(cible).toBeDefined();
    demoDb.destinataires.modifier(cible!.id, {
      etat: 'exclu',
      motif: 'Inscrit au registre d’opposition',
    });
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1 });
    await user.click(screen.getByRole('tab', { name: /^Destinataires/ }));
    await user.click(await screen.findByRole('button', { name: /^Exclus/ }));
    const ligne = await waitFor(() => {
      const l = screen.queryAllByRole('row').find((r) => /Bertin/.test(r.textContent ?? ''));
      if (!l) throw new Error('Mathilde Bertin absente de la liste des exclus');
      return l;
    });
    expect(ligne.textContent).toMatch(/Exclu/);
    expect(ligne.textContent).not.toMatch(/ouverture|Automatique probable/i);
  });

  it('Test A/B : deux versions et le critère « réponses »', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('tab', { name: 'Test A/B' }));
    expect(await screen.findByText(/Version A/)).toBeInTheDocument();
    expect(screen.getByText(/Version B/)).toBeInTheDocument();
    expect(screen.getByText(/critère : réponses/)).toBeInTheDocument();
  });

  it('Pause depuis le suivi, avec confirmation', async () => {
    const user = userEvent.setup();
    await ouvrir('cmp-01');
    await screen.findByRole('heading', { level: 1, name: 'Organisateurs Rhône-Alpes' });
    await user.click(screen.getByRole('button', { name: 'Mettre en pause' }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Mettre en pause ?' })).toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Mettre en pause' }));
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-01')?.statut).toBe('en_pause'));
  });

  it('campagne inconnue : message clair', async () => {
    await ouvrir('cmp-999');
    expect(await screen.findByText(/introuvable/i)).toBeInTheDocument();
  });

  it('état d’erreur : bouton Réessayer', async () => {
    definirEtatForce('erreur');
    await ouvrir('cmp-01');
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});

// ── L5-D : gestes Lancer / Arrêter, refus, préparation, envoi fermé ────────────

beforeEach(() => {
  demoDb.reinitialiser();
  definirEtatForce(null);
  surcharges = [];
});

const GESTES = /^(Lancer|Arrêter|Mettre en pause|Reprendre|Terminer)$/;
const idDe = (statut: string): string => String(demoDb.campagnes.tous().find((x) => x.statut === statut)!.id);
const visibles = () => screen.queryAllByRole('button').map((b) => b.textContent?.trim() ?? '').filter((t) => GESTES.test(t));
const refuser = (geste: string, code: string) =>
  surcharger(http.post(api(`/envoi/campagnes/:id/${geste}`), () => HttpResponse.json({ error: code, message: 'technique' }, { status: 409 })));

/** Remplace la lecture de la fiche pour y injecter `preparation` ou `envoi_ferme`. */
function fixerFiche(id: string, modif: (f: Record<string, unknown>) => Record<string, unknown>) {
  surcharger(http.get(api(`/envoi/campagnes/${id}`), () => HttpResponse.json(modif(ficheDemo(demoDb.campagnes.trouver(id)!)))));
}

async function pret(id: string) {
  await ouvrir(id);
  await screen.findByRole('heading', { level: 1 });
}

describe('L5-D — boutons selon le statut', () => {
  const ATTENDUS: Array<[string, string[]]> = [
    ['programmee', ['Lancer', 'Arrêter']],
    ['en_cours', ['Mettre en pause', 'Terminer', 'Arrêter']],
    ['en_pause', ['Reprendre', 'Terminer', 'Arrêter']],
    ['terminee', []],
    ['brouillon', []],
    ['a_valider', []],
    ['arretee', []],
  ];
  it.each(ATTENDUS)('%s', async (statut, boutons) => {
    if (statut === 'arretee') demoDb.campagnes.modifier(idDe('terminee'), { statut: 'arretee' });
    await pret(idDe(statut));
    expect(visibles().sort()).toEqual([...boutons].sort());
  });
});

describe('L5-D — Lancer', () => {
  it('double clic : une seule requête ; même clé si on relance après un échec réseau', async () => {
    const user = userEvent.setup();
    const cles: Array<string | null> = [];
    let panne = true;
    surcharger(
      http.post(api('/envoi/campagnes/:id/lancer'), async ({ request, params }) => {
        cles.push(request.headers.get('Idempotency-Key'));
        await new Promise((r) => setTimeout(r, 60)); // le second clic tombe pendant que le premier est en vol
        if (panne) return HttpResponse.error();
        return HttpResponse.json(ficheDemo(demoDb.campagnes.modifier(String(params['id']), { statut: 'en_cours' })!).campagne);
      }),
    );
    await pret(idDe('programmee'));
    await user.dblClick(screen.getByRole('button', { name: 'Lancer' }));
    await screen.findByRole('alert');
    expect(cles).toHaveLength(1);
    expect(cles[0]).toMatch(/^[A-Za-z0-9._-]{8,100}$/);
    panne = false;
    await user.click(screen.getByRole('button', { name: 'Lancer' }));
    await waitFor(() => expect(cles).toHaveLength(2));
    expect(cles[1]).toBe(cles[0]);
  });

  const REFUS: Array<[string, string]> = [
    ['transition_refusee', 'Ce geste n’est pas possible dans l’état actuel'],
    ['sans_sequence', 'n’a pas encore de séquence'],
    ['materialisation_bloquee', 'La préparation de la liste est bloquée'],
    ['aucun_destinataire', 'tous les destinataires ont été écartés'],
    ['frein_arret_general', 'L’arrêt général est actif'],
    ['frein_pause_automatique', 'Une pause automatique est active'],
    ['reprise_refusee_base', 'refusé par la base : rien n’a été changé'],
    ['concurrence', 'Une autre opération touche cette campagne'],
    ['version_perimee', 'a changé depuis votre lecture'],
    ['cle_reutilisee', 'a déjà été envoyée'],
  ];
  it.each(REFUS)('refus 409 « %s » : texte français propre', async (code, texte) => {
    const user = userEvent.setup();
    refuser('lancer', code);
    await pret(idDe('programmee'));
    await user.click(screen.getByRole('button', { name: 'Lancer' }));
    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent(texte);
    expect(alerte.textContent).not.toContain('technique');
  });

  it('materialisation_en_cours : « 20 personnes préparées sur 30 »', async () => {
    const user = userEvent.setup();
    const id = idDe('programmee');
    fixerFiche(id, (f) => ({ ...f, preparation: { etat: 'en_cours', motif: null, inscrits: 20, membres: 30 } }));
    refuser('lancer', 'materialisation_en_cours');
    await pret(id);
    await user.click(screen.getByRole('button', { name: 'Lancer' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('La préparation n’est pas terminée : 20 personnes préparées sur 30.');
  });
});

describe('L5-D — Arrêter et Terminer', () => {
  it('Arrêter sans confirmer : aucune requête ; confirmé : un seul arrêt', async () => {
    const user = userEvent.setup();
    let appels = 0;
    const id = idDe('en_cours');
    surcharger(http.post(api('/envoi/campagnes/:id/arreter'), () => (appels += 1, HttpResponse.json({ ...ficheDemo(demoDb.campagnes.modifier(id, { statut: 'arretee' })!).campagne }))));
    await pret(id);
    await user.click(screen.getByRole('button', { name: 'Arrêter' }));
    const dialogue = await screen.findByRole('dialog');
    await user.click(within(dialogue).getByRole('button', { name: 'Annuler' }));
    expect(appels).toBe(0);
    await user.click(screen.getByRole('button', { name: 'Arrêter' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Arrêter la campagne' }));
    await waitFor(() => expect(appels).toBe(1));
    expect(demoDb.campagnes.trouver(id)?.statut).toBe('arretee');
  });

  it.each(['Arrêter', 'Terminer'])('%s prévient : irréversible, les personnes déjà prévues ne recevront rien', async (geste) => {
    await pret(idDe('en_cours'));
    await userEvent.setup().click(screen.getByRole('button', { name: geste }));
    const dialogue = await screen.findByRole('dialog');
    expect(dialogue).toHaveTextContent(/irréversible/i);
    expect(dialogue).toHaveTextContent('les personnes déjà prévues ne recevront rien');
  });

  it('refus à l’arrêt : le texte du refus est affiché dans la confirmation', async () => {
    const user = userEvent.setup();
    refuser('arreter', 'sans_sequence');
    await pret(idDe('programmee'));
    await user.click(screen.getByRole('button', { name: 'Arrêter' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Arrêter la campagne' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('n’a pas encore de séquence');
  });
});

describe('L5-D — accessibilité', () => {
  it('chaque bouton a un nom accessible ; la confirmation garde le focus et se ferme à Échap', async () => {
    const user = userEvent.setup();
    await pret(idDe('en_cours'));
    for (const b of screen.getAllByRole('button')) expect((b.getAttribute('aria-label') ?? b.textContent ?? '').trim()).not.toBe('');
    await user.click(screen.getByRole('button', { name: 'Arrêter' }));
    const dialogue = await screen.findByRole('dialog');
    expect(dialogue).toHaveAccessibleName('Arrêter définitivement ?');
    await waitFor(() => expect(dialogue.contains(document.activeElement)).toBe(true));
    for (let i = 0; i < 6; i += 1) {
      await user.tab();
      expect(dialogue.contains(document.activeElement)).toBe(true);
    }
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});

describe('L5-D — bandeaux', () => {
  it('préparation en cours : progression ; bloquée : motif ; terminée : rien', async () => {
    const id = idDe('programmee');
    const prep = (etat: string, motif: string | null, inscrits: number) => fixerFiche(id, (f) => ({ ...f, preparation: { etat, motif, inscrits, membres: 30 } }));
    prep('en_cours', null, 20);
    await ouvrir(id);
    expect(await screen.findByTestId('bandeau-preparation')).toHaveTextContent('20 personnes préparées sur 30');
    cleanup();
    prep('bloquee', 'reglage_non_applique', 0);
    await ouvrir(id);
    expect(await screen.findByTestId('bandeau-preparation')).toHaveTextContent(/bloquée/);
    cleanup();
    prep('terminee', null, 30);
    await pret(id);
    expect(screen.queryByTestId('bandeau-preparation')).not.toBeInTheDocument();
  });

  it('« Envoi réel fermé : rien ne part » tant que envoi_ferme est vrai, et seulement alors', async () => {
    const id = idDe('en_cours');
    const ferme = (envoi_ferme: boolean) => fixerFiche(id, (f) => ({ ...f, campagne: { ...(f['campagne'] as object), envoi_ferme } }));
    ferme(true);
    await ouvrir(id);
    expect(await screen.findByText('Envoi réel fermé : rien ne part.')).toBeInTheDocument();
    cleanup();
    ferme(false);
    await pret(id);
    expect(screen.queryByText(/Envoi réel fermé/)).not.toBeInTheDocument();
  });
});

describe('L6-C — cartes des compteurs réels', () => {
  const COMPTEURS = {
    inscrits: 40, envoyes: 31, touches: 25, ouverts: null, suivi_ouvertures: false,
    reponses: 6, rebonds_durs: 2, rebonds_mous: 3, oppositions: 4, arretes_campagne: 0, exclus_preparation: 1,
    issues: { opposition: 4, rebond_dur: 2, reponse: 6, rebond_mou: 3, arretee_autre: 5, terminee: 8, active: 12 },
  };
  const poser = (surplus: Record<string, unknown> = {}) =>
    fixerFiche('cmp-01', (f) => ({ ...f, compteurs: { ...(f['compteurs'] as object), ...COMPTEURS, ...surplus } }));
  const carte = (nom: string) => screen.getByTestId(`carte-${nom}`);

  it('1. affiche les valeurs de l’API, sans les recalculer', async () => {
    poser();
    await pret('cmp-01');
    expect(carte('inscrits')).toHaveTextContent('40');
    expect(carte('envoyes')).toHaveTextContent('31');
    expect(carte('envoyes')).toHaveTextContent('25 personnes touchées');
    expect(carte('reponses')).toHaveTextContent('6');
    expect(carte('rebonds')).toHaveTextContent('2 durs');
    expect(carte('rebonds')).toHaveTextContent('3 mous');
    expect(carte('oppositions')).toHaveTextContent('4');
    expect(carte('arretes')).toHaveTextContent('5');
  });

  it('2. ouverts null ⇒ « non suivi », jamais 0', async () => {
    poser();
    await pret('cmp-01');
    expect(carte('ouverts')).toHaveTextContent('Non suivi');
    expect(carte('ouverts')).not.toHaveTextContent(/\b0\b/);
  });

  it('3. barre des issues : 7 segments, 100 %, libellés lisibles sans couleur', async () => {
    poser();
    await pret('cmp-01');
    const barre = screen.getByTestId('barre-issues');
    const segments = within(barre).getAllByRole('listitem');
    expect(segments).toHaveLength(7);
    expect(segments[0]).toHaveTextContent('Oppositions');
    for (const l of ['Oppositions', 'Rebonds durs', 'Réponses', 'Rebonds mous', 'Arrêtés', 'Terminés', 'En cours']) {
      expect(within(barre).getByText(new RegExp(`^${l}`))).toBeInTheDocument();
    }
    expect(within(barre).getByText('Total : 100 %')).toBeInTheDocument();
    const parts = segments.map((s) => Number(/\((\d+) %\)/.exec(s.textContent ?? '')?.[1]));
    expect(parts.reduce((a, b) => a + b, 0)).toBe(100);
  });

  it('aucune inscription ⇒ pas de barre, pas de « 0 % » trompeur', async () => {
    poser({ inscrits: 0, issues: { opposition: 0, rebond_dur: 0, reponse: 0, rebond_mou: 0, arretee_autre: 0, terminee: 0, active: 0 } });
    await pret('cmp-01');
    expect(screen.queryByTestId('barre-issues')).not.toBeInTheDocument();
    expect(screen.getByText('Aucune personne inscrite pour l’instant.')).toBeInTheDocument();
  });

  it('démo : Σ issues = inscrits pour chaque campagne', () => {
    for (const c of demoDb.campagnes.tous()) {
      const k = (ficheDemo(c) as { compteurs: { inscrits: number; issues: Record<string, number> } }).compteurs;
      expect(Object.values(k.issues).reduce((a, b) => a + b, 0)).toBe(k.inscrits);
    }
  });
});
