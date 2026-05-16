import Redis from 'ioredis';

async function main(): Promise<void> {
  const url = process.env['WORKER_REDIS_URL'] ?? 'redis://localhost:6379/1';
  const client = new Redis(url, { maxRetriesPerRequest: 1, lazyConnect: true });
  try {
    await client.connect();
    const pong = await client.ping();
    if (pong !== 'PONG') process.exit(2);
    await client.quit();
    process.exit(0);
  } catch {
    process.exit(3);
  }
}

main();
