/**
 * Sprint 19.7 — Campaigns frontend types.
 * Alignés sur ScrapingCampaignResource + ScrapingCampaignsController.
 */

export type CampaignStatus =
  | 'draft'
  | 'scheduled'
  | 'running'
  | 'paused'
  | 'completed'
  | 'failed'
  | 'cancelled';

export type CampaignSource =
  | 'insee'
  | 'google_maps'
  | 'pages_jaunes'
  | 'france_travail'
  | 'annuaire'
  | 'bodacc'
  | 'ban';

export type ZoneType = 'department' | 'region' | 'city';

export interface CampaignZone {
  type: ZoneType;
  code: string;
  label?: string;
}

export interface PerSourceLimit {
  rpm?: number;
  daily?: number;
}

export interface Campaign {
  id: number;
  workspace_id: string;
  created_by: string;
  name: string;
  description: string | null;
  status: CampaignStatus;
  sources: CampaignSource[];
  zones: CampaignZone[];

  max_companies: number;
  max_duration_minutes: number;
  max_requests_per_minute: number;
  per_source_limits: Record<string, PerSourceLimit> | null;

  scheduled_at: string | null;
  expires_at: string | null;

  companies_created: number;
  requests_made: number;
  runs_completed: number;
  runs_total: number;
  duration_seconds_used: number;

  progress_percent: number;
  elapsed_minutes: number;
  remaining_minutes: number;
  companies_remaining: number;

  started_at: string | null;
  paused_at: string | null;
  finished_at: string | null;
  paused_reason: string | null;

  can_start: boolean;
  can_pause: boolean;
  can_resume: boolean;
  can_cancel: boolean;

  created_at: string;
  updated_at: string;

  runs_preview?: Array<{
    id: number;
    source: string;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
  }>;
}

export interface CampaignsListResponse {
  data: Campaign[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

export interface CampaignStatsResponse {
  campaign: Campaign;
  per_source: Array<{
    source: string;
    total: number;
    running: number;
    success: number;
    failed: number;
    companies: number;
  }>;
  last_events: Array<{
    id: number;
    source: string;
    status: string;
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
    request_payload: unknown;
  }>;
  companies_per_minute: number;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

export const ALL_SOURCES: Array<{
  id: CampaignSource;
  label: string;
  description: string;
  status: 'free' | 'proxies' | 'api_key';
}> = [
  { id: 'insee',          label: 'INSEE Sirene',     description: 'Base officielle entreprises FR (gratuite + API key)', status: 'api_key' },
  { id: 'google_maps',    label: 'Google Maps',      description: 'Fiches établissement geo-locale (proxies requis)',     status: 'proxies' },
  { id: 'pages_jaunes',   label: 'Pages Jaunes',     description: 'Annuaire pro FR (proxies recommandés)',                status: 'proxies' },
  { id: 'france_travail', label: 'France Travail',   description: 'Offres d’emploi (API key gratuite)',              status: 'api_key' },
  { id: 'annuaire',       label: 'Annuaire entreprises', description: 'annuaire-entreprises.data.gouv.fr (gratuit)',     status: 'free' },
  { id: 'bodacc',         label: 'BODACC',           description: 'Annonces légales (gratuit)',                       status: 'free' },
  { id: 'ban',            label: 'BAN géocodage', description: 'Base Adresse Nationale (gratuit)',                     status: 'free' },
];

export const STATUS_LABEL: Record<CampaignStatus, string> = {
  draft: 'Brouillon',
  scheduled: 'Planifiée',
  running: 'En cours',
  paused: 'En pause',
  completed: 'Terminée',
  failed: 'Échec',
  cancelled: 'Annulée',
};

export const PAUSED_REASON_LABEL: Record<string, string> = {
  quota_companies: 'Quota entreprises atteint',
  quota_duration: 'Quota durée atteint',
  manual: 'Pause manuelle',
  rate_limit: 'Limite de taux dépassée',
};

export function statusToTone(s: CampaignStatus): 'success' | 'warning' | 'danger' | 'info' | 'pending' | 'running' | 'neutral' {
  switch (s) {
    case 'running':   return 'running';
    case 'paused':    return 'warning';
    case 'completed': return 'success';
    case 'failed':    return 'danger';
    case 'cancelled': return 'danger';
    case 'scheduled': return 'info';
    case 'draft':     return 'pending';
    default:          return 'neutral';
  }
}
