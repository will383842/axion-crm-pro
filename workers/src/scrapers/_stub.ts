import pino from 'pino';

/**
 * Sprint 1 stub : tous les workers non encore implémentés ne crashent pas — ils logguent
 * juste leur démarrage et restent idle. Les Sprints 6-7-8 implémenteront chacun leur impl.
 */
export async function stubWorker(name: string): Promise<void> {
  const log = pino({ name });
  log.info({ name, sprint_status: 'stub' }, 'Worker stub started — waits for sprint implementation');
}
