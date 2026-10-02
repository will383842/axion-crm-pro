/**
 * RÉGLAGES TECHNIQUES — déplacés des Paramètres (audit UX du 2026-10-02, P1-12).
 *
 * Les Paramètres sont un écran pour un humain ; ce qui suit parle au
 * technicien : clés du serveur (noms de variables d'environnement), suivi des
 * erreurs (Sentry), outils de file d'attente (Horizon, Telescope). Rien n'a été
 * retiré : tout est affiché sous « Technique › Santé du système ».
 *
 * Historique des commandes mortes retirées (D26-003) : voir l'en-tête de
 * `SettingsPage.tsx`. Garde : `tests/screens/SettingsPage.commandes-mortes.test.tsx`.
 */
import { ExternalLink } from "lucide-react";
import { Card, CardEyebrow, CardHeader, CardTitle, StatusPill } from "@/components/ui";

/**
 * ⚠️ `role` et NON `status`. Le champ s'appelait `status: 'configured' | …` et
 * l'écran en tirait une pastille « Configuré » : une affirmation sur l'état du
 * SERVEUR, écrite dans le FRONTEND, qu'aucune lecture n'étayait. Ce qu'on peut
 * dire honnêtement d'ici, c'est la place de l'intégration dans le produit —
 * requise, optionnelle, ou repoussée à une phase ultérieure. Pas si la clé est
 * renseignée.
 */
interface Integration {
  name: string;
  env: string;
  description: string;
  role: "requise" | "optionnelle" | "phase-b";
}

const INTEGRATIONS: Integration[] = [
  {
    name: "INSEE Sirene",
    env: "INSEE_API_KEY",
    description: "Base entreprises + données légales (gratuit, 500 req/min)",
    role: "requise",
  },
  {
    name: "France Travail",
    env: "FRANCE_TRAVAIL_CLIENT_ID",
    description: "Offres d'emploi + intentions de recrutement (gratuit)",
    role: "requise",
  },
  {
    name: "Mistral AI",
    env: "MISTRAL_API_KEY",
    description:
      "IA principale : classe les entreprises par activité (hébergée en France, ~5 €/mois)",
    role: "requise",
  },
  {
    name: "Anthropic Claude",
    env: "ANTHROPIC_API_KEY",
    description: "IA d’appoint pour les tâches les plus délicates (optionnelle)",
    role: "optionnelle",
  },
  // Sprint H9 + H12 — Google Places API officielle (enrichissement auto, garde-fou quota)
  {
    name: "Google Places",
    env: "GOOGLE_PLACES_API_KEY",
    description:
      "Complète téléphone, horaires, site et note Google (gratuit jusqu’à 12 000 fiches par mois, plafond surveillé)",
    role: "optionnelle",
  },
  {
    name: "Webshare (serveurs relais)",
    env: "WEBSHARE_USERNAME",
    description: "Serveurs relais pour consulter les Pages Jaunes (~30 $/mois, plus tard)",
    role: "phase-b",
  },
  {
    name: "2captcha",
    env: "TWOCAPTCHA_API_KEY",
    description: "Résolution des tests anti-robots (plus tard, seulement pour Google)",
    role: "phase-b",
  },
];

const LIBELLE_ROLE: Record<Integration["role"], string> = {
  requise: "Requise",
  optionnelle: "Optionnelle",
  "phase-b": "Plus tard",
};

const TON_ROLE: Record<Integration["role"], "success" | "info" | "warning"> = {
  requise: "success",
  optionnelle: "info",
  "phase-b": "warning",
};

// Lot 4 (audit P1-4) — les six adresses `http://localhost:…` (Prometheus,
// Grafana, Loki, Tempo, GlitchTip, Uptime Kuma) ont été retirées : en
// production elles ne mènent nulle part.
const OBSERVABILITY_LINKS: Array<{ name: string; url: string; description: string }> = [
  { name: "Horizon", url: "/horizon", description: "Traitements en file d’attente" },
  { name: "Telescope", url: "/telescope", description: "Diagnostic, en local seulement" },
];

