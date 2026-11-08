/**
 * LOT 2 UX — LA NAVIGATION CIBLE (audit du 02/10/2026, P1-1, P1-2, P1-3, P2-3).
 *
 * Avant : 27 entrées, dont ONZE sous « Contacts », six outils de développeur
 * au premier niveau (« LLM Router », « Proxies », « Rotations »,
 * « Observabilité »…), des titres de page qui ne disaient pas le mot du menu
 * (« Médias (presse) » / « Médias », « Collectes » / « Nouvelle campagne »), et
 * un fil d'Ariane dont les segments intermédiaires menaient à la page
 * introuvable (« Console CRM », « Relations presse », « LLM »).
 *
 * Ce que ces gardes tiennent :
 *  1. l'arborescence : le tableau de bord, six sections dans l'ordre de la
 *     journée, puis « Technique » en DERNIER ;
 *  2. « Technique » est REPLIÉE à l'arrivée (sauf si la page courante en fait
 *     partie) ;
 *  3. aucune section de travail ne dépasse cinq entrées ;
 *  4. UN mot par écran : le libellé du menu = celui du fil d'Ariane = le titre
 *     de la page ;
 *  5. aucun libellé de menu ne contient de jargon ni d'anglais ;
 *  6. le fil d'Ariane n'offre aucun lien vers un segment sans écran ;
 *  7. (DM0-COQ) les DEUX espaces : les 10 entrées d'Envoi tiennent les mêmes
 *     règles (menu = fil d'Ariane = titre de la page « en attente »).
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { ReactNode } from 'react';

import { termeJargon } from '../helpers/jargon';

let cheminCourant = '/';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, ...reste }: { children?: ReactNode; to: string } & Record<string, unknown>) => (
    <a href={to} {...reste}>
      {children}
    </a>
  ),
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: cheminCourant } }),
}));

vi.mock('@/lib/api', () => ({ api: { get: () => Promise.resolve({ data: {} }) } }));

const { Sidebar, sectionsDeNavigation, SECTION_TECHNIQUE } = await import('@/components/layout/Sidebar');
const { AutoBreadcrumbs, LIBELLES_DE_CHEMIN } = await import('@/components/layout/AutoBreadcrumbs');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');
const { sectionsEnvoi } = await import('@/components/layout/navigationEnvoi');

const TOUT_OUVERT = { console_v2: true, universes: { business: true, vivier: true } };
const CONSOLE_FERMEE = { console_v2: false, universes: { business: true, vivier: false } };

function afficherBarre(chemin: string) {
  cheminCourant = chemin;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, TOUT_OUVERT);
  return render(
    <QueryClientProvider client={client}>
      <Sidebar collapsed={false} onToggleCollapse={() => {}} />
    </QueryClientProvider>,
  );
}

describe('Navigation cible — arborescence', () => {
  it('tableau de bord, les sections dans l’ordre de la journée, puis Technique en dernier', () => {
    const titres = sectionsDeNavigation(TOUT_OUVERT).map((s) => s.title);
    expect(titres).toEqual([
      'Accueil',
      'À traiter',
      'Ma base',
      'Presse',
      'Réseaux',
      'Ciblage',
      'Alimenter la base',
      'Réglages',
      'Technique',
    ]);
    expect(sectionsDeNavigation(TOUT_OUVERT).at(-1)?.id).toBe(SECTION_TECHNIQUE);
  });

  it('Ciblage = choisir à qui s’adresser ; Alimenter la base = faire entrer des entreprises (revue A09)', () => {
    const sections = sectionsDeNavigation(TOUT_OUVERT);
    const chemins = (id: string) => sections.find((s) => s.id === id)?.items.map((i) => i.to);
    expect(chemins('ciblage')).toEqual(['/audiences', '/listes']);
    expect(chemins('alimenter')).toEqual(['/collectes', '/coverage']);
    // Audit UX lot 18 (2026-10-03) : la Roumanie quitte « Ma base » pour la
    // section repliée « Technique ».
    expect(chemins('ma-base')).not.toContain('/international/roumanie');
    expect(chemins('technique')).toContain('/international/roumanie');
  });

  it('aucune section de travail ne dépasse cinq entrées (fini les onze sous « Contacts »)', () => {
    for (const features of [TOUT_OUVERT, CONSOLE_FERMEE]) {
      for (const section of sectionsDeNavigation(features)) {
        if (section.id === SECTION_TECHNIQUE) continue;
        expect(section.items.length, `Section « ${section.title} » : trop d’entrées`).toBeLessThanOrEqual(5);
      }
    }
  });

  it('les outils de développeur sont TOUS rangés sous Technique, renommés en français', () => {
    const technique = sectionsDeNavigation(TOUT_OUVERT).find((s) => s.id === SECTION_TECHNIQUE);
    const chemins = technique?.items.map((i) => i.to) ?? [];
    for (const outil of ['/llm/router', '/llm/proxy-providers', '/llm/rotations', '/admin/observability', '/rgpd/ai-act', '/audit-logs', '/scraper-runs']) {
      expect(chemins, `${outil} doit vivre sous « Technique »`).toContain(outil);
    }
    // Et nulle part ailleurs.
    const ailleurs = sectionsDeNavigation(TOUT_OUVERT)
      .filter((s) => s.id !== SECTION_TECHNIQUE)
      .flatMap((s) => s.items.map((i) => i.to));
    for (const outil of chemins) expect(ailleurs).not.toContain(outil);
  });

  it('aucun libellé du menu ne contient de jargon ni d’anglais', () => {
    for (const section of sectionsDeNavigation(TOUT_OUVERT)) {
      expect(termeJargon(section.title), section.title).toBeNull();
      for (const item of section.items) {
        expect(termeJargon(item.label), `« ${item.label} »`).toBeNull();
      }
    }
  });
});

describe('Navigation cible — « Technique » repliée', () => {
  it('à l’arrivée sur le tableau de bord, la section Technique est FERMÉE', () => {
    afficherBarre('/');
    const bouton = screen.getByRole('button', { name: 'Technique' });
    expect(bouton).toHaveAttribute('aria-expanded', 'false');
    const liste = document.getElementById(`nav-section-${SECTION_TECHNIQUE}`);
    expect(liste?.className).toContain('hidden');
  });

  it('elle s’ouvre seule quand la page courante en fait partie (on ne perd pas l’utilisateur)', () => {
    afficherBarre('/llm/router');
    expect(screen.getByRole('button', { name: 'Technique' })).toHaveAttribute('aria-expanded', 'true');
    const liste = document.getElementById(`nav-section-${SECTION_TECHNIQUE}`) as HTMLElement;
    expect(within(liste).getByText('Moteurs d’IA')).toBeInTheDocument();
  });

  it('le tableau de bord reste visible sans en-tête de section à ouvrir', () => {
    afficherBarre('/companies');
    expect(screen.getByText('Tableau de bord')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Accueil' })).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// Un mot par écran : menu = fil d'Ariane = titre de page
// ---------------------------------------------------------------------------

const racine = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(racine, '../../src');

/** L'écran de chaque entrée de menu. Une entrée nouvelle sans écran ici fait rougir. */
const ECRAN_DE: Record<string, string> = {
  '/': 'features/dashboard/DashboardPage.tsx',
  '/doublons': 'features/doublons/DoublonsPage.tsx',
  '/rgpd/effacements': 'features/rgpd/EffacementsDemandesPage.tsx',
  '/origines': 'features/origines/OriginesPage.tsx',
  '/console/arbitrage': 'features/crm-console/ArbitragePage.tsx',
  '/companies': 'features/companies/CompaniesListPage.tsx',
  '/console/contacts': 'features/crm-console/ContactsHubPage.tsx',
  '/contacts': 'features/contacts/ContactsListPage.tsx',
  '/console/lettre-et-guide': 'features/crm-console/PersonnesPage.tsx',
  '/console/vivier': 'features/crm-console/CandidatesPage.tsx',
  '/media': 'features/media/MediaListPage.tsx',
  '/journalists': 'features/media/JournalistsListPage.tsx',
  '/presse/envois': 'features/media/EnvoisPressePage.tsx',
  '/federations': 'features/federations/FederationsPage.tsx',
  '/evenements': 'features/evenements/EvenementsPage.tsx',
  '/audiences': 'features/audiences/AudiencesListPage.tsx',
  '/listes': 'features/listes/ListesManuellesPage.tsx',
  '/coverage': 'features/coverage/CoveragePage.tsx',
  '/collectes': 'features/collectes/CollectesListePage.tsx',
  '/settings': 'features/settings/SettingsPage.tsx',
  '/users': 'features/users/UsersPage.tsx',
  '/tags': 'features/tags/TagsManagerPage.tsx',
  '/rgpd/requests': 'features/rgpd/RgpdRequestsPage.tsx',
  '/scraper-runs': 'features/scraping/ScraperRunsPage.tsx',
  '/admin/observability': 'features/observability/ObservabilityPage.tsx',
  '/llm/router': 'features/llm/LlmRouterPage.tsx',
  '/llm/proxy-providers': 'features/llm/ProxyProvidersPage.tsx',
  '/llm/rotations': 'features/llm/RotationsPage.tsx',
  '/rgpd/ai-act': 'features/rgpd/AiActRegisterPage.tsx',
  '/audit-logs': 'features/rgpd/AuditLogsPage.tsx',
  '/international/roumanie': 'features/international/RoumaniePage.tsx',
};

