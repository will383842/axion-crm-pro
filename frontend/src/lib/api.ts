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
    const status = error?.response?.status;
    const code = error?.response?.data?.error;

    if (status === 401 && !window.location.pathname.startsWith('/login')) {
      window.location.assign('/login');
      return Promise.reject(error);
    }

    // Le serveur EXIGE desormais la double authentification (middleware
    // EnsureTwoFactorPassed) : tant qu'elle n'est pas franchie, toute route
    // metier repond 403 `two_factor_required`. Sans ce renvoi, l'utilisateur
    // resterait devant un ecran en erreur sans savoir quoi faire - alors que
    // l'etape suivante tient en six chiffres.
    if (status === 403 && code === 'two_factor_required' && !window.location.pathname.startsWith('/2fa')) {
      window.location.assign('/2fa');
      return Promise.reject(error);
    }

    // Meme raisonnement pour la premiere connexion : le serveur renvoie vers
    // l'enrolement, l'interface doit y conduire.
    if (status === 403 && code === 'first_login_required' && !window.location.pathname.startsWith('/2fa')) {
      window.location.assign('/2fa');
      return Promise.reject(error);
    }

    return Promise.reject(error);
  },
);
