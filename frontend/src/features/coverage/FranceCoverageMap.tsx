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

const TILES_URL = import.meta.env['VITE_MAPLIBRE_TILES_URL'] ?? 'https://tiles.openfreemap.org/styles/liberty';

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
    if (!containerRef.current) return;
    let cancelled = false;

    const map = new maplibregl.Map({
      container: containerRef.current,
      style: TILES_URL as string | StyleSpecification,
      center: [2.4, 46.6], // centre métropole
      zoom: 5,
      attributionControl: { compact: true },
    });
    mapRef.current = map;

    map.on('load', () => {
      if (cancelled) return;

      // GeoJSON source des départements.
      // Sprint 18.9c — servi localement via /tiles/admin/departements.geojson
      // (téléchargé via setup script — pas commité au repo pour ne pas alourdir).
      // Surcharge possible via VITE_DEPARTEMENTS_GEOJSON_URL.
      const geojsonUrl = import.meta.env['VITE_DEPARTEMENTS_GEOJSON_URL']
        ?? '/tiles/admin/departements.geojson';
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
            0,    '#f1f5f9',
            10,   '#bae6fd',
            100,  '#38bdf8',
            500,  '#0284c7',
            2000, '#075985',
          ],
          'fill-opacity': 0.7,
        },
      });

      map.addLayer({
        id: 'dept-outline',
        type: 'line',
        source: 'departements',
        paint: { 'line-color': '#1e293b', 'line-width': 0.5 },
      });

      map.on('click', 'dept-fill', (e) => {
        const code = e.features?.[0]?.properties?.['code'] as string | undefined;
        if (code && onZoneClick) onZoneClick(code);
      });
      map.on('mouseenter', 'dept-fill', () => (map.getCanvas().style.cursor = 'pointer'));
      map.on('mouseleave', 'dept-fill', () => (map.getCanvas().style.cursor = ''));
    });

    map.on('error', (e) => {
      const msg = (e?.error as { message?: string })?.message ?? 'Erreur carte';
      if (!cancelled) setError(msg);
    });

    return () => {
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
