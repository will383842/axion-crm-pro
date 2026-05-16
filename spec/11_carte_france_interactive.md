# 11 — Carte France interactive

> **Stack 100 % gratuite :** MapLibre GL JS 4 + OpenFreeMap (tuiles) + IGN AdminExpress COG 2026 (polygones) + BAN (géocodage).
> **Zéro coût additionnel.** Pas de Mapbox/Google Maps.
> **3 modes :** Visualisation (choropleth) + Recherche (auto-suggest 2150+ villes) + Action (clic zone → panneau scraping).

---

## §1 — Stack technique

| Composant | Source | Coût | Licence |
|-----------|--------|------|---------|
| Rendu vectoriel WebGL | MapLibre GL JS v4+ | 0 € | BSD-3 |
| Tuiles fond de carte | `https://tiles.openfreemap.org/styles/positron` | 0 € illimité | OSM ODbL |
| Polygones régions/départements | IGN AdminExpress COG 2026 | 0 € | Licence Ouverte Etalab 2.0 |
| Polygones communes >5k | IGN AdminExpress COG 2026 | 0 € | Etalab 2.0 |
| Géocodage | `api-adresse.data.gouv.fr` (BAN) | 0 € illimité officiel | Etalab 2.0 |

---

## §2 — Import polygones IGN AdminExpress

### Download

```bash
# Version COG 2026 (mise à jour annuelle, fichier ~600 MB)
wget https://wxs.ign.fr/agriculture/telechargement/inspire/ADMIN-EXPRESS-COG_2-5__SHP_LAMB93_FXX_2026-01-15/file/ADMIN-EXPRESS-COG_2-5__SHP_LAMB93_FXX_2026-01-15.7z
7z x ADMIN-EXPRESS-COG_2-5__SHP_LAMB93_FXX_2026-01-15.7z
```

### Simplification + reprojection EPSG:4326

```bash
# Régions (13)
mapshaper ADE-COG_FR/ADMIN-EXPRESS-COG/1_DONNEES_LIVRAISON_*/ADECOG_3-2_SHP_LAMB93_FXX/REGION.shp \
  -proj wgs84 \
  -simplify 5% keep-shapes \
  -o format=geojson regions.geojson

# Départements (101 hors COM)
mapshaper ADECOG_3-2_SHP_LAMB93_FXX/DEPARTEMENT.shp \
  -proj wgs84 \
  -simplify 3% keep-shapes \
  -o format=geojson departments.geojson

# Communes >5k habitants (~2150)
mapshaper ADECOG_3-2_SHP_LAMB93_FXX/COMMUNE.shp \
  -filter "POPULATION > 5000" \
  -proj wgs84 \
  -simplify 2% keep-shapes \
  -o format=geojson cities.geojson
```

Tailles cibles :
- `regions.geojson` : ~200 KB gz
- `departments.geojson` : ~400 KB gz
- `cities.geojson` : ~1.2 MB gz

### Conversion vers MVT (Vector Tiles) avec tippecanoe

```bash
# Tuiles vectorielles servies par Caddy directement (pas de tile server)
tippecanoe -o admin.mbtiles \
  -L regions:regions.geojson \
  -L departments:departments.geojson \
  -L cities:cities.geojson \
  --maximum-zoom=12 --minimum-zoom=5 \
  --drop-densest-as-needed --extend-zooms-if-still-dropping \
  --no-tile-compression

# Extraction en répertoire de tuiles
mb-util admin.mbtiles ./public/tiles/admin/  # ./tiles/admin/{z}/{x}/{y}.pbf
```

### Servi par Caddy

```caddyfile
# Caddyfile addition
crm.axion-pro.com {
    # ... routes existantes
    handle /tiles/* {
        header Cache-Control "public, max-age=2592000, immutable"
        header Content-Encoding "gzip"
        header Content-Type "application/vnd.mapbox-vector-tile"
        root * /srv/react-build
        file_server
    }
}
```

### Import en DB (pour requêtes spatiales serveur-side)

Commande artisan `app:import-ign-polygons` :