describe('Navigation cible — un mot par écran', () => {
  const entrees = [TOUT_OUVERT, CONSOLE_FERMEE].flatMap((f) => sectionsDeNavigation(f).flatMap((s) => s.items));

  it('le fil d’Ariane dit le MÊME mot que le menu', () => {
    for (const item of entrees) {
      expect(LIBELLES_DE_CHEMIN[item.to], `Fil d’Ariane de ${item.to}`).toBe(item.label);
    }
  });

  it('le titre de la page dit le MÊME mot que le menu', () => {
    for (const item of entrees) {
      const fichier = ECRAN_DE[item.to];
      expect(fichier, `Entrée de menu ${item.to} sans écran déclaré dans ECRAN_DE`).toBeDefined();
      const chemin = path.join(SRC, fichier as string);
      expect(existsSync(chemin), chemin).toBe(true);
      const source = readFileSync(chemin, 'utf8');
      // `title="Libellé"` (PageHeader) ou le texte d'un en-tête maison (carte).
      const titreExact = source.includes(`title="${item.label}"`) || new RegExp(`>\\s*${item.label}\\s*<`).test(source);
      expect(titreExact, `Le titre de ${fichier} ne dit pas « ${item.label} » comme le menu`).toBe(true);
    }
  });
});

describe('Navigation cible — fil d’Ariane sans lien mort', () => {
  function afficherFil(chemin: string) {
    cheminCourant = chemin;
    return render(<AutoBreadcrumbs />);
  }

  it.each([
    ['/console/contacts', '/console', 'Console CRM'],
    ['/presse/envois', '/presse', 'Relations presse'],
    ['/llm/router', '/llm', 'LLM'],
    ['/admin/observability', '/admin', 'Administration'],
  ])('sur %s, aucun lien vers %s ni mot « %s »', (chemin, segment, ancien) => {
    const { container } = afficherFil(chemin);
    const liens = [...container.querySelectorAll('a')].map((a) => a.getAttribute('href'));
    expect(liens).not.toContain(segment);
    expect(container.textContent).not.toContain(ancien);
    expect(container.textContent).toContain(LIBELLES_DE_CHEMIN[chemin]);
  });

  it('une fiche s’appelle « Fiche », pas « #a1b2c3d4 »', () => {
    const { container } = afficherFil('/companies/a1b2c3d4-0000-0000-0000-000000000000');
    expect(container.textContent).toContain('Fiche');
    expect(container.textContent).not.toContain('#a1b2c3d4');
  });
});

