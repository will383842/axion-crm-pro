/**
 * Bridge Laravel → Node via Redis lists simples (pas BullMQ binaire).
 * Côté Laravel : `Redis::connection('queue')->lpush('axion:scrape:<source>', json)`
 * Côté Node    : `BRPOP axion:scrape:<source>` (blocking pop, timeout 30s)
 */
export const QUEUES = {
  GOOGLE_MAPS:     'axion:scrape:google-maps',
  PAGES_JAUNES:    'axion:scrape:pages-jaunes',
  WEBSITE:         'axion:scrape:website',
  GOOGLE_SEARCH:   'axion:scrape:google-search',
  DIRECTION_FINDER:'axion:scrape:direction-finder',
  FRANCE_TRAVAIL:  'axion:scrape:france-travail',
  MESRI:           'axion:scrape:mesri',
  CRUNCHBASE:      'axion:scrape:crunchbase',
  INFOGREFFE:      'axion:scrape:infogreffe',
  SOCIETE_COM:     'axion:scrape:societe-com',
  SOCIAL_LIGHT:    'axion:scrape:social-light',
} as const;

export type QueueName = (typeof QUEUES)[keyof typeof QUEUES];
