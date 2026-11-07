/** PR-C — barre latérale : « Effacements demandés » (pastille) et « Origines des contacts ». */
import { describe, it, expect } from 'vitest';
import { sectionsDeNavigation } from '@/components/layout/Sidebar';
import { CONSOLE_FEATURES_OPEN } from '../msw/handlers';

const ouvert = CONSOLE_FEATURES_OPEN as never;
const items = () => sectionsDeNavigation(ouvert, true);

describe('navigation — origines et effacements', () => {
  it('« Effacements demandés » est dans « À traiter » et porte la pastille demandes_rgpd', () => {
    const s = items().find((x) => x.id === 'a-traiter');
    const e = s?.items.find((i) => i.label === 'Effacements demandés');
    expect(e?.to).toBe('/rgpd/effacements');
    expect(e?.compteur).toBe('demandes_rgpd');
  });

  it('« Demandes RGPD » reste dans « Réglages », sans seconde pastille', () => {
    const r = items().find((x) => x.id === 'reglages');
    const d = r?.items.find((i) => i.label === 'Demandes RGPD');
    expect(d?.to).toBe('/rgpd/requests');
    expect(d?.compteur).toBeUndefined();
  });

  it('« Origines des contacts » dans « Ma base »', () => {
    const s = items().find((x) => x.id === 'ma-base');
    expect(s?.items.find((i) => i.label === 'Origines des contacts')?.to).toBe('/origines');
  });
});