// ---------------------------------------------------------------------------
// DM0-COQ — l'espace Envoi tient les mêmes règles
// ---------------------------------------------------------------------------

const LES_DEUX = { ...CONSOLE_FERMEE, produits: { crm: 'actif', envoi: 'actif' } } as const;
const ENVOI_SEUL = { ...CONSOLE_FERMEE, produits: { crm: 'absent', envoi: 'actif' } } as const;

/** L'écran « en attente » de chaque entrée d'Envoi (plan 30 § 4.2). */
const ECRAN_ENVOI_DE: Record<string, string> = {
  '/envoi/campagnes': 'features/envoi/campagnes/CampagnesPage.tsx',
  '/envoi/reponses': 'features/envoi/reponses/ReponsesPage.tsx',
  '/envoi/boites': 'features/envoi/infrastructure/BoitesPage.tsx',
  '/envoi/domaines': 'features/envoi/infrastructure/DomainesPage.tsx',
  '/envoi/serveurs': 'features/envoi/infrastructure/ServeursPage.tsx',
  '/envoi/chauffe': 'features/envoi/infrastructure/ChauffePage.tsx',
  '/envoi/sante': 'features/envoi/pilotage/SantePage.tsx',
  '/envoi/desinscriptions': 'features/envoi/conformite/DesinscriptionsPage.tsx',
  '/envoi/alertes': 'features/envoi/conformite/AlertesEnvoiPage.tsx',
  '/envoi/reglages': 'features/envoi/reglages/ReglagesEnvoiPage.tsx',
  // Envoi seul : les réglages du socle restent atteignables (ADR-12).
  '/users': 'features/users/UsersPage.tsx',
  '/rgpd/requests': 'features/rgpd/RgpdRequestsPage.tsx',
  '/audit-logs': 'features/rgpd/AuditLogsPage.tsx',
};

