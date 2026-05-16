// Healthcheck HTTP minimal pour Prometheus /metrics + readiness probe
import { createServer } from 'node:http';
import pino from 'pino';
import { getRedis } from './bridge/redis';

const log = pino({ name: 'healthcheck-server' });
const PORT = Number(process.env['HEALTHCHECK_PORT'] ?? 9100);

let lastJobAt = Date.now();
export function tickJob(): void {
  lastJobAt = Date.now();
}

export function startHealthcheckServer(): void {
  const server = createServer((req, res) => {
    if (req.url === '/healthz') {
      res.statusCode = 200;
      res.setHeader('content-type', 'text/plain');
      res.end('ok\n');
      return;
    }
    if (req.url === '/readyz') {
      void getRedis().ping()
        .then(() => { res.statusCode = 200; res.end('ready\n'); })
        .catch(() => { res.statusCode = 503; res.end('not_ready\n'); });
      return;
    }
    if (req.url === '/metrics') {
      const idleS = Math.floor((Date.now() - lastJobAt) / 1000);
      res.setHeader('content-type', 'text/plain; version=0.0.4');
      res.end(
        `# HELP worker_last_job_seconds_ago Seconds since last job\n` +
        `# TYPE worker_last_job_seconds_ago gauge\n` +
        `worker_last_job_seconds_ago{worker="${process.env['WORKER_TYPE'] ?? 'unknown'}"} ${idleS}\n` +
        `# HELP worker_up Worker is up\n` +
        `# TYPE worker_up gauge\n` +
        `worker_up{worker="${process.env['WORKER_TYPE'] ?? 'unknown'}"} 1\n`,
      );
      return;
    }
    res.statusCode = 404;
    res.end('not found\n');
  });

  server.listen(PORT, () => log.info({ port: PORT }, 'Healthcheck server started'));
}
