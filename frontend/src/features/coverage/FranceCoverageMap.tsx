import { useEffect, useRef, useState } from 'react';
import maplibregl, { type Map as MlMap, type StyleSpecification } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

export type CoverageMode = 'visu' | 'search' | 'action';

interface Cell {
  code: string;
  name: string;
  total: number;
  complete?: number;
  partial?: number;
  lat?: number;
  lon?: number;
}

// Sprint 18.9c — on FORCE MINIMAL_STYLE et on ignore VITE_MAPLIBRE_TILES_URL.
// Raison : openfreemap charge un sprite externe qui plante en AbortError au
// remount de FranceCoverageMap (TanStack Router preload double-mount), et
// le tileset externe ajoute ~5 MB + dépend de CSP. Pour MVP V1, fond blanc OK.
// À réactiver quand on aura : (a) MapLibre upgrade qui gère mieux abort sprite,
//                              (b) auto-hosted tileserver, (c) CSP autorisée.
const FORCE_MINIMAL = true;
const TILES_URL = FORCE_MINIMAL ? undefined : import.meta.env['VITE_MAPLIBRE_TILES_URL'];

// Style minimal sans tileset externe : fond blanc + nos polygones GeoJSON.
// Fond blanc (pas gris) pour que les départements gris clair soient bien visibles.
const MINIMAL_STYLE: StyleSpecification = {
  version: 8,
  sources: {},
  layers: [
    {
      id: 'background',
      type: 'background',
      paint: { 'background-color': '#ffffff' },
    },
  ],
};

const LOG = (...args: unknown[]) => console.log('[FranceMap]', ...args);