describe('Navigation cible — l’espace Envoi (DM0-COQ)', () => {
  it('les 10 entrées des maquettes validées, dans l’ordre', () => {
    const entrees = sectionsEnvoi(LES_DEUX).flatMap((s) => s.items);
    expect(entrees.map((i) => [i.to, i.label])).toEqual([
      ['/envoi/campagnes', 'Campagnes'],
      ['/envoi/reponses', 'Réponses'],
      ['/envoi/boites', 'Boîtes d’envoi'],
      ['/envoi/domaines', 'Domaines'],
      ['/envoi/serveurs', 'Serveurs et adresses IP'],
      ['/envoi/chauffe', 'Chauffe'],
      ['/envoi/sante', 'Santé des envois'],
      ['/envoi/desinscriptions', 'Désinscriptions'],
      ['/envoi/alertes', 'Alertes d’envoi'],
      ['/envoi/reglages', 'Réglages d’envoi'],
    ]);
  });

  it('Envoi seul : les réglages du socle (équipe, RGPD, journal) restent dans la barre', () => {
    const chemins = sectionsEnvoi(ENVOI_SEUL).flatMap((s) => s.items.map((i) => i.to));
    expect(chemins).toEqual(expect.arrayContaining(['/users', '/rgpd/requests', '/audit-logs']));
  });

  it('le menu du CRM ne change pas quand Envoi est actif (le menu de Will reste à l’identique)', () => {
    const avec = sectionsDeNavigation(LES_DEUX).map((s) => [s.id, s.items.map((i) => i.to)]);
    const sans = sectionsDeNavigation(CONSOLE_FERMEE).map((s) => [s.id, s.items.map((i) => i.to)]);
    expect(avec).toEqual(sans);
  });

  const entreesEnvoi = [LES_DEUX, ENVOI_SEUL].flatMap((f) => sectionsEnvoi(f).flatMap((s) => s.items));

  it('aucun libellé d’Envoi ne contient de jargon ni d’anglais', () => {
    for (const item of entreesEnvoi) expect(termeJargon(item.label), `« ${item.label} »`).toBeNull();
  });

  it('le fil d’Ariane dit le MÊME mot que le menu', () => {
    for (const item of entreesEnvoi) {
      expect(LIBELLES_DE_CHEMIN[item.to], `Fil d’Ariane de ${item.to}`).toBe(item.label);
    }
  });

  it('le titre de la page dit le MÊME mot que le menu', () => {
    for (const item of entreesEnvoi) {
      const fichier = ECRAN_ENVOI_DE[item.to];
      expect(fichier, `Entrée d’Envoi ${item.to} sans écran déclaré dans ECRAN_ENVOI_DE`).toBeDefined();
      const chemin = path.join(SRC, fichier as string);
      expect(existsSync(chemin), chemin).toBe(true);
      const source = readFileSync(chemin, 'utf8');
      const titreExact = source.includes(`title="${item.label}"`) || new RegExp(`>\\s*${item.label}\\s*<`).test(source);
      expect(titreExact, `Le titre de ${fichier} ne dit pas « ${item.label} » comme le menu`).toBe(true);
    }
  });

  it('dans Envoi, le fil d’Ariane part d’« Envoi », pas de l’accueil du CRM', () => {
    cheminCourant = '/envoi/domaines';
    const { container } = render(<AutoBreadcrumbs />);
    expect(container.textContent).toContain('Envoi');
    expect(container.textContent).toContain('Domaines');
    expect(container.textContent).not.toContain('Accueil');
    const liens = [...container.querySelectorAll('a')].map((a) => a.getAttribute('href'));
    expect(liens).not.toContain('/');
    expect(liens).not.toContain('/envoi');
  });
});
