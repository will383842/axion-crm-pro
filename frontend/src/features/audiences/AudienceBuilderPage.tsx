/**
 * Sprint Pipeline 360° — AudienceBuilderPage.
 *
 * Builder visuel 2 colonnes : critères à gauche, preview live (debounced 500ms) à droite.
 * À chaque changement, on POST /audiences/preview et on affiche {companies, contacts}.
 * Création : POST /audiences → redirect /audiences/:id.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  ChevronLeft, Sparkles, X, MapPin, Building, Tag, Mail, Users2, Layers, ListChecks, Send,
  Newspaper, Ban,
} from 'lucide-react';
import { api } from '@/lib/api';
import {
  JOIGNABILITES,
  NATURES,
  REGIONS,
  SECTEURS,
  TAILLES,
  type EntreeReferentiel,
} from '@/lib/referentiels.generated';
import {
  Button,
  Card,
  cn,
  CompteurDeSaisie,
  Input,
  PageHeader,
  Spinner,
  StatusPill,
} from '@/components/ui';
import type {
  AudienceCondition,
  AudienceCriteria,
  EmailAudience,
} from './AudiencesListPage';
import { METIER_PRESETS, critereMetiers } from './metiers';
import { chargerListes } from '@/features/listes/listes';
import {
  REGLAGE_PAR_DEFAUT,
  reglageVersApi,
  type ApercuDestinataires,
  type ReglageDestinataires,
} from './destinataires';
import { ReglageDestinatairesChamps } from './ReglageDestinatairesChamps';
import { ApercuDestinatairesCarte } from './ApercuDestinatairesCarte';
import {
  FORMAT_MEDIA_PRESETS,
  PUBLIC_MEDIA_PRESETS,
  SECTEUR_MEDIA_PRESETS,
  THEME_MEDIA_PRESETS,
  TYPE_MEDIA_PRESETS,
  ZONE_MEDIA_PRESETS,
  MOTIFS_PRESSE_ECARTEE,
  avecExclusions,
  criteresAudiencePresse,
  criteresMedias,
  exclusions,
} from './presse';
import {
  RELATIONS_EXCLUES_PAR_DEFAUT,
  SANS_TAILLE,
  aUnCriterePositif,
  construireCriteres,
  type ChoixPays,
} from './criteres-relation';
import {
  LIFECYCLE_LABELS,
  RELATION_TYPE_LABELS,
} from '@/features/crm-console/types';

// ---------------------------------------------------------------------------
// Presets
// ---------------------------------------------------------------------------
const DEPT_PRESETS: Array<{ code: string; label: string }> = [
  { code: '75', label: 'Paris' },
  { code: '92', label: 'Hauts-de-Seine' },
  { code: '93', label: 'Seine-Saint-Denis' },
  { code: '69', label: 'Rhône' },
  { code: '13', label: 'Bouches-du-Rhône' },
  { code: '33', label: 'Gironde' },
  { code: '31', label: 'Haute-Garonne' },
  { code: '59', label: 'Nord' },
  { code: '67', label: 'Bas-Rhin' },
  { code: '44', label: 'Loire-Atlantique' },
];

// Régions, tailles, secteurs, natures : le référentiel unique, GÉNÉRÉ depuis
// le serveur (`Taxonomy`). Les listes recopiées qui vivaient ici ne
// connaissaient que 10 secteurs sur 15, et des tailles (`micro`, `grande`,
// libellées « TPE (10-49) », faux au sens INSEE) que la collecte ne
// produisait pas : une audience « Micro » ne trouvait aucune fiche collectée.
const enPresets = (liste: readonly EntreeReferentiel[]): Array<{ code: string; label: string }> =>
  liste.map((e) => ({ code: e.code, label: e.libelle }));

const REGION_PRESETS = enPresets(REGIONS);
// « Taille non renseignée » : effectif inconnu OU organisation (association,
// fédération…), pour qui la taille d'entreprise ne s'applique pas. Aucun
// effectif n'est deviné : on cible ou on exclut ces fiches EN CONNAISSANCE
// de cause (chantier C).
const SIZE_PRESETS = [
  ...enPresets(TAILLES),
  { code: SANS_TAILLE, label: 'Taille non renseignée (effectif inconnu ou organisation)' },
];
const RELATION_PRESETS = Object.entries(RELATION_TYPE_LABELS).map(([code, label]) => ({ code, label }));
const ETAPE_PRESETS = Object.entries(LIFECYCLE_LABELS).map(([code, label]) => ({ code, label }));
const JOIGNABILITE_PRESETS = enPresets(JOIGNABILITES);
const PAYS_OPTIONS: Array<{ code: ChoixPays; label: string }> = [
  { code: 'tous', label: 'Tous pays' },
  { code: 'france', label: 'France' },
  { code: 'etranger', label: 'Étranger (hors France)' },
];
const SECTOR_PRESETS = enPresets(SECTEURS);
const NATURE_PRESETS = enPresets(NATURES);

const STATUS_PRESETS: Array<{ code: string; label: string }> = [
  { code: 'pending',              label: 'Pending' },
  { code: 'ready_for_outreach',   label: 'Prêt outreach' },
  { code: 'partial_email',        label: 'Email partiel' },
  { code: 'archived_no_email',    label: 'Archivé sans email' },
];

// ---------------------------------------------------------------------------
// Form
// ---------------------------------------------------------------------------
interface BuilderForm {
  name: string;
  description: string;
}

interface PreviewResponse {
  companies: number;
  contacts: number;
  /** Audience presse : adresses écartées par leur provenance, par motif. */
  presse_ecartees?: Record<string, number>;
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
export function AudienceBuilderPage() {
  const navigate = useNavigate();
  const { register, handleSubmit, watch, formState: { errors } } = useForm<BuilderForm>({
    defaultValues: { name: '', description: '' },
  });

  // États critères (multi-select chips)
  const [departments, setDepartments] = useState<string[]>([]);
  const [regions, setRegions] = useState<string[]>([]);
  const [sizes, setSizes] = useState<string[]>([]);
  const [sectors, setSectors] = useState<string[]>([]);
  const [natures, setNatures] = useState<string[]>([]);
  const [metiers, setMetiers] = useState<string[]>([]);
  const [statuses, setStatuses] = useState<string[]>(['ready_for_outreach']);
  const [qualityMin, setQualityMin] = useState<number>(0);
  const [hasEmail, setHasEmail] = useState<boolean>(false);
  const [tagsInput, setTagsInput] = useState<string>('');
  // 2026-09-30 — listes manuelles : « membres de la liste X », « sauf liste Y ».
  const [listesIncluses, setListesIncluses] = useState<string[]>([]);
  const [listesExclues, setListesExclues] = useState<string[]>([]);
  // 2026-09-30 — à qui écrire dans chaque organisation.
  const [reglage, setReglage] = useState<ReglageDestinataires>(REGLAGE_PAR_DEFAUT);
  const listes = useQuery({ queryKey: ['listes-manuelles'], queryFn: () => chargerListes() });
  const LISTE_PRESETS = useMemo(
    () => (listes.data ?? []).map((l) => ({ code: String(l.id), label: l.nom })),
    [listes.data],
  );
  // Chantiers B, C, D (2026-10-01) — relation, pays, joignabilité. Les
  // relations établies sont EXCLUES par défaut d'une prospection : visible,
  // décochable (`criteres-relation.ts`).
  const [relationsVisees, setRelationsVisees] = useState<string[]>([]);
  const [relationsExclues, setRelationsExclues] = useState<string[]>([...RELATIONS_EXCLUES_PAR_DEFAUT]);
  const [etapesVisees, setEtapesVisees] = useState<string[]>([]);
  const [etapesExclues, setEtapesExclues] = useState<string[]>([]);
  const [pays, setPays] = useState<ChoixPays>('tous');
  const [joignabilitesVisees, setJoignabilitesVisees] = useState<string[]>([]);
  const [joignabilitesExclues, setJoignabilitesExclues] = useState<string[]>([]);
  // Presse et exclusions (harmonisation des contacts, 2026-09-30).
  const [typesMedia, setTypesMedia] = useState<string[]>([]);
  const [zonesMedia, setZonesMedia] = useState<string[]>([]);
  const [themesMedia, setThemesMedia] = useState<string[]>([]);
  const [publicsMedia, setPublicsMedia] = useState<string[]>([]);
  const [formatsMedia, setFormatsMedia] = useState<string[]>([]);
  const [secteursMedia, setSecteursMedia] = useState<string[]>([]);
  const [exclNatures, setExclNatures] = useState<string[]>([]);
  const [exclTypesMedia, setExclTypesMedia] = useState<string[]>([]);
  // Audience presse (01/10/2026) : la presse, et seulement elle.
  const [audiencePresse, setAudiencePresse] = useState<boolean>(false);

  // Build criteria
  const criteria = useMemo<AudienceCriteria>(() => {
    const all: AudienceCondition[] = [];
    if (departments.length > 0) all.push({ field: 'department_code', op: 'in', value: departments });
    if (regions.length > 0)     all.push({ field: 'region_code',     op: 'in', value: regions });
    if (sectors.length > 0)     all.push({ field: 'sector_main',     op: 'in', value: sectors });
    if (natures.length > 0)     all.push({ field: 'entity_nature',   op: 'in', value: natures });
    // Métier : l'étiquette `metier-<code>` (chantier 2), en `contains_any`.
    const metier = critereMetiers(metiers);
    if (metier !== null)        all.push(metier);
    if (statuses.length > 0)    all.push({ field: 'prospection_status', op: 'in', value: statuses });
    if (qualityMin > 0)         all.push({ field: 'quality_score',   op: 'gte', value: qualityMin });
    if (hasEmail)               all.push({ field: 'has_email',       op: 'eq',  value: true });

    const tagList = tagsInput
      .split(/[,\s]+/)
      .map((t) => t.trim())
      .filter((t) => t.length > 0);
    const medias = criteresMedias(typesMedia, zonesMedia, {
      themes: themesMedia,
      publics: publicsMedia,
      formats: formatsMedia,
      secteurs: secteursMedia,
    });
    if (audiencePresse) {
      return criteresAudiencePresse({ departements: departments, regions, etiquettes: tagList }, medias, exclTypesMedia);
    }
    if (tagList.length > 0) all.push({ field: 'tags', op: 'contains_any', value: tagList });
    if (listesIncluses.length > 0) all.push({ field: 'liste_manuelle', op: 'in', value: listesIncluses.map(Number) });
    if (listesExclues.length > 0) all.push({ field: 'liste_manuelle', op: 'not_in', value: listesExclues.map(Number) });
    all.push(...medias);

    const criteres = construireCriteres(all, {
      relationsVisees,
      relationsExclues,
      etapesVisees,
      etapesExclues,
      pays,
      tailles: sizes,
      joignabilitesVisees,
      joignabilitesExclues,
    });
    return avecExclusions(criteres, exclusions({ natures: exclNatures, typesMedia: exclTypesMedia }));
  }, [
    departments, regions, sizes, sectors, natures, metiers, statuses, qualityMin, hasEmail, tagsInput,
    relationsVisees, relationsExclues, etapesVisees, etapesExclues, pays, joignabilitesVisees, joignabilitesExclues,
    typesMedia, zonesMedia, themesMedia, publicsMedia, formatsMedia, secteursMedia, exclNatures, exclTypesMedia, listesIncluses, listesExclues,
    audiencePresse,
  ]);
  const aDesCriteres = aUnCriterePositif(criteria);
  const conditionsRecap = [
    ...(criteria.all ?? []).map((c) => ({ bloc: 'ET', c })),
    ...(criteria.any ?? []).map((c) => ({ bloc: 'OU', c })),
    ...(criteria.not ?? []).map((c) => ({ bloc: 'SAUF', c })),
  ];

  // 2026-09-30 — aperçu des DESTINATAIRES (adresses distinctes, exclues par
  // motif), même anti-rebond que l'aperçu des entreprises.
  const [apercuDest, setApercuDest] = useState<ApercuDestinataires | null>(null);
  const [apercuDestErreur, setApercuDestErreur] = useState<string | null>(null);
  useEffect(() => {
    if (!aDesCriteres) {
      setApercuDest(null);
      setApercuDestErreur(null);
      return;
    }
    const timer = setTimeout(() => {
      api
        .post<{ data: ApercuDestinataires }>('/audiences/apercu-destinataires', { criteria, ...reglageVersApi(reglage) })
        .then((r) => {
          setApercuDest(r.data.data);
          setApercuDestErreur(null);
        })
        .catch((err: unknown) => {
          setApercuDest(null);
          setApercuDestErreur(extractApiMessage(err) ?? 'Aperçu des destinataires indisponible');
        });
    }, 500);
    return () => { clearTimeout(timer); };
  }, [criteria, aDesCriteres, reglage]);

  // Preview live (debounced 500ms)
  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const fetchPreview = useCallback(async (c: AudienceCriteria) => {
    setPreviewLoading(true);
    setPreviewError(null);
    try {
      const r = await api.post<PreviewResponse>('/audiences/preview', { criteria: c });
      setPreview(r.data);
    } catch (err) {
      setPreviewError(extractApiMessage(err) ?? 'Preview indisponible');
      setPreview(null);
    } finally {
      setPreviewLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!aDesCriteres) {
      setPreview(null);
      setPreviewError(null);
      return;
    }
    const timer = setTimeout(() => { void fetchPreview(criteria); }, 500);
    return () => { clearTimeout(timer); };
  }, [criteria, aDesCriteres, fetchPreview]);

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (await api.post<{ data: EmailAudience }>('/audiences', payload)).data,
  });

  const onSubmit = handleSubmit(async (form) => {
    if (!aDesCriteres) {
      toast.error('Ajoute au moins un critère');
      return;
    }
    try {
      const res = await createMutation.mutateAsync({
        name: form.name.trim(),
        description: form.description.trim() || undefined,
        criteria,
        is_active: true,
        auto_refresh: false,
        ...reglageVersApi(reglage),
      });
      toast.success('Audience créée');
      void navigate({ to: '/audiences/$audienceId', params: { audienceId: String(res.data.id) } });
    } catch (err) {
      toast.error(extractApiMessage(err) ?? 'Création impossible');
    }
  });

  const watchedName = watch('name');
  // D26-010 — le compteur a besoin de la valeur COURANTE, pas seulement de
  // celle qui sera soumise : la troncature se produit pendant la frappe.
  const watchedDescription = watch('description');
  const canCreate = watchedName.trim().length > 0 && aDesCriteres;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Nouvelle audience"
        subtitle="Compose un segment dynamique. La preview se met à jour à chaque modification."
        breadcrumbs={[
          { label: 'Audiences', to: '/audiences' },
          { label: 'Nouvelle' },
        ]}
      />

      <form onSubmit={onSubmit} className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
        {/* Colonne gauche : builder */}
        <div className="space-y-5">
          {/* Identité */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Sparkles className="h-4 w-4" />} title="Informations" />
            {/*
              D26-012 — UN SEUL MÉCANISME DE BORNE PAR CHAMP.
              Ces deux champs en portaient DEUX qui s'annulaient : l'attribut
              HTML `maxLength` tronque à 120 (resp. 500), et une règle
              react-hook-form `maxLength` prétendait rougir à 121 (resp. 501) —
              une longueur que la valeur ne pouvait jamais atteindre. Le message
              « Max 120 caractères » était donc INATTEIGNABLE par construction.
              On garde l'attribut HTML — le retirer laisserait partir vers l'API
              une valeur plus longue que la colonne — et on retire les règles
              mortes. La borne cesse d'être silencieuse par le compteur
              (D26-010), pas par un message qui ne s'affiche jamais.

              `required` RESTE : contrairement aux règles de longueur, ce n'est
              pas un doublon d'un attribut HTML, et il tient si `canCreate`
              (plus bas) change un jour de forme.
            */}
            <Field label="Nom" required error={errors.name?.message}>
              <Input
                placeholder="Ex : PME Île-de-France IT — prêtes outreach"
                {...register('name', { required: 'Nom requis' })}
                invalid={!!errors.name}
                maxLength={120}
                aria-describedby="compteur-nom-audience"
              />
              <CompteurDeSaisie id="compteur-nom-audience" valeur={watchedName ?? ''} max={120} />
            </Field>
            <Field label="Description (optionnel)">
              <textarea
                className="min-h-[70px] w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 transition placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
                placeholder="Pour quelle campagne ? Quel objectif ?"
                maxLength={500}
                aria-describedby="compteur-description-audience"
                {...register('description')}
              />
              <CompteurDeSaisie id="compteur-description-audience" valeur={watchedDescription ?? ''} max={500} />
            </Field>
          </Card>

          {/* Géographie */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<MapPin className="h-4 w-4" />} title="Géographie" />
            <Field label="Départements">
              <ChipsMultiSelect
                options={DEPT_PRESETS}
                selected={departments}
                onChange={setDepartments}
                placeholder="Aucun département"
              />
            </Field>
            <Field label="Régions">
              <ChipsMultiSelect
                options={REGION_PRESETS}
                selected={regions}
                onChange={setRegions}
                placeholder="Aucune région"
              />
            </Field>
            <Field label="Pays">
              <select
                aria-label="Pays"
                value={pays}
                onChange={(e) => setPays(e.target.value as ChoixPays)}
                className="w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
              >
                {PAYS_OPTIONS.map((o) => (
                  <option key={o.code} value={o.code}>{o.label}</option>
                ))}
              </select>
            </Field>
          </Card>

          {/* Relation et joignabilité (chantiers B et D) */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Users2 className="h-4 w-4" />} title="Relation et joignabilité" />
            <Field label="Types de relation visés">
              <ChipsMultiSelect
                options={RELATION_PRESETS}
                selected={relationsVisees}
                onChange={setRelationsVisees}
                placeholder="Tous types"
                masquerCode
              />
            </Field>
            <Field label="Types de relation EXCLUS">
              <p className="mb-2 text-[11px] text-slate-500 dark:text-slate-400">
                Clients, partenaires, presse, fournisseurs et investisseurs sont exclus par défaut d’une prospection, décochez pour les inclure.
              </p>
              <ChipsMultiSelect
                options={RELATION_PRESETS}
                selected={relationsExclues}
                onChange={setRelationsExclues}
                placeholder="Aucune exclusion"
                masquerCode
              />
            </Field>
            <Field label="Étapes visées">
              <ChipsMultiSelect
                options={ETAPE_PRESETS}
                selected={etapesVisees}
                onChange={setEtapesVisees}
                placeholder="Toutes étapes"
                masquerCode
              />
            </Field>
            <Field label="Étapes exclues">
              <ChipsMultiSelect
                options={ETAPE_PRESETS}
                selected={etapesExclues}
                onChange={setEtapesExclues}
                placeholder="Aucune exclusion"
                masquerCode
              />
            </Field>
            <Field label="Joignabilité visée">
              <ChipsMultiSelect
                options={JOIGNABILITE_PRESETS}
                selected={joignabilitesVisees}
                onChange={setJoignabilitesVisees}
                placeholder="Toutes"
                masquerCode
              />
            </Field>
            <Field label="Joignabilité exclue">
              <ChipsMultiSelect
                options={JOIGNABILITE_PRESETS}
                selected={joignabilitesExclues}
                onChange={setJoignabilitesExclues}
                placeholder="Aucune exclusion"
                masquerCode
              />
            </Field>
          </Card>

          {/* Taille / Secteur */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Building className="h-4 w-4" />} title="Taille et secteur" />
            <Field label="Tailles d'entreprise">
              <ChipsMultiSelect
                options={SIZE_PRESETS}
                selected={sizes}
                onChange={setSizes}
                placeholder="Toutes tailles"
              />
            </Field>
            <Field label="Secteurs">
              <ChipsMultiSelect
                options={SECTOR_PRESETS}
                selected={sectors}
                onChange={setSectors}
                placeholder="Tous secteurs"
              />
            </Field>
            <Field label="Natures d'organisation">
              <ChipsMultiSelect
                options={NATURE_PRESETS}
                selected={natures}
                onChange={setNatures}
                placeholder="Toutes natures"
              />
            </Field>
          </Card>

          {/* Presse et médias : étiquettes `media-type:` / `media-zone:` / `media-sujet:` / `media-public:` / `media-format:` */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Newspaper className="h-4 w-4" />} title="Presse et médias" />
            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-200">
              <input
                type="checkbox"
                checked={audiencePresse}
                onChange={(e) => { setAudiencePresse(e.target.checked); }}
                className="h-4 w-4 rounded accent-sky-600"
                aria-describedby="aide-audience-presse"
              />
              Audience presse
            </label>
            <p id="aide-audience-presse" className="text-[11px] text-slate-500 dark:text-slate-400">
              {audiencePresse
                ? 'Cette audience vise uniquement la presse : seuls la géographie, les étiquettes et les critères ci-dessous s’appliquent. Seules les adresses de provenance fiable sont retenues (jamais une adresse tirée d’un site deviné).'
                : 'Sans cette case, aucune fiche de presse n’entre dans l’audience : ces critères visent alors les fiches de média non protégées (production audiovisuelle…).'}
            </p>
            <Field label="Types de média">
              <ChipsMultiSelect
                options={TYPE_MEDIA_PRESETS}
                selected={typesMedia}
                onChange={setTypesMedia}
                placeholder="Tous types"
                masquerCode
              />
            </Field>
            <Field label="Zones de diffusion">
              <ChipsMultiSelect
                options={ZONE_MEDIA_PRESETS}
                selected={zonesMedia}
                onChange={setZonesMedia}
                placeholder="Toutes zones"
                masquerCode
              />
            </Field>
            <Field label="Thèmes (lecture du site)">
              <ChipsMultiSelect
                options={THEME_MEDIA_PRESETS}
                selected={themesMedia}
                onChange={setThemesMedia}
                placeholder="Tous thèmes"
                masquerCode
              />
            </Field>
            <Field label="Secteur couvert">
              <ChipsMultiSelect
                options={SECTEUR_MEDIA_PRESETS}
                selected={secteursMedia}
                onChange={setSecteursMedia}
                placeholder="Tous secteurs"
                masquerCode
              />
            </Field>
            <Field label="Publics visés">
              <ChipsMultiSelect
                options={PUBLIC_MEDIA_PRESETS}
                selected={publicsMedia}
                onChange={setPublicsMedia}
                placeholder="Tous publics"
                masquerCode
              />
            </Field>
            <Field label="Formats TV">
              <ChipsMultiSelect
                options={FORMAT_MEDIA_PRESETS}
                selected={formatsMedia}
                onChange={setFormatsMedia}
                placeholder="Tous formats"
                masquerCode
              />
            </Field>
          </Card>

          {/* Exclusions de nature et de type de média (bloc `not`) */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Ban className="h-4 w-4" />} title="Exclusions (nature, type de média)" />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => { setRelationsExclues([...new Set([...relationsExclues, ...RELATIONS_EXCLUES_PAR_DEFAUT])]); }}
            >
              Prospection : rétablir l’exclusion des relations établies
            </Button>
            <Field label="Natures exclues">
              <ChipsMultiSelect
                options={NATURE_PRESETS}
                selected={exclNatures}
                onChange={setExclNatures}
                placeholder="Aucune nature exclue"
              />
            </Field>
            <Field label="Types de média exclus">
              <ChipsMultiSelect
                options={TYPE_MEDIA_PRESETS}
                selected={exclTypesMedia}
                onChange={setExclTypesMedia}
                placeholder="Aucun type exclu"
                masquerCode
              />
            </Field>
          </Card>

          {/* Métiers (chantier 2) */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Building className="h-4 w-4" />} title="Métiers" />
            <Field label="Métiers (depuis le code NAF de la fiche)">
              <ChipsMultiSelect
                options={METIER_PRESETS}
                selected={metiers}
                onChange={setMetiers}
                placeholder="Tous métiers"
                masquerCode
                filtre="Filtrer les métiers"
              />
            </Field>
          </Card>

          {/* Qualité */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Layers className="h-4 w-4" />} title="Qualité et statut" />
            <Field label="Statuts prospection">
              <ChipsMultiSelect
                options={STATUS_PRESETS}
                selected={statuses}
                onChange={setStatuses}
                placeholder="Tous statuts"
              />
            </Field>
            <Field label={`Score qualité minimum : ${qualityMin}`}>
              <input
                type="range"
                min={0}
                max={100}
                step={5}
                value={qualityMin}
                onChange={(e) => setQualityMin(Number(e.target.value))}
                className="w-full accent-sky-600"
              />
              <div className="flex justify-between text-[10px] text-slate-400">
                <span>0</span><span>25</span><span>50</span><span>75</span><span>100</span>
              </div>
            </Field>
            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={hasEmail}
                onChange={(e) => setHasEmail(e.target.checked)}
                className="h-4 w-4 rounded accent-sky-600"
              />
              <Mail className="h-4 w-4 text-slate-400" />
              A au moins un contact avec email
            </label>
          </Card>

          {/* Listes manuelles (2026-09-30) */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<ListChecks className="h-4 w-4" />} title="Listes manuelles" />
            <Field label="Membres de ces listes (au moins une)">
              <ChipsMultiSelect
                options={LISTE_PRESETS}
                selected={listesIncluses}
                onChange={setListesIncluses}
                placeholder="Aucune liste exigée"
                masquerCode
              />
            </Field>
            <Field label="Sauf les membres de ces listes">
              <ChipsMultiSelect
                options={LISTE_PRESETS}
                selected={listesExclues}
                onChange={setListesExclues}
                placeholder="Aucune liste exclue"
                masquerCode
              />
            </Field>
          </Card>

          {/* Destinataires (2026-09-30) */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Send className="h-4 w-4" />} title="Destinataires" />
            <ReglageDestinatairesChamps
              valeur={reglage}
              onChange={setReglage}
              listeExigee={listesIncluses.length > 0}
            />
          </Card>

          {/* Tags */}
          <Card padding="md" className="space-y-4">
            <SectionHeading icon={<Tag className="h-4 w-4" />} title="Tags personnalisés" />
            <Field label="Slugs séparés par virgule ou espace (contains_any)">
              <Input
                placeholder="ex : decisionnaire, growth, fintech"
                value={tagsInput}
                onChange={(e) => setTagsInput(e.target.value)}
              />
            </Field>
          </Card>
        </div>

        {/* Colonne droite : sticky preview */}
        <aside className="lg:sticky lg:top-6 lg:self-start">
          <Card padding="md" variant="glass" className="space-y-4 border border-sky-200/60 dark:border-sky-900/40">
            <div className="flex items-center gap-2">
              <Users2 className="h-4 w-4 text-sky-600 dark:text-sky-400" />
              <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Preview live</h2>
              {previewLoading ? <Spinner size="sm" /> : null}
            </div>

            {previewError ? (
              <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-700 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40">
                {previewError}
              </div>
            ) : preview ? (
              <div className="grid grid-cols-2 gap-3">
                <PreviewStat label="Entreprises" value={preview.companies} tone="sky" />
                <PreviewStat label="Contacts"    value={preview.contacts}  tone="violet" />
                {preview.presse_ecartees !== undefined ? (
                  <ul className="col-span-2 space-y-1 text-xs text-slate-600 dark:text-slate-300" aria-label="Adresses de presse écartées">
                    {Object.entries(preview.presse_ecartees).map(([motif, n]) => (
                      <li key={motif}>
                        {MOTIFS_PRESSE_ECARTEE[motif] ?? motif} : {n.toLocaleString('fr-FR')} adresse(s) écartée(s)
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : (
              <div className="rounded-lg bg-slate-50 p-4 text-center text-xs text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                Ajoute au moins un critère pour voir la preview.
              </div>
            )}

            {apercuDestErreur !== null ? (
              <div className="rounded-lg bg-rose-50 p-3 text-xs text-rose-700 ring-1 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40">
                {apercuDestErreur}
              </div>
            ) : apercuDest !== null ? (
              <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
                <div className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                  Destinataires (rien n’est envoyé)
                </div>
                <ApercuDestinatairesCarte apercu={apercuDest} />
              </div>
            ) : null}

            {/* Recap critères */}
            {conditionsRecap.length > 0 ? (
              <div className="space-y-1.5">
                <div className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                  Critères ({conditionsRecap.length})
                </div>
                <ul className="space-y-1">
                  {conditionsRecap.map(({ bloc, c }, i) => (
                    <li key={i} className="rounded-md bg-slate-50 px-2 py-1 text-[11px] font-mono text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">
                      <span className="text-slate-400">{bloc}</span>{' '}
                      <span className="text-slate-900 dark:text-white">{c.field}</span>{' '}
                      <span className="text-slate-400">{c.op}</span>{' '}
                      <span className="text-sky-700 dark:text-sky-300">
                        {Array.isArray(c.value)
                          ? `[${c.value.length}]`
                          : typeof c.value === 'string' || typeof c.value === 'number' || typeof c.value === 'boolean'
                            ? String(c.value)
                            : ''}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            <div className="space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
              <Button
                type="submit"
                variant="primary"
                size="md"
                full
                iconLeft={<Sparkles className="h-4 w-4" />}
                loading={createMutation.isPending}
                disabled={!canCreate}
              >
                Créer l'audience
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                full
                iconLeft={<ChevronLeft className="h-3.5 w-3.5" />}
                onClick={() => { void navigate({ to: '/audiences' }); }}
              >
                Annuler
              </Button>
              {!canCreate ? (
                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                  Renseigne un nom et au moins un critère pour activer la création.
                </p>
              ) : null}
            </div>
          </Card>
        </aside>
      </form>
    </div>
  );
}

// ---------------------------------------------------------------------------
// ChipsMultiSelect — sélecteur chips toggle
// ---------------------------------------------------------------------------
function ChipsMultiSelect({
  options,
  selected,
  onChange,
  placeholder,
  masquerCode = false,
  filtre,
}: {
  options: ReadonlyArray<{ code: string; label: string }>;
  selected: string[];
  onChange: (next: string[]) => void;
  placeholder?: string;
  /** N'afficher que le libellé (le code d'un métier n'apprend rien au lecteur). */
  masquerCode?: boolean;
  /** Nom accessible d'un champ qui filtre les puces par libellé (longues listes). */
  filtre?: string;
}) {
  const [recherche, setRecherche] = useState('');
  const terme = recherche.trim().toLocaleLowerCase('fr');
  // Une puce CHOISIE reste toujours visible : on ne cache pas ce qui cible.
  const visibles = terme === ''
    ? options
    : options.filter((o) => selected.includes(o.code) || o.label.toLocaleLowerCase('fr').includes(terme));
  function toggle(code: string) {
    if (selected.includes(code)) {
      onChange(selected.filter((c) => c !== code));
    } else {
      onChange([...selected, code]);
    }
  }
  return (
    <div className="space-y-2">
      {selected.length === 0 && placeholder ? (
        <div className="text-[11px] italic text-slate-400">{placeholder}</div>
      ) : null}
      {filtre !== undefined ? (
        <Input
          aria-label={filtre}
          placeholder="ex : comptable, plombier, coiffure"
          value={recherche}
          onChange={(e) => setRecherche(e.target.value)}
        />
      ) : null}
      <div className="flex flex-wrap gap-1.5">
        {visibles.map((opt) => {
          const active = selected.includes(opt.code);
          return (
            <button
              key={opt.code}
              type="button"
              onClick={() => toggle(opt.code)}
              className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium transition',
                active
                  ? 'bg-sky-500 text-white ring-1 ring-sky-600 shadow-sm'
                  : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-700',
              )}
            >
              {masquerCode || opt.code.startsWith('__') ? null : <span className="font-mono text-[10px] opacity-70">{opt.code}</span>}
              <span>{opt.label}</span>
              {active ? <X className="h-3 w-3" /> : null}
            </button>
          );
        })}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// PreviewStat
// ---------------------------------------------------------------------------
function PreviewStat({ label, value, tone }: { label: string; value: number; tone: 'sky' | 'violet' }) {
  const chip = tone === 'sky'
    ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300'
    : 'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300';
  return (
    <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
      <span className={cn('inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider', chip)}>
        {label}
      </span>
      <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">
        {value.toLocaleString('fr-FR')}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Form utils
// ---------------------------------------------------------------------------
function SectionHeading({ icon, title }: { icon?: React.ReactNode; title: string }) {
  return (
    <div className="flex items-center gap-2">
      {icon ? <span className="text-slate-400">{icon}</span> : null}
      <h2 className="text-sm font-semibold tracking-tight text-slate-900 dark:text-white">{title}</h2>
    </div>
  );
}

function Field({
  label, required, error, children,
}: {
  label: string;
  required?: boolean;
  error?: string | undefined;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 inline-block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
        {label} {required ? <span className="text-rose-500">*</span> : null}
      </span>
      {children}
      {error ? (
        <span className="mt-1 inline-flex items-center gap-1 text-[11px] text-rose-600 dark:text-rose-400">
          <StatusPill tone="danger">{error}</StatusPill>
        </span>
      ) : null}
    </label>
  );
}

function extractApiMessage(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string; error?: string } } };
    return e.response?.data?.message ?? e.response?.data?.error ?? null;
  }
  return null;
}
