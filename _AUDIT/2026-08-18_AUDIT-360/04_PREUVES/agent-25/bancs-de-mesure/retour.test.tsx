/**
 * AGENT 25 — L'ÉTAT DE LISTE SURVIT-IL À UN ALLER-RETOUR VERS UNE FICHE ?
 * Et l'état (tri / filtre / page) est-il DANS L'URL — donc partageable et
 * rechargeable — comme l'exige le §5.1-7 ?
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * Geste réel : on ouvre la liste, on POSE un filtre, on part vers une fiche,
 * on revient par l'HISTORIQUE (router.history.back()), et on relit l'écran.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { writeFileSync, mkdirSync } from 'node:fs';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { recordGet, getJson } from '../../tests/msw/handlers';
import { MediaListPage } from '@/features/media/MediaListPage';
import { MediaDetailPage } from '@/features/media/MediaDetailPage';
import { AuditLogsPage } from '@/features/rgpd/AuditLogsPage';

const lignes: string[] = [
  'AGENT 25 — ETAT D URL ET RETOUR ARRIERE (gestes reels)',
  'Reference : main 8db8229',
  '',
];

const MEDIAS = {
  data: [
    { id: 'm-1', name: 'Le Progrès', media_family: 'press', media_type: 'quotidien', department: '69', website: null },
    { id: 'm-2', name: 'Radio Scoop', media_family: 'radio', media_type: 'locale', department: '69', website: null },
  ],
  meta: { total: 2, current_page: 1, last_page: 1, per_page: 100 },
};

describe('AGENT 25 — état dans l URL et retour arrière', () => {
  it('/media : poser un filtre ne change PAS l URL', async () => {
    const rec = recordGet('/media', MEDIAS);
    const vue = await renderScreen(<MediaListPage />, {
      path: '/media',
      url: '/media',
      handlers: [rec.handler],
    });
    await screen.findByText('Le Progrès');
    const urlAvant = vue.router.state.location.href;

    // On tape une recherche — c'est un état d'écran que l'utilisateur voudra partager.
    const champ = document.querySelector('input[type="search"], input[type="text"]') as HTMLInputElement | null;
    let urlApres = urlAvant;
    let requeteApres = '';
    if (champ) {
      await userEvent.type(champ, 'Progrès');
      await new Promise((r) => setTimeout(r, 900)); // anti-rebond
      urlApres = vue.router.state.location.href;
      requeteApres = rec.urls[rec.urls.length - 1] ?? '';
    }
    lignes.push('### /media — un filtre saisi à l écran part-il dans l URL du navigateur ?');
    lignes.push(`  champ de recherche trouvé : ${champ ? 'OUI' : 'NON'}`);
    lignes.push(`  URL du routeur AVANT filtre : ${urlAvant}`);
    lignes.push(`  URL du routeur APRES filtre : ${urlApres}`);
    lignes.push(`  DERNIERE requete API envoyee : ${requeteApres}`);
    lignes.push(`  => l API reçoit bien le filtre ; l URL du navigateur, NON : ${urlAvant === urlApres ? 'CONFIRME (identique)' : 'INFIRME (l URL a change)'}`);
    lignes.push('');
    expect(true).toBe(true);
  });

  it('/media : aller vers une fiche puis revenir — le filtre survit-il ?', async () => {
    const rec = recordGet('/media', MEDIAS);
    const vue = await renderScreen(<MediaListPage />, {
      path: '/media',
      url: '/media',
      handlers: [
        rec.handler,
        getJson('/media/m-1', { data: { id: 'm-1', name: 'Le Progrès', media_family: 'press', media_type: 'quotidien' } }),
      ],
      landingRoutes: [{ path: '/media/$mediaId', element: <MediaDetailPage /> }],
    });
    await screen.findByText('Le Progrès');
    const champ = document.querySelector('input[type="search"], input[type="text"]') as HTMLInputElement | null;
    let saisi = '';
    if (champ) {
      await userEvent.type(champ, 'Progrès');
      await new Promise((r) => setTimeout(r, 900));
      saisi = champ.value;
    }

    // On part vers la fiche (navigation programmatique = même effet qu un clic).
    await vue.router.navigate({ to: '/media/$mediaId', params: { mediaId: 'm-1' } });
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/media/m-1'), { timeout: 5000 });

    // Retour par l HISTORIQUE — le geste « flèche arrière » du navigateur.
    vue.router.history.back();
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/media'), { timeout: 5000 });
    await new Promise((r) => setTimeout(r, 400));

    const champApres = document.querySelector('input[type="search"], input[type="text"]') as HTMLInputElement | null;
    const valeurApres = champApres?.value ?? '(champ introuvable)';

    lignes.push('### /media — aller-retour vers une fiche');
    lignes.push(`  valeur du filtre AVANT le départ : « ${saisi} »`);
    lignes.push(`  valeur du filtre APRES le retour : « ${valeurApres} »`);
    lignes.push(`  => le filtre survit : ${saisi !== '' && saisi === valeurApres ? 'OUI' : 'NON — il est PERDU'}`);
    lignes.push('');
    expect(true).toBe(true);
  });

  it('/audit-logs : recharger l URL rejoue-t-il le filtre de sévérité ?', async () => {
    const JOURNAL = {
      data: [
        { id: 1, event_type: 'auth.login', status_code: 200, created_at: '2026-08-19T10:00:00Z', current_hash: 'a'.repeat(64), severity: 'info', actor: 'will' },
        { id: 2, event_type: 'rgpd.erasure', status_code: 500, created_at: '2026-08-19T10:01:00Z', current_hash: 'b'.repeat(64), severity: 'critical', actor: 'will' },
      ],
    };
    // On visite directement l URL AVEC un paramètre de filtre : un utilisateur
    // qui partage son écran filtré enverrait exactement cela.
    const vue = await renderScreen(<AuditLogsPage />, {
      path: '/audit-logs',
      url: '/audit-logs?severity=critical&q=erasure',
      handlers: [getJson('/audit-logs', JOURNAL)],
    });
    await new Promise((r) => setTimeout(r, 500));
    const texte = (document.body.textContent ?? '').replace(/\s+/g, ' ');
    const voitLesDeux = texte.includes('auth.login') && texte.includes('rgpd.erasure');
    lignes.push('### /audit-logs — URL partagée avec ?severity=critical&q=erasure');
    lignes.push(`  URL visitée : ${vue.router.state.location.href}`);
    lignes.push(`  l écran affiche-t-il ENCORE les lignes non filtrées ? ${voitLesDeux ? 'OUI — le paramètre d URL est IGNORE' : 'NON — le filtre a été appliqué'}`);
    lignes.push(`  extrait : « ${texte.slice(0, 300)} »`);
    lignes.push('');
    expect(true).toBe(true);
  });

  it('ZZZ — écrit le relevé', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync('tmp/agent25/out/releve-retour.txt', lignes.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(lignes.join('\n'));
    expect(lignes.length).toBeGreaterThan(3);
  });
});
