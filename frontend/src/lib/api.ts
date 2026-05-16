import axios from 'axios';

const baseURL = import.meta.env['VITE_API_BASE_URL'] ?? 'https://api.localhost';

export const api = axios.create({
  baseURL: `${baseURL}/api/v1`,
  withCredentials: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  timeout: 30_000,
});

// CSRF token (Sanctum SPA) — récupère le cookie XSRF-TOKEN une seule fois
let csrfFetched = false;
export async function ensureCsrf(): Promise<void> {
  if (csrfFetched) return;
  await axios.get(`${baseURL}/sanctum/csrf-cookie`, { withCredentials: true });
  csrfFetched = true;
}

api.interceptors.request.use(async (config) => {
  if (['post', 'put', 'patch', 'delete'].includes((config.method ?? '').toLowerCase())) {
    await ensureCsrf();
  }
  return config;
});

api.interceptors.response.use(
  (resp) => resp,
  (error) => {
    if (error?.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      window.location.assign('/login');
    }
    return Promise.reject(error);
  },
);
