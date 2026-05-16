# 11 — CARTE FRANCE INTERACTIVE

## Vue d'ensemble

La carte de France interactive est l'**élément visuel central** de la console admin Axion CRM Pro. Elle remplit 3 fonctions clés depuis une seule vue :

1. **Visualisation** : où en est la couverture scrapée par région / département / ville (choropleth coloré).
2. **Recherche** : auto-suggest sur 2 157 villes éligibles (≥ 5 000 hab) + zoom auto sur zone trouvée.
3. **Action** : clic sur une zone → panneau latéral avec stats détaillées + boutons "Lancer scraping" / "Ajouter à campagne".

**Contrainte stratégique :** **100 % gratuite et sans vendor lock-in**. Aucune dépendance Mapbox / Google Maps / HERE / MapTiler payant.

---

## Stack 100 % gratuite

| Couche | Choix | Coût | Notes |
|---|---|---|---|
| Rendu carto | **MapLibre GL JS 4.x** | 0€ OSS | Fork libre de Mapbox GL JS v1 sous licence BSD-3 |
| Tiles vectorielles | **OpenFreeMap** | 0€ illimité | `https://tiles.openfreemap.org/styles/positron` |
| Polygones administratifs | **IGN AdminExpress COG 2026** | 0€ (Open License Etalab) | Régions + départements + communes ≥ 5 000 hab |
| Géocodage | **api-adresse.data.gouv.fr (BAN)** | 0€ illimité officiel | Géocodage France métro + DOM-TOM |
| Auto-suggest villes | **Search local PostgreSQL** | 0€ | pg_trgm GIN index sur `cities.name_unaccented` |

**Coût total : 0€/mois** + ~30 Mo de bande passante par session utilisateur (tiles vectorielles).

---

## Import des polygones IGN AdminExpress

### Téléchargement officiel

```bash
# Source officielle IGN (gratuite, Open License Etalab)
curl -L -o /tmp/admin-express-cog-fr-2026.zip \
  "https://data.geopf.fr/telechargement/download/ADMIN-EXPRESS-COG/ADMIN-EXPRESS-COG_3-2__SHP_WGS84G_FRA_2026-01-01.7z"
7z x -o/tmp/admin-express /tmp/admin-express-cog-fr-2026.zip
```

### Simplification via mapshaper

Les fichiers shapefile bruts pèsent ~600 Mo (communes). On simplifie pour ne garder que :
- Régions : 100 % de précision (13 régions, ~3 Mo total après simplification)
- Départements : 100 % (101 dept, ~5 Mo)
- Communes : **uniquement >= 5 000 habitants** + simplification mapshaper -p 0.05 (5 % des sommets) → ~2 157 communes, ~10 Mo

```bash
npm install -g mapshaper

# Régions
mapshaper /tmp/admin-express/ADE-COG/REGION.shp \
  -simplify 0.10 \
  -o format=geojson /tmp/regions.geojson

# Départements
mapshaper /tmp/admin-express/ADE-COG/DEPARTEMENT.shp \
  -simplify 0.08 \
  -o format=geojson /tmp/departments.geojson

# Communes filtrées >= 5000 hab + simplifiées
mapshaper /tmp/admin-express/ADE-COG/COMMUNE.shp \
  -filter "POPULATION >= 5000" \
  -simplify 0.05 \
  -o format=geojson /tmp/cities-5k-plus.geojson
```

### Import en PostgreSQL via Laravel artisan

```bash
php artisan axion:import-geo \
  --regions=/tmp/regions.geojson \
  --departments=/tmp/departments.geojson \
  --cities=/tmp/cities-5k-plus.geojson
```

Le seeder fait :
- INSERT `regions`, `departments`, `cities` (cf fichier 03 schema)
- Set `geom_simplified` en MultiPolygon SRID 4326
- Calcul `centroid` automatique
- Validation : ≥ 2 100 communes (sinon le seeder fail)

---

## API backend Laravel

### `GET /api/coverage/matrix`

Endpoint principal pour la carte. Retourne les agrégations par zone.

```json
{
  "zoom_level": "region", // ou "department" | "city"
  "filters": { "axion_offer": ["mission_pme"], "priority_score": ["prioritaire"] },
  "cells": [
    {
      "zone_type": "region",
      "zone_code": "11",
      "zone_name": "Île-de-France",
      "total_companies": 24500,
      "enriched_companies": 12340,
      "coverage_pct": 50.4,
      "hot_count": 423
    },
    // ...
  ]
}
```