export function FranceCoverageMap({
  cells,
  mode,
  onZoneClick,
}: {
  cells: Cell[];
  mode: CoverageMode;
  onZoneClick?: (code: string) => void;
}) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<MlMap | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    LOG('useEffect:init — container=', containerRef.current, 'cells.length=', cells.length, 'mode=', mode);
    LOG('env VITE_STRICT_MODE=', import.meta.env['VITE_STRICT_MODE'], 'VITE_MAPLIBRE_TILES_URL=', TILES_URL);
    if (!containerRef.current) {
      LOG('useEffect:abort — no container ref');
      return;
    }
    const container = containerRef.current;
    const rect = container.getBoundingClientRect();
    const cs = window.getComputedStyle(container);
    LOG('container dims — w=', rect.width, 'h=', rect.height, 'display=', cs.display, 'visibility=', cs.visibility, 'position=', cs.position);
    if (rect.width === 0 || rect.height === 0) {
      LOG('⚠️ container has zero dimensions — map will not render');
    }
    let cancelled = false;
    LOG('creating maplibregl.Map…');

    const map = new maplibregl.Map({
      container,
      style: TILES_URL ?? MINIMAL_STYLE,
      center: [2.4, 46.6], // centre métropole
      zoom: 5,
      attributionControl: { compact: true },
    });
    mapRef.current = map;
    LOG('map instance created');

    map.on('styledata', () => LOG('event:styledata — isStyleLoaded=', map.isStyleLoaded()));
    map.on('sourcedata', (e) => {
      const dataType = (e as { dataType?: string }).dataType;
      const sourceId = (e as { sourceId?: string }).sourceId;
      const isSourceLoaded = (e as { isSourceLoaded?: boolean }).isSourceLoaded;
      if (sourceId === 'departements') {
        LOG('event:sourcedata — dataType=', dataType, 'sourceId=', sourceId, 'isSourceLoaded=', isSourceLoaded);
      }
    });
    map.on('dataloading', (e) => {
      const sourceId = (e as { sourceId?: string }).sourceId;
      LOG('event:dataloading — sourceId=', sourceId);
    });
    map.on('idle', () => LOG('event:idle — map fully rendered'));

    map.on('load', () => {
      LOG('event:load — cancelled=', cancelled);
      if (cancelled) return;

      // GeoJSON source des départements.
      // Sprint 18.9c — servi localement via /tiles/admin/departements.geojson
      // (téléchargé via setup script — pas commité au repo pour ne pas alourdir).
      // Surcharge possible via VITE_DEPARTEMENTS_GEOJSON_URL.
      const geojsonUrl = import.meta.env['VITE_DEPARTEMENTS_GEOJSON_URL']
        ?? '/tiles/admin/departements.geojson';
      LOG('adding source — url=', geojsonUrl);

      // Fetch en parallèle pour mesurer perf + détecter 404/CSP/CORS hors-map.
      fetch(geojsonUrl, { credentials: 'same-origin' })
        .then(async (r) => {
          LOG('fetch geojson — status=', r.status, 'content-type=', r.headers.get('content-type'), 'content-length=', r.headers.get('content-length'));
          if (!r.ok) {
            LOG('⚠️ fetch geojson FAILED — status', r.status);
            return;
          }
          const text = await r.text();
          LOG('fetch geojson — body length=', text.length, 'first 80 chars=', text.slice(0, 80));
          try {
            const json = JSON.parse(text) as { features?: unknown[]; type?: string };
            LOG('fetch geojson — parsed OK, type=', json.type, 'features=', json.features?.length);
          } catch (err) {
            LOG('⚠️ fetch geojson — JSON parse FAILED', err);
          }
        })
        .catch((err) => LOG('⚠️ fetch geojson — network error', err));

      map.addSource('departements', {
        type: 'geojson',
        data: geojsonUrl,
        generateId: true,
      });

      map.addLayer({
        id: 'dept-fill',
        type: 'fill',
        source: 'departements',
        paint: {
          'fill-color': [
            'interpolate', ['linear'],
            ['coalesce', ['feature-state', 'total'], 0],
            0,    '#e2e8f0', // gris clair distinct du fond blanc → visible quand cells vide
            10,   '#bae6fd',
            100,  '#38bdf8',
            500,  '#0284c7',
            2000, '#075985',
          ],
          'fill-opacity': 0.85,
        },
      });

      map.addLayer({
        id: 'dept-outline',
        type: 'line',
        source: 'departements',
        paint: { 'line-color': '#475569', 'line-width': 1 },
      });
      LOG('layers added — dept-fill + dept-outline');

      map.on('click', 'dept-fill', (e) => {
        const code = e.features?.[0]?.properties?.['code'] as string | undefined;
        if (code && onZoneClick) onZoneClick(code);
      });
      map.on('mouseenter', 'dept-fill', () => (map.getCanvas().style.cursor = 'pointer'));
      map.on('mouseleave', 'dept-fill', () => (map.getCanvas().style.cursor = ''));
    });

    map.on('error', (e) => {
      const errObj = e?.error as { message?: string; status?: number; url?: string; stack?: string; name?: string } | undefined;
      const msg = errObj?.message ?? 'Erreur carte';
      LOG('⚠️ event:error — message=', msg, 'status=', errObj?.status, 'url=', errObj?.url, 'fullError=', e?.error);
      // AbortError lors d'un remount (TanStack Router preload, navigation rapide) est bénin :
      // la map est remplacée, l'ancienne fetch est annulée. Pas d'erreur utilisateur.
      const isBenignAbort =
        errObj?.name === 'AbortError' ||
        msg.includes('aborted') ||
        msg.includes('AbortError');
      if (!cancelled && !isBenignAbort) setError(msg);
    });

    return () => {
      LOG('useEffect:cleanup — calling map.remove() (cancelled=', cancelled, ')');
      cancelled = true;
      map.remove();
      mapRef.current = null;
    };
  }, [onZoneClick]);

  // Met à jour feature-state quand cells change
  useEffect(() => {
    const map = mapRef.current;
    if (!map || !map.isStyleLoaded()) return;

    for (const cell of cells) {
      // L'id feature-state doit correspondre à promoteId ou generateId — on cherche par code.
      map.querySourceFeatures('departements', { filter: ['==', ['get', 'code'], cell.code] })
        .forEach((f) => {
          if (f.id !== undefined) {
            map.setFeatureState({ source: 'departements', id: f.id }, { total: cell.total });
          }
        });
    }
  }, [cells]);

  return (
    <div className="relative h-[600px] w-full overflow-hidden rounded-xl border border-slate-200">
      <div ref={containerRef} className="absolute inset-0" aria-label="Carte de couverture France" role="region" />
      <div className="pointer-events-none absolute left-3 top-3 rounded-md bg-white/90 px-3 py-1.5 text-xs font-medium shadow-sm">
        Mode : {mode === 'visu' ? 'Visualisation' : mode === 'search' ? 'Recherche' : 'Action'}
      </div>
      {error ? (
        <div className="absolute bottom-3 left-3 rounded-md bg-rose-50 px-3 py-1.5 text-xs text-rose-700 shadow-sm">
          {error}
        </div>
      ) : null}
    </div>
  );
}
