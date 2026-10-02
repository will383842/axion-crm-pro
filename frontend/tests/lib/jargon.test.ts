/**
 * GARDE ANTI-JARGON — la console parle FRANÇAIS COURANT, partout.
 *
 * Audit UX du 02/10/2026 (P1-7 et audit visuel en production) : « Pending »,
 * « Refresh », « Lancer scraping », « Monitoring des jobs de scraping »,
 * « Plafond LLM mensuel », « Slug (URL) », « Workspace », « Custom »,
 * « Run #12 », « Timeline », « touchpoints »… Le propriétaire n'est pas
 * technicien : ces mots ont été remplacés, et cette garde ROUGIT si l'un d'eux
 * revient dans un texte AFFICHÉ.
 *
 * Périmètre et méthode : `tests/helpers/jargon.ts` (texte JSX, attributs JSX
 * affichés, propriétés d'affichage, tables de libellés, `toast`, `confirm`).
 * Les valeurs techniques (`status: 'pending'`, `queryKey: ['jobs']`,
 * `to="/scraper-runs"`) ne sont PAS lues : ce ne sont pas des textes.
 *
 * ═══ EXCEPTIONS ═══
 *
 * Une exception se déclare dans `EXCEPTIONS`, avec le fichier, le texte EXACT
 * et la raison. La section « Technique » du menu (moteurs d'IA, serveurs
 * relais, santé du système…) a été traduite elle aussi : aucune exception
 * n'est nécessaire à ce jour. Si un écran technique DOIT montrer un terme
 * (nom de produit, identifiant de configuration), on l'ajoute ici plutôt que
 * d'affaiblir la liste.
 */
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { chercherJargon, remplacementDe, termeJargon, textesAffichesDuModule } from '../helpers/jargon';

interface Exception {
  fichier: string;
  texte: string;
  raison: string;
}

const EXCEPTIONS: Exception[] = [];

function trouverRacineSrc(): string {
  let courant = process.cwd();
  for (let i = 0; i < 6; i += 1) {
    const candidat = join(courant, 'src');
    if (existsSync(join(candidat, 'locales', 'fr.json'))) return candidat;
    const parent = dirname(courant);
    if (parent === courant) break;
    courant = parent;
  }
  return join(process.cwd(), 'frontend', 'src');
}

describe('Détecteur de jargon — témoins', () => {
  // Les textes RÉELLEMENT vus en production le 02/10/2026.
  it.each([
    'Pending',
    'Refresh',
    'Dernier refresh : jamais',
    'Lancer scraping →',
    'Monitoring des jobs de scraping en temps réel.',
    'Plafond LLM mensuel (€)',
    'Slug (URL)',
    'Workspace',
    'Custom',
    'Run #12',
    'Annuler ce run ?',
    'Proxies',
    'Preview live',
    'Timeline',
    'Aucun touchpoint enregistré.',
    'Le dashboard affiche vos KPIs',
    'Kill-switch automatique LLM quand atteint',
    'Nouveau tag',
    'Payload (brut)',
    'Opt-out',
    '4 rôles RBAC',
    'Journal append-only',
    'Live',
    'Critères (JSON brut)',
  ])('repère « %s »', (texte) => {
    expect(termeJargon(texte)).not.toBeNull();
  });

  it.each([
    'En attente',
    'Actualiser',
    'Récupérer des entreprises',
    'Budget IA mensuel (€)',
    'Mon entreprise',
    'Personnalisées',
    'Historique des collectes',
    'Serveurs relais',
    'Étiquettes',
    'Brunch du réseau',
    'Jobboard ? non : « offres d’emploi »',
    'Tagliatelle',
    'Collecte n° 12',
    'Moteurs d’IA',
  ])('ne confond pas « %s » avec du jargon', (texte) => {
    expect(termeJargon(texte)).toBeNull();
  });

  it('lit ce qui est affiché, et SEULEMENT cela', () => {
    const source = `
      const STATUTS = [{ value: 'pending', label: 'Pending' }];
      const cle = ['scraper-runs', 'jobs'];
      export function Ecran() {
        return (
          <div className="refresh" data-x="workspace">
            <Link to="/scraper-runs">Lancer scraping</Link>
            <input placeholder="Slug" />
            {ok ? 'Refresh' : 'Rien'}
          </div>
        );
      }
      toast.error('Scrape échoué');
    `;
    const lus = textesAffichesDuModule('x.tsx', source).map((t) => t.texte.trim());
    expect(lus).toEqual(expect.arrayContaining(['Pending', 'Lancer scraping', 'Slug', 'Refresh', 'Scrape échoué']));
    expect(lus).not.toContain('pending');
    expect(lus).not.toContain('scraper-runs');
    expect(lus).not.toContain('jobs');
    expect(lus).not.toContain('refresh');
    expect(lus).not.toContain('workspace');
    expect(lus).not.toContain('/scraper-runs');
  });
});

describe('Vocabulaire — aucun texte affiché ne contient de jargon', () => {
  it('aucune occurrence hors exceptions documentées', () => {
    const trouvees = chercherJargon(trouverRacineSrc()).filter(
      (o) => !EXCEPTIONS.some((e) => e.fichier === o.fichier && e.texte === o.texte),
    );
    expect(
      trouvees.map((o) => `${o.fichier}:${o.ligne} [${o.terme} → ${remplacementDe(o.terme)}] ${o.texte}`),
      'Jargon ou anglais dans un texte affiché. La console parle français courant ' +
        '(propriétaire non technicien). GESTE : remplacer par le mot proposé entre ' +
        'crochets. Terme légitime avéré seulement : le déclarer dans EXCEPTIONS.',
    ).toEqual([]);
  });

  it('chaque exception déclarée existe encore (pas d’exception morte)', () => {
    const toutes = chercherJargon(trouverRacineSrc());
    for (const e of EXCEPTIONS) {
      expect(
        toutes.some((o) => o.fichier === e.fichier && o.texte === e.texte),
        `Exception morte : « ${e.texte} » (${e.fichier}) n’existe plus. La retirer.`,
      ).toBe(true);
    }
  });
});