Implémentation : SELECT agrégé depuis `coverage_matrix_cells` (materialized view).

### `GET /api/coverage/zones/{type}/{code}`

Détail d'une zone particulière (panneau latéral).

```json
{
  "zone": { "type": "department", "code": "75", "name": "Paris" },
  "stats": {
    "total_companies": 24500,
    "enriched_companies": 12340,
    "by_size": { "TPE": 8200, "PME": 3500, "ETI": 540, "GE": 100 },
    "by_offer": { "audit_flash": 6000, "audit_cible": 3500, "mission_pme": 2200, "mission_eti": 540 },
    "by_signal_severity": { "critical": 20, "high": 145, "medium": 480 },
    "top_naf": [["6201Z", 1820], ["7022Z", 1240], ["7112B", 760]]
  },
  "actions": [
    { "id": "launch_scrape_zone", "label": "Lancer scraping zone" },
    { "id": "add_to_campaign",     "label": "Ajouter à campagne" }
  ]
}
```

### `GET /api/coverage/tiles/{z}/{x}/{y}.mvt`

Tile vectorielle Mapbox MVT pour les communes (fallback en cas de très haute densité). Optionnel V1 — par défaut on charge les communes en GeoJSON paginé par département.

---

## Composant React `<FranceCoverageMap />`

### Props

```tsx
interface FranceCoverageMapProps {
  workspaceId: number;
  initialFilters?: CoverageFilters;
  onZoneClick?: (zone: ZoneRef) => void;
  mode: 'visualization' | 'search' | 'action';        // mode initial
}

interface CoverageFilters {
  axionOffer?: string[];
  priorityScore?: string[];
  iaMaturity?: string[];
  nafSection?: string[];
  effectifTier?: string[];
  hasActiveSignal?: boolean;
}

interface ZoneRef {
  type: 'region' | 'department' | 'city';
  code: string;
  name: string;
}
```

### Squelette code

