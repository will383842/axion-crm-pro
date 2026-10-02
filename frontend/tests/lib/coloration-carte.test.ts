/**
 * GARDE — coloration de la carte de France (constat prod du 2026-10-02 :
 * tous les départements gris « 0 » alors que Paris compte 615 507 fiches).
 *
 * La coloration ne balaie plus la source : elle écrit l'état de chaque
 * département par son CODE (`promoteId: 'code'`), et attend que la source
 * soit chargée — sans jamais perdre les cellules arrivées avant.
 */
import { describe, expect, it, vi } from 'vitest';

import {
  appliquerTotaux,
  codeDepartement,
  SOURCE_DEPARTEMENTS,
  type CarteColorable,
} from '@/features/coverage/colorationCarte';

function fausseCarte(chargee: boolean) {
  const etat = { chargee };
  const setFeatureState = vi.fn();
  const carte: CarteColorable = {
    getSource: (id) => (id === SOURCE_DEPARTEMENTS ? {} : undefined),
    isSourceLoaded: () => etat.chargee,
    setFeatureState,
  };
  return { carte, setFeatureState, etat };
}

const CELLULES = [
  { code: '75', total: 615507 },
  { code: '2A', total: 1200 },
  { code: '971', total: 40 },
  { code: '1', total: 3 },
];

describe('appliquerTotaux', () => {
  it('source pas encore chargée : rien n’est écrit, la carte réessaiera', () => {
    const { carte, setFeatureState } = fausseCarte(false);
    expect(appliquerTotaux(carte, CELLULES)).toBeNull();
    expect(setFeatureState).not.toHaveBeenCalled();
  });

  it('source sans département du tout : rien n’est écrit', () => {
    const setFeatureState = vi.fn();
    const carte: CarteColorable = {
      getSource: () => undefined,
      isSourceLoaded: () => {
        throw new Error('ne doit pas être appelé sans source');
      },
      setFeatureState,
    };
    expect(appliquerTotaux(carte, CELLULES)).toBeNull();
    expect(setFeatureState).not.toHaveBeenCalled();
  });

  it('cellules arrivées AVANT le chargement : colorées dès que la source est prête', () => {
    const { carte, setFeatureState, etat } = fausseCarte(false);
    expect(appliquerTotaux(carte, CELLULES)).toBeNull();

    etat.chargee = true; // ce que signale `sourcedata`
    const colores = appliquerTotaux(carte, CELLULES);

    expect(colores).toEqual(new Set(['75', '2A', '971', '01']));
    expect(setFeatureState).toHaveBeenCalledTimes(4);
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '75' }, { total: 615507 });
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '2A' }, { total: 1200 });
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '971' }, { total: 40 });
    // « 1 » de l'API devient « 01 », le code du GeoJSON.
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '01' }, { total: 3 });
  });

  it('cellules arrivées APRÈS le chargement : colorées tout de suite, et un département disparu repasse à 0', () => {
    const { carte, setFeatureState } = fausseCarte(true);
    const premiers = appliquerTotaux(carte, CELLULES);
    setFeatureState.mockClear();

    const seconds = appliquerTotaux(carte, [{ code: '75', total: 615600 }], premiers ?? new Set());

    expect(seconds).toEqual(new Set(['75']));
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '75' }, { total: 615600 });
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '2A' }, { total: 0 });
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '971' }, { total: 0 });
    expect(setFeatureState).toHaveBeenCalledWith({ source: 'departements', id: '01' }, { total: 0 });
  });
});

describe('codeDepartement', () => {
  it('suit le format du GeoJSON', () => {
    expect(codeDepartement('1')).toBe('01');
    expect(codeDepartement('01')).toBe('01');
    expect(codeDepartement('2a')).toBe('2A');
    expect(codeDepartement('974')).toBe('974');
  });
});