```php
public function handle(): int
{
    $regions = json_decode(file_get_contents(storage_path('app/regions.geojson')), true);
    DB::beginTransaction();
    foreach ($regions['features'] as $f) {
        $props = $f['properties'];
        Region::updateOrCreate(['code' => $props['INSEE_REG']], [
            'country_code' => 'FR',
            'name'         => $props['NOM'],
            'population'   => $props['POPULATION'],
            'geometry'     => DB::raw('ST_Force2D(ST_GeomFromGeoJSON(' . DB::getPdo()->quote(json_encode($f['geometry'])) . '))'),
        ]);
    }
    DB::commit();
    // idem pour departments + cities
}
```

---

## §3 — Composant React `<FranceCoverageMap />`

### Architecture

```
src/features/map/
├── FranceCoverageMap.tsx        — composant principal
├── modes/
│   ├── VisualizationMode.tsx    — choropleth
│   ├── SearchMode.tsx           — auto-suggest
│   └── ActionMode.tsx           — clic zone → panneau
├── layers/
│   ├── ChoroplethLayer.tsx
│   ├── HoverLayer.tsx
│   └── LabelsLayer.tsx
├── hooks/
│   ├── useMapInit.ts
│   ├── useCoverageData.ts       — fetch /api/coverage
│   └── useZoneSelection.ts
└── styles/positron-fr.json      — style MapLibre custom
```

### Code principal (extrait)

```tsx
// src/features/map/FranceCoverageMap.tsx
import { useEffect, useRef, useState } from 'react'
import maplibregl, { Map } from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { useCoverageData } from './hooks/useCoverageData'
import { useZoneSelection } from './hooks/useZoneSelection'
import { VisualizationMode } from './modes/VisualizationMode'
import { SearchMode } from './modes/SearchMode'
import { ActionMode } from './modes/ActionMode'

type Mode = 'visualization' | 'search' | 'action'

export function FranceCoverageMap() {
  const containerRef = useRef<HTMLDivElement>(null)
  const mapRef = useRef<Map | null>(null)
  const [mode, setMode] = useState<Mode>('visualization')
  const { coverage } = useCoverageData()
  const { selected, setSelected } = useZoneSelection()

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return
    mapRef.current = new maplibregl.Map({
      container: containerRef.current,
      style: '/styles/positron-fr.json',
      center: [2.5, 46.6],
      zoom: 5.5,
      maxZoom: 12,
      minZoom: 5,
      maxBounds: [[-5.5, 41], [10, 51.5]],   // France métropolitaine
      attributionControl: { compact: true },
    })
    mapRef.current.addControl(new maplibregl.NavigationControl(), 'top-right')

    mapRef.current.on('load', () => {
      mapRef.current!.addSource('admin', {
        type: 'vector',
        tiles: ['/tiles/admin/{z}/{x}/{y}.pbf'],
        minzoom: 5,
        maxzoom: 12,
      })
      // Layers
      mapRef.current!.addLayer({
        id: 'departments-fill',
        type: 'fill',
        source: 'admin',
        'source-layer': 'departments',
        minzoom: 5, maxzoom: 8,
        paint: {
          'fill-color': [
            'interpolate', ['linear'],
            ['coalesce', ['feature-state', 'coverage'], 0],
            0, '#f8f9fa',
            25, '#a8d8ea',
            50, '#3e92cc',
            75, '#2d6a9f',
            100, '#0b2545',
          ],
          'fill-opacity': 0.7,
          'fill-outline-color': '#fff',
        },
      })
      mapRef.current!.addLayer({
        id: 'departments-line',
        type: 'line',
        source: 'admin',
        'source-layer': 'departments',
        minzoom: 5, maxzoom: 8,
        paint: { 'line-color': '#fff', 'line-width': 1 },
      })
      // ... layers cities (zoom > 8) etc.

      // Click handler
      mapRef.current!.on('click', 'departments-fill', (e) => {
        if (!e.features?.[0]) return
        const feature = e.features[0]
        setSelected({
          type: 'department',
          code: feature.properties.INSEE_DEP,
          name: feature.properties.NOM,
        })
      })
      mapRef.current!.on('mouseenter', 'departments-fill', () => {
        mapRef.current!.getCanvas().style.cursor = 'pointer'
      })
      mapRef.current!.on('mouseleave', 'departments-fill', () => {
        mapRef.current!.getCanvas().style.cursor = ''
      })
    })

    return () => { mapRef.current?.remove(); mapRef.current = null }
  }, [])

  // Update feature-state when coverage data loads
  useEffect(() => {
    if (!mapRef.current || !coverage) return
    if (!mapRef.current.isStyleLoaded()) return
    for (const [deptCode, pct] of Object.entries(coverage.departments)) {
      mapRef.current.setFeatureState(
        { source: 'admin', sourceLayer: 'departments', id: deptCode },
        { coverage: pct }
      )
    }
  }, [coverage])

  return (
    <div className="relative h-screen w-full">
      <div ref={containerRef} className="absolute inset-0" />
      <div className="absolute top-4 left-4 bg-white shadow-lg rounded-lg p-2 flex gap-1">
        {(['visualization', 'search', 'action'] as Mode[]).map(m => (
          <button
            key={m}
            onClick={() => setMode(m)}
            className={`px-3 py-1 rounded ${mode === m ? 'bg-blue-600 text-white' : 'bg-gray-100'}`}
          >{labelFor(m)}</button>
        ))}
      </div>
      {mode === 'visualization' && <VisualizationMode coverage={coverage} />}
      {mode === 'search' && <SearchMode map={mapRef.current} />}
      {mode === 'action' && <ActionMode selected={selected} />}
    </div>
  )
}
```

