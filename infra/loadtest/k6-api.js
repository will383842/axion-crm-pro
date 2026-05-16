// Axion CRM Pro — load test k6
// Cible : 100 req/s API tient pendant 2 min sur les endpoints lecture les plus chauds.
// Usage : k6 run --vus 50 --duration 2m infra/loadtest/k6-api.js

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const errorRate = new Rate('error_rate');
const apiLatency = new Trend('api_latency_ms');

const BASE = __ENV.E2E_BASE_URL || 'https://api.localhost';

export const options = {
  vus: 50,
  duration: '2m',
  thresholds: {
    'http_req_duration{type:api}': ['p(95)<1000', 'p(99)<2000'],
    'error_rate': ['rate<0.01'],
    'http_req_failed': ['rate<0.01'],
  },
  insecureSkipTLSVerify: true,
};

const ENDPOINTS = [
  '/api/v1/companies?per_page=25',
  '/api/v1/coverage?level=department',
  '/api/v1/scraper-runs?per_page=10',
  '/api/v1/llm/use-cases',
  '/api/v1/audit-logs',
];

export default function () {
  const url = BASE + ENDPOINTS[Math.floor(Math.random() * ENDPOINTS.length)];
  const r = http.get(url, { tags: { type: 'api' } });

  const ok = check(r, {
    'status 2xx': (res) => res.status >= 200 && res.status < 300,
    'p < 1000ms': (res) => res.timings.duration < 1000,
  });

  errorRate.add(!ok);
  apiLatency.add(r.timings.duration);

  sleep(Math.random() * 0.5);
}
