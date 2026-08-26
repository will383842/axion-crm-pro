import { Link, useParams } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Button, Card, EmptyState, PageHeader, Spinner, StatusPill } from "@/components/ui";
import { api } from "@/lib/api";
// Saisie et historique viennent du panneau partagé : cette page n'en détient
// plus de copie. Cf. l'en-tête de `JournalPresse.tsx`.
import { ConsignerEchange, HistoriqueEchanges, dateFr, type TimelineItem } from "./JournalPresse";

/**
 * Fiche d'un contact presse : qui il est, par quelle porte on l'atteint, et
 * TOUT ce qu'on lui a envoyé ou qu'on s'est dit.
 *
 * Deux partis pris d'affichage, qui ne sont pas cosmétiques :
 *
 *  1. **La porte d'accès est affichée en haut, en couleur, avec sa conséquence
 *     écrite.** Trois contacts sur quatre ne doivent PAS recevoir de mailing —
 *     un contact d'émission TV se joint par sa production, sinon il est brûlé.
 *     Ranger cette information dans un tableau de détails la rendrait
 *     consultable ; la mettre en tête la rend impossible à manquer.
 *
 *  2. **Un média non rattaché le DIT.** `media_raw` sans `media_id` s'affiche
 *     comme « à rattacher », pas comme un nom de média ordinaire. Sans ce
 *     signal, une chaîne brute (« Chroniqueur BFM Business, CEO Le Crayon
 *     Groupe ») passerait pour une rédaction identifiée, et personne ne
 *     saurait qu'il reste un arbitrage à rendre.
 */

interface JournalistDetail {
  id: number;
  first_name: string | null;
  last_name: string | null;
  role: string | null;
  beat: string | null;
  email: string | null;
  phone: string | null;
  opt_out: boolean;
  media_id: number | null;
  media_raw: string | null;
  media?: { id: number; name: string } | null;
  acces: string | null;
  linkedin_slug: string | null;
  lien_linkedin: string;
  lien_linkedin_le: string | null;
  priorite: number | null;
  score: number | null;
  abonnes: number | null;
  media_portee_raw: string | null;
  media_support_raw: string | null;
  collecte_le: string | null;
  source: string | null;
  source_url: string | null;
}

interface ShowResponse {
  data: JournalistDetail;
  timeline: TimelineItem[];
}

/** Ce que chaque porte d'accès AUTORISE — la conséquence, pas l'étiquette. */
const ACCES: Record<string, { label: string; consequence: string; diffusable: boolean }> = {
  email_redaction: {
    label: "Email rédaction",
    consequence: "Diffusable par mailing.",
    diffusable: true,
  },
  redaction_prod: {
    label: "Rédaction / production",
    consequence: "NE PAS envoyer en direct — passer par la production de l'émission.",
    diffusable: false,
  },
  linkedin_direct: {
    label: "LinkedIn direct",
    consequence: "Pas d'email. Message envoyé à la main, puis consigné ici.",
    diffusable: false,
  },
  a_qualifier: {
    label: "À qualifier",
    consequence: "Rédaction non identifiée — hors diffusion tant que ce n'est pas tranché.",
    diffusable: false,
  },
};

const LIENS: Record<string, string> = {
  inconnu: "Jamais vérifié",
  non_connecte: "Pas en relation",
  demande_envoyee: "Demande envoyée, sans réponse",
  connecte: "En relation (1er)",
  abonne: "Abonné seulement",
  refuse: "Demande déclinée",
};