### Mode Visualisation

Affiche choropleth selon le filtre actif :
- **% scrapé** = `enriched_count / companies_count`
- **% qualité complète** = `quality_complete / companies_count`
- **Nombre total** par zone
- 3 niveaux zoom : régions (5-6) → départements (6-8) → communes >5k (8-12)

### Mode Recherche

```tsx
export function SearchMode({ map }: { map: Map | null }) {
  const [q, setQ] = useState('')
  const { data: suggestions } = useCitySuggestions(q)

  return (
    <Combobox
      onChange={(city: City) => {
        map?.flyTo({ center: [city.lon, city.lat], zoom: 10, duration: 1500 })
      }}
    >
      <Combobox.Input
        onChange={e => setQ(e.target.value)}
        placeholder="Chercher une ville (Paris, Lyon, ...)"
        className="..."
      />
      <Combobox.Options>
        {suggestions?.map(c => (
          <Combobox.Option key={c.code_insee} value={c}>
            {c.name} ({c.department}) — {formatPop(c.population)}
          </Combobox.Option>
        ))}
      </Combobox.Options>
    </Combobox>
  )
}
```

Endpoint backend `/api/cities/suggest?q=par&limit=10`:
```sql
SELECT code_insee, name, department, population,
       ST_X(centroid) AS lon, ST_Y(centroid) AS lat
FROM cities
WHERE name ILIKE ? || '%'  OR slug ILIKE ? || '%'
ORDER BY population DESC
LIMIT 10;
```

### Mode Action

Panneau latéral droit s'ouvre au clic sur une zone :

```tsx
export function ActionMode({ selected }: { selected: ZoneSelection | null }) {
  if (!selected) return null
  const { data: stats } = useZoneStats(selected)
  return (
    <Sheet defaultOpen>
      <SheetContent side="right" className="w-[400px]">
        <h2 className="text-xl font-bold">{selected.name}</h2>
        <div className="grid grid-cols-2 gap-2 mt-4">
          <KpiCard label="Entreprises connues" value={stats?.companies_count} />
          <KpiCard label="Enrichies" value={stats?.enriched_count} suffix="%" />
          <KpiCard label="Fiches 🟢" value={stats?.quality_complete} color="green" />
          <KpiCard label="Fiches 🟡" value={stats?.quality_partial} color="amber" />
        </div>
        <Separator className="my-4" />
        <h3 className="font-semibold mb-2">Lancer un scraping</h3>
        <Form>
          <Select name="naf_section">...</Select>
          <Select name="size_category">...</Select>
          <Button onClick={() => launchScraping(selected, formData)}>
            Lancer scraping de cette zone
          </Button>
        </Form>
      </SheetContent>
    </Sheet>
  )
}
```

