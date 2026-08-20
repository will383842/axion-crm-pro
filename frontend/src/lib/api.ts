import axios, { isAxiosError } from 'axios';
import type { AxiosError } from 'axios';

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

// 🔴 Cet intercepteur travaillait sur un `error` implicitement `any` :
// `error?.response?.status` et `error?.response?.data?.error` traversaient trois
// accès non typés. La porte `pnpm lint` du dépôt, DÉCLARÉE BLOQUANTE dans
// `ci.yml`, les refusait — quatre erreurs `no-unsafe-*` et quatre
// `prefer-promise-reject-errors` sur ce seul fichier.
//
// Ce n'est pas une coquetterie de typage : sans forme déclarée, rien
// n'empêchait de lire `data.errors` (au pluriel) ou `data.code` et de croire
// que la redirection marchait. Le contrat du serveur est écrit ici, une fois.
type ReponseErreurApi = { error?: string };

api.interceptors.response.use(
  (resp) => resp,
  (error: unknown) => {
    const erreur = isAxiosError(error) ? (error as AxiosError<ReponseErreurApi>) : null;
    const status = erreur?.response?.status;
    const code = erreur?.response?.data?.error;

    // `prefer-promise-reject-errors` : on rejette avec une valeur dont le type
    // est établi, jamais avec un `unknown`. Le rejet reste l'objet d'origine
    // quand c'est bien une erreur Axios — les appelants qui lisent
    // `err.response` continuent de fonctionner à l'identique.
    const rejet = erreur ?? (error instanceof Error ? error : new Error(String(error)));

    if (status === 401 && !window.location.pathname.startsWith('/login')) {
      window.location.assign('/login');
      return Promise.reject(rejet);
    }

    // Le serveur EXIGE desormais la double authentification (middleware
    // EnsureTwoFactorPassed) : tant qu'elle n'est pas franchie, toute route
    // metier repond 403 `two_factor_required`. Sans ce renvoi, l'utilisateur
    // resterait devant un ecran en erreur sans savoir quoi faire - alors que
    // l'etape suivante tient en six chiffres.
    if (status === 403 && code === 'two_factor_required' && !window.location.pathname.startsWith('/2fa')) {
      window.location.assign('/2fa');
      return Promise.reject(rejet);
    }

    // Meme raisonnement pour la premiere connexion : le serveur renvoie vers
    // l'enrolement, l'interface doit y conduire.
    if (status === 403 && code === 'first_login_required' && !window.location.pathname.startsWith('/2fa')) {
      window.location.assign('/2fa');
      return Promise.reject(rejet);
    }

    return Promise.reject(rejet);
  },
);