```tsx
import { useEffect, useRef, useState } from 'react';
import maplibregl, { Map, Popup } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useQuery } from '@tanstack/react-query';
import { fetchCoverageMatrix, fetchZoneDetail } from '@/api/coverage';

export function FranceCoverageMap({ workspaceId, initialFilters, onZoneClick, mode }: FranceCoverageMapProps) {
  const mapRef = useRef<HTMLDivElement>(null);
  const mapInstance = useRef<Map | null>(null);
  const [selectedZone, setSelectedZone] = useState<ZoneRef | null>(null);
  const [zoom, setZoom] = useState<'region' | 'department' | 'city'>('region');
  const [filters, setFilters] = useState(initialFilters ?? {});

  const { data: cells } = useQuery({
    queryKey: ['coverage-matrix', zoom, filters],
    queryFn: () => fetchCoverageMatrix({ zoom, ...filters }),
  });

  useEffect(() => {
    if (!mapRef.current) return;
    mapInstance.current = new maplibregl.Map({
      container: mapRef.current,
      style: 'https://tiles.openfreemap.org/styles/positron',
      center: [2.5, 46.5],          // France centroïde approximatif
      zoom: 5,
      maxZoom: 12,
      minZoom: 4,
    });

    mapInstance.current.on('load', () => {
      // Source régions (GeoJSON statique servi par /api/geo/regions.geojson)
      mapInstance.current!.addSource('regions', {
        type: 'geojson',
        data: '/api/geo/regions.geojson',
      });
      mapInstance.current!.addSource('departments', {
        type: 'geojson',
        data: '/api/geo/departments.geojson',
      });
      mapInstance.current!.addSource('cities', {
        type: 'geojson',
        data: '/api/geo/cities.geojson',     // ~10 Mo, lazy-loaded à zoom >= 8
      });

      mapInstance.current!.addLayer({
        id: 'regions-fill',
        type: 'fill',
        source: 'regions',
        paint: {
          'fill-color': [
            'interpolate', ['linear'], ['get', 'coverage_pct'],
            0, '#fee5d9',
            25, '#fcae91',
            50, '#fb6a4a',
            75, '#de2d26',
            100, '#a50f15',
          ],
          'fill-opacity': 0.7,
          'fill-outline-color': '#333',
        },
        maxzoom: 7,                          // affiché entre zoom 4-7
      });
      mapInstance.current!.addLayer({
        id: 'departments-fill',
        type: 'fill',
        source: 'departments',
        paint: { /* idem */ },
        minzoom: 7,
        maxzoom: 9,
      });
      mapInstance.current!.addLayer({
        id: 'cities-fill',
        type: 'fill',
        source: 'cities',
        paint: { /* idem */ },
        minzoom: 9,
      });

      // Hover popup
      const popup = new Popup({ closeButton: false, closeOnClick: false });
      mapInstance.current!.on('mousemove', 'regions-fill', (e) => {
        const f = e.features?.[0];
        if (!f) return;
        popup
          .setLngLat(e.lngLat)
          .setHTML(`<strong>${f.properties.name}</strong><br/>${f.properties.coverage_pct}% scrapé`)
          .addTo(mapInstance.current!);
      });
      mapInstance.current!.on('mouseleave', 'regions-fill', () => popup.remove());

      // Click action
      mapInstance.current!.on('click', 'regions-fill', (e) => {
        const f = e.features?.[0];
        if (!f) return;
        const zone = { type: 'region', code: f.properties.insee_code, name: f.properties.name };
        setSelectedZone(zone);
        onZoneClick?.(zone);
      });
      // ... même chose pour departments-fill et cities-fill
    });

    mapInstance.current.on('zoomend', () => {
      const z = mapInstance.current!.getZoom();
      if (z < 7) setZoom('region');
      else if (z < 9) setZoom('department');
      else setZoom('city');
    });

    return () => mapInstance.current?.remove();
  }, []);

  // Update painting on filter / cells change
  useEffect(() => {
    if (!mapInstance.current || !cells) return;
    // Update source data with coverage_pct from cells
    const sourceId = `${zoom}s`;
    const src = mapInstance.current.getSource(sourceId) as maplibregl.GeoJSONSource;
    src.setData(injectCoverageInGeojson(src._data, cells));
  }, [cells, zoom]);

  return (
    <div className="relative h-[700px] w-full">
      <div ref={mapRef} className="absolute inset-0" />
      {mode === 'search' && <CitySearchBar onSelect={(city) => mapInstance.current?.flyTo({ center: [city.lng, city.lat], zoom: 11 })} />}
      <CoverageFilterPanel filters={filters} onChange={setFilters} className="absolute top-4 left-4 max-w-xs" />
      {selectedZone && (
        <ZoneDetailPanel zone={selectedZone} className="absolute top-0 right-0 h-full w-[420px] bg-zinc-900 text-zinc-100 shadow-2xl" />
      )}
    </div>
  );
}
```

### Composant `<ZoneDetailPanel>`

```tsx
function ZoneDetailPanel({ zone }: { zone: ZoneRef }) {
  const { data } = useQuery({
    queryKey: ['zone-detail', zone.type, zone.code],
    queryFn: () => fetchZoneDetail(zone.type, zone.code),
  });
  return (
    <aside className="flex flex-col p-6 gap-4">
      <h2 className="text-2xl font-bold">{zone.name}</h2>
      <p className="text-sm text-zinc-400">{zone.type} INSEE {zone.code}</p>
      {data && (
        <>
          <StatsCard label="Entreprises totales" value={data.stats.total_companies} />
          <StatsCard label="Enrichies" value={data.stats.enriched_companies} pct={data.stats.coverage_pct} />
          <SizeBreakdown by_size={data.stats.by_size} />
          <OfferBreakdown by_offer={data.stats.by_offer} />
          <SignalsBadge by_signal={data.stats.by_signal_severity} />
          <TopNafTable top_naf={data.stats.top_naf} />
          <div className="flex gap-2 mt-4">
            <Button onClick={() => launchScrape(zone)}>Lancer scraping zone</Button>
            <Button variant="secondary" onClick={() => addToCampaign(zone)}>Ajouter à campagne</Button>
          </div>
        </>
      )}
    </aside>
  );
}
```

### Composant `<CitySearchBar>`

