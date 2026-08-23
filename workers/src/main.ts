import pino from 'pino';
import { startGoogleMapsWorker } from './scrapers/google-maps.worker';
import { startPagesJaunesWorker } from './scrapers/pages-jaunes.worker';
import { startWebsiteWorker } from './scrapers/website.worker';
import { startGoogleSearchWorker } from './scrapers/google-search.worker';
import { startDirectionFinderWorker } from './scrapers/direction-finder.worker';
import { startFranceTravailWorker } from './scrapers/france-travail.worker';
import { startMesriWorker } from './scrapers/mesri.worker';
import { startCrunchbaseWorker } from './scrapers/crunchbase.worker';
import { startInfogreffeWorker } from './scrapers/infogreffe.worker';
import { startSocieteComWorker } from './scrapers/societe-com.worker';
import { startSocialLightWorker } from './scrapers/social-light.worker';
import { useMockScrapers } from './config/mocks';

const log = pino({ name: 'workers', level: process.env['LOG_LEVEL'] ?? 'info' });

type WorkerType =
  | 'google-maps' | 'pages-jaunes' | 'website' | 'google-search' | 'direction-finder'
  | 'france-travail' | 'mesri' | 'crunchbase' | 'infogreffe' | 'societe-com' | 'social-light';

const REGISTRY: Record<WorkerType, () => Promise<void>> = {
  'google-maps':       startGoogleMapsWorker,
  'pages-jaunes':      startPagesJaunesWorker,
  'website':           startWebsiteWorker,
  'google-search':     startGoogleSearchWorker,
  'direction-finder':  startDirectionFinderWorker,
  'france-travail':    startFranceTravailWorker,
  'mesri':             startMesriWorker,
  'crunchbase':        startCrunchbaseWorker,
  'infogreffe':        startInfogreffeWorker,
  'societe-com':       startSocieteComWorker,
  'social-light':      startSocialLightWorker,
};

async function main(): Promise<void> {
  const type = (process.env['WORKER_TYPE'] ?? 'google-maps') as WorkerType;
  const factory = REGISTRY[type];
  if (!factory) {
    log.fatal({ type }, 'Unknown WORKER_TYPE');
    process.exit(1);
  }

  // Healthcheck HTTP server (port 9100, /healthz /readyz /metrics)
  const { startHealthcheckServer } = await import('./healthcheck-server');
  startHealthcheckServer();

  // C18-017 — cette ligne n'imprimait que `MOCK_MODE`, alors que `MOCK_SCRAPERS`
  // le MASQUE. L'opérateur lisait donc `mockMode=false` sur un worker qui
  // servait des fixtures. On imprime la DÉCISION, pas une des deux poignées.
  log.info(
    {
      type,
      mockScrapers: process.env['MOCK_SCRAPERS'] ?? null,
      mockMode: process.env['MOCK_MODE'] ?? null,
      simulacres: useMockScrapers(),
    },
    'Worker booting',
  );
  await factory();
}

main().catch((err) => {
  log.fatal({ err }, 'Worker crashed at boot');
  process.exit(1);
});
