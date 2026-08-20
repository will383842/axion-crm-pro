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

// ═══════════════════════════════════════════════════════════════════════════
// QUALIFIER UN ÉCHEC — le point UNIQUE où l'on décide ce qu'une erreur signifie
// ═══════════════════════════════════════════════════════════════════════════
//
// 🔴 DÉFAUT D25-001, mesuré par la passe P6 de l'audit 360 :
//    `grep -rn isError src/features` → 9 occurrences dans 4 fichiers, sur 35
//    écrans. Les 31 autres lisent `query.data?.data ?? []`, trouvent un tableau
//    vide et en concluent « il n'y a rien ». Sous 403 (droits manquants), sous
//    500 (base tombée) et sous 200-liste-vide, ces écrans rendaient un texte
//    STRICTEMENT IDENTIQUE — « Rien à arbitrer », « Aucun utilisateur ».
//
//    Trois situations, trois gestes OPPOSÉS (demander un rôle / alerter
//    l'exploitation / ne rien faire), une seule phrase.
//
//    Et les 4 écrans qui traitaient l'erreur ne lisaient pas davantage le code
//    HTTP : `RoumaniePage.tsx:166` affiche « Impossible de charger le vivier
//    Roumanie » aussi bien sous 403 que sous 500.
//
// Le raisonnement vit ICI, une fois, et non dupliqué 35 fois — 35 copies, ce
// sont 35 occasions de diverger. Les écrans ne testent plus de code HTTP : ils
// passent l'erreur telle quelle à `<QueryErrorState error={…} />`.

/**
 * Ce qu'un échec d'API SIGNIFIE pour la personne devant l'écran.
 *
 *  - `session`     : 401 — reconnecte-toi (l'intercepteur ci-dessus redirige
 *                    déjà, mais la promesse est rejetée le temps du saut).
 *  - `refus`       : 403 — le serveur a répondu, et il a dit NON. Question de
 *                    rôle, jamais de panne. Réessayer est inutile.
 *  - `introuvable` : 404 — cette ressource n'existe pas. Différent de « vide ».
 *  - `requete`     : autre 4xx (422 validation, 429 quota) — la demande est en
 *                    cause, pas le serveur.
 *  - `panne`       : 5xx — le serveur a échoué. RIEN ne dit qu'il n'y a pas de
 *                    données : c'est tout l'objet de ce lot.
 *  - `reseau`      : aucune réponse (DNS, VPN coupé, délai de 30 s dépassé,
 *                    cf. `timeout` ligne 10). Pas de code HTTP du tout.
 *  - `inconnue`    : ce n'est pas une erreur axios. On n'invente rien.
 */
export type NatureErreurApi =
  | 'session'
  | 'refus'
  | 'introuvable'
  | 'requete'
  | 'panne'
  | 'reseau'
  | 'inconnue';

export interface ErreurQualifiee {
  nature: NatureErreurApi;
  /** Le code HTTP, ou `null` quand le serveur n'a JAMAIS répondu. */
  status: number | null;
  /** Le code applicatif du corps (`{"error":"two_factor_required"}`), ou `null`. */
  code: string | null;
}

function natureDuStatut(status: number): NatureErreurApi {
  if (status === 401) return 'session';
  if (status === 403) return 'refus';
  if (status === 404) return 'introuvable';
  if (status >= 500) return 'panne';
  if (status >= 400) return 'requete';
  // 1xx/2xx/3xx en rejet : axios ne produit pas ça, mais rien ne doit être
  // requalifié en « refus » par défaut — on enverrait l'utilisateur réclamer
  // un droit qu'il possède déjà.
  return 'inconnue';
}

/**
 * Lit un rejet de promesse et dit ce qu'il est. N'ÉCRIT rien, ne redirige pas.
 *
 * ⚠️ `status: null` et non `0` quand il n'y a pas eu de réponse : un écran qui
 * afficherait « code HTTP 0 » ferait croire que le serveur a parlé.
 */
export function qualifierErreur(error: unknown): ErreurQualifiee {
  if (!isAxiosError(error)) return { nature: 'inconnue', status: null, code: null };

  const erreur = error as AxiosError<ReponseErreurApi>;
  const reponse = erreur.response;

  // Pas de `response` = le serveur n'a jamais répondu. C'est le cas du VPN
  // coupé, du DNS mort et de l'expiration du délai — aucun rapport avec un
  // serveur qui répond 500.
  if (reponse === undefined) return { nature: 'reseau', status: null, code: null };

  const brut = reponse.data?.error;
  return {
    nature: natureDuStatut(reponse.status),
    status: reponse.status,
    // Une chaîne vide n'est pas un code : on la ramène à `null` pour qu'un
    // appelant puisse écrire `if (code === null)` sans piège.
    code: typeof brut === 'string' && brut.length > 0 ? brut : null,
  };
}