---

## §4 — API backend `/api/coverage`

```php
// Route: GET /api/coverage?level=department&size=pme
public function index(Request $req): JsonResponse
{
    $level = $req->input('level', 'department');     // region|department|city
    $size = $req->input('size');
    $naf = $req->input('naf_section');

    $key = "dept_code";   // or region_code / city_insee
    $field = match($level) {
        'region'     => 'region_code',
        'department' => 'department_code',
        'city'       => 'city_insee',
    };

    $rows = DB::table('coverage_matrix_cells')
        ->select($field . ' AS code',
                 DB::raw('SUM(companies_count) AS total'),
                 DB::raw('SUM(quality_complete) AS quality_complete'),
                 DB::raw('SUM(quality_partial) AS quality_partial'),
                 DB::raw('SUM(enriched_count) AS enriched'))
        ->when($size, fn($q) => $q->where('size_category', $size))
        ->when($naf, fn($q) => $q->where(DB::raw('LEFT(naf_subclass_code, 1)'), $naf))
        ->groupBy($field)
        ->get();

    return response()->json([
        'level' => $level,
        'data'  => $rows->mapWithKeys(fn($r) => [$r->code => [
            'total' => (int)$r->total,
            'pct_enriched' => round(100 * $r->enriched / max(1, $r->total), 1),
            'pct_complete' => round(100 * $r->quality_complete / max(1, $r->total), 1),
        ]]),
    ]);
}
```

Cache 60 s côté serveur (Redis).

---

## §5 — Performance

### Tailles

- Tuiles vectorielles : ~50 KB par zoom level / par zone (total ~10 MB sur tout zoom)
- GeoJSON `cities.geojson` : 1.2 MB gz (chargé uniquement si nécessaire en fallback)
- Bundle MapLibre : 230 KB gz

### Optimisations

- Lazy-load MapLibre (dynamic import quand l'utilisateur ouvre la page Coverage)
- `setFeatureState` (pas de re-render layer entier)
- Tuiles cachées agressif (Cache-Control 30 jours immutable)
- Coverage data refetch toutes les 60s seulement si page visible
- Workers Web : décodage MVT off main thread (natif MapLibre)

---

## §6 — Wireframe page Coverage

```
┌──────────────────────────────────────────────────────────────────────┐
│ Axion CRM Pro   ☰  Dashboard   📍 Coverage   👥 Entreprises   ...    │
├──────────────────────────────────────────────────────────────────────┤
│ Coverage Map                                          [Filters ▼]    │
│ ┌────────────────────────────────────────────────────────────────┐  │
│ │ [Visu] [Search] [Action]                                       │  │
│ │ ┌────────────────────────────────────────────────────┐ ┌─────┐ │  │
│ │ │                                                     │ │     │ │  │
│ │ │           Carte France interactive                 │ │ KPI │ │  │
│ │ │           (MapLibre + OpenFreeMap)                 │ │  +  │ │  │
│ │ │                                                     │ │ Act │ │  │
│ │ │   [Choropleth coverage par département]            │ │     │ │  │
│ │ │                                                     │ │     │ │  │
│ │ └────────────────────────────────────────────────────┘ └─────┘ │  │
│ │                                                                │  │
│ │ Légende : 🟢 ≥75%  🔵 50-75%  🟡 25-50%  🔴 <25%               │  │
│ │ Filtres : [NAF: tous▼] [Taille: tous▼] [Région: tous▼]         │  │
│ └────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Lecture suivante

→ `12_coverage_matrix_deduplication.md` (Materialized View + anti-doublon 6 niveaux + fuzzy matching).
