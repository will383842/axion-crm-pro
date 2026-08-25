import { useState } from "react";
import { Link, useParams } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, Card, EmptyState, Input, PageHeader, Spinner, StatusPill } from "@/components/ui";
import { api } from "@/lib/api";
import { toast } from "sonner";

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

interface TimelineItem {
  id: number;
  kind: string | null;
  title: string | null;
  content: string | null;
  occurred_at: string | null;
  created_at: string | null;
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

const KINDS: Array<{ value: string; label: string }> = [
  { value: "press_release_sent", label: "Communiqué envoyé" },
  { value: "press_followup", label: "Relance" },
  { value: "press_reply", label: "Réponse reçue" },
  { value: "press_coverage", label: "Retombée publiée" },
  { value: "linkedin_message", label: "Message LinkedIn" },
  { value: "call", label: "Appel" },
];

function dateFr(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
}

export function JournalistDetailPage() {
  const { journalistId } = useParams({ strict: false });
  const queryClient = useQueryClient();

  const [kind, setKind] = useState(KINDS[0].value);
  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");

  const { data, isLoading, isError } = useQuery<ShowResponse>({
    queryKey: ["journalist", journalistId],
    queryFn: async () => {
      const r = await api.get<ShowResponse>(`/journalists/${journalistId}`);
      return r.data;
    },
    enabled: !!journalistId,
  });

  const consigner = useMutation({
    mutationFn: async () => {
      await api.post(`/journalists/${journalistId}/activities`, { kind, title, content: content || null });
    },
    onSuccess: () => {
      setTitle("");
      setContent("");
      toast.success("Échange consigné");
      void queryClient.invalidateQueries({ queryKey: ["journalist", journalistId] });
    },
    onError: () => toast.error("La consignation a échoué"),
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

        {/* ── Échanges ──────────────────────────────────────────────────── */}
        <div className="space-y-6">
          <Card>
            <h2 className="mb-4 text-sm font-semibold text-slate-900 dark:text-white">Consigner un échange</h2>
            <div className="space-y-3">
              <select
                value={kind}
                onChange={(e) => setKind(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white"
              >
                {KINDS.map((k) => (
                  <option key={k.value} value={k.value}>
                    {k.label}
                  </option>
                ))}
              </select>
              <Input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="En une ligne : ce qui s'est passé"
              />
              <textarea
                value={content}
                onChange={(e) => setContent(e.target.value)}
                rows={3}
                placeholder="Détail (facultatif)"
                className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white"
              />
              <Button
                variant="primary"
                size="md"
                disabled={!title.trim() || consigner.isPending}
                onClick={() => consigner.mutate()}
              >
                {consigner.isPending ? "Enregistrement…" : "Consigner"}
              </Button>
            </div>
          </Card>

          <Card padding="none" className="overflow-hidden">
            <h2 className="border-b border-slate-200 px-5 py-4 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
              Historique · {data.timeline.length}
            </h2>
            {data.timeline.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-500">
                Aucun échange consigné. Le premier communiqué envoyé apparaîtra ici.
              </p>
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.timeline.map((t) => (
                  <li key={t.id} className="px-5 py-3">
                    <div className="flex items-baseline justify-between gap-3">
                      <span className="text-sm font-medium text-slate-900 dark:text-white">
                        {t.title ?? KINDS.find((k) => k.value === t.kind)?.label ?? t.kind}
                      </span>
                      <span className="shrink-0 text-xs text-slate-500 tabular-nums">
                        {dateFr(t.occurred_at ?? t.created_at)}
                      </span>
                    </div>
                    <span className="text-xs text-slate-500">
                      {KINDS.find((k) => k.value === t.kind)?.label ?? t.kind}
                    </span>
                    {t.content && (
                      <p className="mt-1 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300">{t.content}</p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
