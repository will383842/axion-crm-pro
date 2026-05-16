import Redis from 'ioredis';

let connection: Redis | null = null;

export function getRedis(): Redis {
  if (connection) return connection;
  const url = process.env['WORKER_REDIS_URL'] ?? 'redis://localhost:6379/1';
  connection = new Redis(url, { maxRetriesPerRequest: null, enableReadyCheck: false });
  return connection;
}