export function JournalistDetailPage() {
  const { journalistId } = useParams({ strict: false });

  const { data, isLoading, isError } = useQuery<ShowResponse>({
    queryKey: ["journalist", journalistId],
    queryFn: async () => {
      const r = await api.get<ShowResponse>(`/journalists/${journalistId}`);
      return r.data;
    },
    enabled: !!journalistId,
  });

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div className="px-6 py-6">
        <EmptyState icon="🎙️" title="Contact introuvable" description="Cette fiche n'existe pas ou a été supprimée." />
      </div>
    );
  }

  const j = data.data;
  const nom = [j.first_name, j.last_name].filter(Boolean).join(" ") || "Sans nom";
  const acces = j.acces ? ACCES[j.acces] : null;
  const rattache = j.media_id !== null && j.media != null;

  const faits: Array<[string, string | null]> = [
    ["Rôle", j.role],
    ["Rubrique", j.beat],
    ["Portée du média", j.media_portee_raw],
    ["Support", j.media_support_raw],
    ["Priorité", j.priorite ? `P${j.priorite}` : null],
    ["Score de ciblage", j.score ? `${j.score} / 110` : null],
    ["Abonnés LinkedIn", j.abonnes ? j.abonnes.toLocaleString("fr-FR") : null],
    ["Téléphone", j.phone],
    ["Provenance", j.source],
    ["Collecté le", j.collecte_le ? dateFr(j.collecte_le) : null],
  ];

  return (
    <div className="px-6 py-6">
      <PageHeader
        title={nom}
        subtitle={
          rattache ? (
            <Link to="/media/$mediaId" params={{ mediaId: String(j.media_id) }} className="hover:underline">
              {j.media!.name}
            </Link>
          ) : (
            <span className="text-amber-700 dark:text-amber-400">
              {j.media_raw ?? "Aucun média"} · <strong>à rattacher</strong>
            </span>
          )
        }
        actions={
          <Link to="/journalists">
            <Button variant="secondary" size="md">← Tous les journalistes</Button>
          </Link>
        }
      />

      {/* ── La porte d'accès, et ce qu'elle interdit ─────────────────────── */}
      {acces && (
        <Card
          className={
            acces.diffusable
              ? "mb-6 border-emerald-300 bg-emerald-50/60 dark:border-emerald-800 dark:bg-emerald-950/30"
              : "mb-6 border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/30"
          }
        >
          <div className="flex flex-wrap items-center gap-3">
            <StatusPill tone={acces.diffusable ? "success" : "warning"}>{acces.label}</StatusPill>
            <span className="text-sm text-slate-700 dark:text-slate-200">{acces.consequence}</span>
          </div>
        </Card>
      )}

      {j.opt_out && (
        <Card className="mb-6 border-rose-300 bg-rose-50/60 dark:border-rose-800 dark:bg-rose-950/30">
          <span className="text-sm font-semibold text-rose-800 dark:text-rose-300">
            Opposition RGPD — ce contact ne doit plus être sollicité, quel que soit le canal.
          </span>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        {/* ── Identité ──────────────────────────────────────────────────── */}
        <div className="space-y-6">
          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Coordonnées</h2>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-slate-500 dark:text-slate-400">Email</dt>
                <dd className="text-right text-slate-900 dark:text-white">
                  {j.email ?? <span className="text-amber-700 dark:text-amber-400">à trouver</span>}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-slate-500 dark:text-slate-400">LinkedIn</dt>
                <dd className="text-right">
                  {j.linkedin_slug ? (
                    <a
                      href={`https://www.linkedin.com/in/${j.linkedin_slug}`}
                      target="_blank"
                      rel="noreferrer noopener"
                      className="text-sky-700 hover:underline dark:text-sky-400"
                    >
                      /in/{j.linkedin_slug}
                    </a>
                  ) : (
                    <span className="text-slate-400">—</span>
                  )}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-slate-500 dark:text-slate-400">Relation</dt>
                <dd className="text-right text-slate-900 dark:text-white">
                  {LIENS[j.lien_linkedin] ?? j.lien_linkedin}
                  {j.lien_linkedin_le && (
                    <span className="ml-1 text-xs text-slate-500">· {dateFr(j.lien_linkedin_le)}</span>
                  )}
                </dd>
              </div>
            </dl>
          </Card>

          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Ciblage</h2>
            <dl className="space-y-2 text-sm">
              {faits
                .filter(([, v]) => v)
                .map(([k, v]) => (
                  <div key={k} className="flex justify-between gap-4">
                    <dt className="text-slate-500 dark:text-slate-400">{k}</dt>
                    <dd className="text-right text-slate-900 dark:text-white">{v}</dd>
                  </div>
                ))}
            </dl>
            {j.source_url && (
              <p className="mt-4 text-xs text-slate-500">
                Provenance :{" "}
                <a href={j.source_url} target="_blank" rel="noreferrer noopener" className="hover:underline">
                  {j.source_url}
                </a>
              </p>
            )}
          </Card>
        </div>

        {/* ── Échanges ─────────────────────────────────────────────────
            Panneau PARTAGÉ avec la fiche rédaction (JournalPresse.tsx) :
            une seconde copie aurait divergé au premier motif ajouté. */}
        <div className="space-y-6">
          <ConsignerEchange
            endpoint={`/journalists/${journalistId}/activities`}
            queryKey={["journalist", journalistId]}
          />
          <HistoriqueEchanges timeline={data.timeline} />
        </div>
      </div>
    </div>
  );
}