export function ReglagesTechniques() {
  return (
    <section aria-labelledby="reglages-techniques" className="space-y-6">
      <h2 id="reglages-techniques" className="text-lg font-semibold text-slate-900">
        Réglages techniques
      </h2>

      <h3 className="text-sm font-semibold text-slate-700">Intégrations</h3>
      <div className="space-y-3">
        {/*
            🔴 CE QUI A ÉTÉ RETIRÉ ICI, ET POURQUOI IL NE FAUT PAS LE REMETTRE :
            — 14 boutons « Renouveler » / « Configurer » sans `onClick` ;
            — une pastille de secret dont la valeur (`sk-•••••`) était une
              constante du frontend, que « Afficher » révélait fièrement ;
            — un état « Configuré » écrit en dur, sans aucune lecture.
            Tant qu'aucune route n'expose l'état réel des clés, la seule position
            tenable est de le DIRE.
          */}
        <p className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
          Ces clés sont des <strong>secrets du serveur</strong>, lus dans son environnement. Aucune
          route de l’API ne les expose, même masquées&nbsp;: cet écran{" "}
          <strong>ne peut donc pas dire</strong> lesquelles sont renseignées, ni les modifier. Elles
          se règlent dans les variables d’environnement du déploiement.
        </p>

        <div className="grid gap-3 md:grid-cols-2">
          {INTEGRATIONS.map((i) => (
            <Card key={i.env}>
              <CardHeader>
                <div className="min-w-0">
                  <CardEyebrow>{i.env}</CardEyebrow>
                  <CardTitle className="mt-1 truncate text-base">{i.name}</CardTitle>
                </div>
                {/* Ce que l'écran sait vraiment : la place de l'intégration dans
                      le produit. Pas si sa clé est posée sur le serveur. */}
                <StatusPill tone={TON_ROLE[i.role]}>{LIBELLE_ROLE[i.role]}</StatusPill>
              </CardHeader>
              <p className="text-sm text-slate-600 dark:text-slate-300">{i.description}</p>
            </Card>
          ))}
        </div>
      </div>

      <h3 className="text-sm font-semibold text-slate-700">Suivi des erreurs et des traitements</h3>
      <div className="space-y-4">
        <Card>
          <CardHeader>
            <div>
              <CardEyebrow>Sentry / GlitchTip</CardEyebrow>
              <CardTitle className="mt-1 text-base">Suivi des erreurs</CardTitle>
            </div>
          </CardHeader>
          {/*
              🔴 LE CHAMP « DSN Sentry » A ÉTÉ RETIRÉ. Il n'avait ni `name`, ni
              `onChange`, ni `<form>`, ni bouton — tout ce qui y était saisi
              était jeté. Et il ne pouvait pas en être autrement :
              `src/lib/sentry.ts:13` lit `import.meta.env.VITE_SENTRY_DSN`, une
              valeur figée AU MOMENT DU BUILD de l'image. Aucune saisie faite
              dans le navigateur ne peut la changer.
            */}
          <p className="text-sm text-slate-600 dark:text-slate-300">
            Le DSN est lu dans <code className="font-mono text-xs">VITE_SENTRY_DSN</code>{" "}
            <strong>au moment du build</strong> de l’image (cf.{" "}
            <code className="font-mono text-xs">Dockerfile.frontend</code>). Il ne se règle pas
            depuis cet écran, et ne le pourrait pas&nbsp;: il est compilé dans le paquet servi au
            navigateur.
          </p>
          <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
            État de <em>ce</em> build&nbsp;:{" "}
            {import.meta.env["VITE_SENTRY_DSN"] ? (
              <StatusPill tone="success">DSN présent — le SDK est actif</StatusPill>
            ) : (
              <StatusPill tone="info">aucun DSN — le SDK reste inerte</StatusPill>
            )}
          </p>
        </Card>

        <div>
          <p className="mb-2 text-xs text-slate-500">Outils de suivi des traitements du serveur.</p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {OBSERVABILITY_LINKS.map((link) => (
              <a
                key={link.name}
                href={link.url}
                target="_blank"
                rel="noopener noreferrer"
                className="group rounded-2xl bg-white p-4 ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-md dark:bg-slate-900 dark:ring-slate-800"
              >
                <div className="mb-1 flex items-center justify-between">
                  <p className="text-sm font-semibold text-slate-900 dark:text-white">
                    {link.name}
                  </p>
                  <ExternalLink className="h-3.5 w-3.5 text-slate-400 transition group-hover:text-slate-700 dark:group-hover:text-slate-200" />
                </div>
                <p className="text-xs text-slate-500">{link.description}</p>
                <p className="mt-2 truncate font-mono text-xs text-slate-400">{link.url}</p>
              </a>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}
