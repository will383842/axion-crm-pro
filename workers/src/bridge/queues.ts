/**
 * Bridge Laravel Horizon ↔ Node BullMQ — convention de nommage des queues partagées via Redis.
 * Côté Laravel : `dispatch(new GoogleMapsScrapeJob(...))->onQueue('scrape:google-maps');`
 * Côté Node    : `new Worker('scrape:google-maps', ...)`.
 */
export const QUEUES = {
  GOOGLE_MAPS:     'scrape:google-maps',
  PAGES_JAUNES:    'scrape:pages-jaunes',
  WEBSITE:         'scrape:website',
  GOOGLE_SEARCH:   'scrape:google-search',
  DIRECTION_FINDER:'scrape:direction-finder',
  FRANCE_TRAVAIL:  'scrape:france-travail',
  MESRI:           'scrape:mesri',
  CRUNCHBASE:      'scrape:crunchbase',
  INFOGREFFE:      'scrape:infogreffe',
  SOCIETE_COM:     'scrape:societe-com',
  SOCIAL_LIGHT:    'scrape:social-light',
} as const;

export type QueueName = (typeof QUEUES)[keyof typeof QUEUES];