```tsx
function CitySearchBar({ onSelect }: { onSelect: (city: City) => void }) {
  const [query, setQuery] = useState('');
  const { data: suggestions = [] } = useQuery({
    queryKey: ['city-suggest', query],
    queryFn: () => fetchCitySuggestions(query),
    enabled: query.length >= 2,
    staleTime: 60_000,
  });
  return (
    <Combobox value={null} onChange={onSelect}>
      <ComboboxInput
        className="absolute top-4 right-[430px] z-10 w-72 px-4 py-2 rounded-lg bg-zinc-900 text-zinc-100"
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Rechercher une ville…"
      />
      <ComboboxOptions className="absolute top-14 right-[430px] z-10 w-72 max-h-64 overflow-y-auto bg-zinc-900 rounded-lg shadow-xl">
        {suggestions.map(city => (
          <ComboboxOption key={city.insee_code} value={city}>
            {city.name} <span className="text-zinc-500">({city.postal_codes[0]})</span>
          </ComboboxOption>
        ))}
      </ComboboxOptions>
    </Combobox>
  );
}
```

Endpoint API `GET /api/cities/suggest?q=par` retourne max 10 résultats triés par population desc, matchant `name_unaccented ILIKE '%{q}%'` via index pg_trgm GIN. Latence p95 < 50 ms.

---

## Performance

| Optimisation | Bénéfice |
|---|---|
| Tiles vectorielles WebGL (MapLibre) | Rendu fluide même à 60 fps sur 2 157 polygones villes |
| Lazy-load GeoJSON villes (visible zoom ≥ 8) | Économie ~10 Mo au chargement initial |
| Cache navigateur 7 jours sur `.geojson` statiques | Re-visite = 0 fetch |
| Cache CDN Cloudflare sur `/api/geo/*.geojson` | Origin Hetzner moins sollicité |
| Materialized view `coverage_matrix_cells` refresh hourly | Réponse API < 30 ms |
| Index PostGIS GiST sur `geom_simplified` | Query spatial < 10 ms |
| TanStack Query staleTime 60s | Re-render filter = 0 fetch si cache frais |
| Cluster de markers (optionnel) | Affichage entreprises individuelles si zoom > 11 |

---

## 3 modes obligatoires

### Mode `visualization` (par défaut)

- Choropleth par zone selon `coverage_pct` (rouge sombre = 100 %, beige = 0 %)
- Filtres composables (axion_offer, priority_score, ia_maturity, NAF section, effectif_tier, has_active_signal)
- Tooltip au survol

### Mode `search`

- Barre auto-suggest activée (top-right)
- Sélection ville → zoom 11 + pin
- Pan automatique vers la ville sélectionnée

### Mode `action`

- Clic zone → panneau latéral droit (`<ZoneDetailPanel>`)
- Boutons d'action : "Lancer scraping zone" / "Ajouter à campagne (Phase 2)"
- Confirmation modale avec récap zone × cible avant trigger

Les 3 modes coexistent dans la même page Coverage (page 3 du fichier 13). Toggle par segmented control en haut.

---

## 6. Tests d'acceptance (S9)

- [ ] Carte se charge en < 2.5 s p95 sur 4G
- [ ] FPS ≥ 30 sur Macbook Air M1 base au zoom 9 avec 2 157 communes affichées
- [ ] Recherche ville "par" retourne "Paris" en top 3 en < 100 ms
- [ ] Choropleth se met à jour < 200 ms après changement de filtre
- [ ] Clic zone ouvre panel détail < 500 ms
- [ ] Lancement scraping zone depuis carte fonctionne end-to-end (commit job + tracé `target_zones`)
- [ ] OpenFreeMap répond 200 OK sur tous les niveaux de zoom 4-12

---

## 7. Plan B si OpenFreeMap tombe

Risque #8 dans fichier 22 (OpenFreeMap ferme ou rate-limit).

Mitigation immédiate (< 1h de boulot) :
1. Self-host tiles avec `protomaps/tile-server` + dataset Protomaps Daylight (téléchargement 70 Go)
2. Ou bascule sur MapTiler Cloud free tier (100k tile loads/mois) — suffisant pour V1
3. Variable d'env `MAP_STYLE_URL` éditable sans redéploiement

---

## 8. Anti-patterns interdits

- ❌ Mapbox GL JS v2+ (licence commerciale + tokens limit)
- ❌ Google Maps Embed (coût explosif > $200/mois au volume cible)
- ❌ Chargement des 2 157 polygones villes au render initial (= 10 Mo bloquants)
- ❌ Re-render React à chaque déplacement de souris (utiliser MapLibre listeners natifs)
- ❌ Stocker tile MVT/PNG en DB PostgreSQL (utiliser Cloudflare CDN)

---

## Prochaine étape

→ Lire `12_coverage_matrix_deduplication.md` pour le coverage matrix multi-dimensions + dédup 6 niveaux.
