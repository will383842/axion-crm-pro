import { useState } from "react";
import { Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Card, EmptyState, Input, PageHeader, Spinner } from "@/components/ui";
import { api } from "@/lib/api";
import { KINDS, dateFr, libelleKind } from "./JournalPresse";

/**
 * REGISTRE DES ENVOIS PRESSE — « à qui a-t-on envoyé quoi, et quand ».
 *
 * ── Ce que cet écran n'est PAS ─────────────────────────────────────────────
 * Ce n'est pas la liste d'une table `communiques_envoyes` : cette table
 * n'existe pas, et c'est délibéré. L'écran LIT la timeline unifiée
 * (`activities`), filtrée sur les natures presse. Conséquence directe et
 * voulue : le suivi demandé pour la presse vaut du même coup pour tous les
 * contacts du CRM, puisque c'est le même registre.
 *
 * ── Deux colonnes plutôt qu'une ────────────────────────────────────────────
 * « Cible » dit à QUI c'est parti (une rédaction, ou une personne). « Rédaction »
 * dit à quel TITRE. Les fondre ferait disparaître l'information la plus utile
 * pour décider d'une relance : savoir qu'on a déjà écrit trois fois au même
 * journal, par trois personnes différentes.
 */
interface EnvoiItem {
  id: number;
  kind: string | null;
  title: string | null;
  content: string | null;
  occurred_at: string | null;
  created_at: string | null;
  subject_type: string;
  subject_id: number;
  cible: string;
  cible_type: "redaction" | "journaliste";
  /** `null` = rattachement de la personne à sa rédaction encore à faire. */
  redaction: string | null;
}

interface EnvoisResponse {
  data: EnvoiItem[];
  meta: { total: number; per_page: number; current_page: number; last_page: number };
}

export function EnvoisPressePage() {
  const [kind, setKind] = useState<string>("");
  const [q, setQ] = useState("");

  const { data, isLoading, isError } = useQuery<EnvoisResponse>({
    queryKey: ["presse-envois", kind, q],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (kind) params.set("kind", kind);
      if (q.trim()) params.set("q", q.trim());
      const r = await api.get<EnvoisResponse>(`/presse/envois?${params.toString()}`);
      return r.data;
    },
  });

  // Seules les natures listées par le back sont acceptées en filtre : proposer
  // « Appel » ici renverrait un registre vide sans expliquer pourquoi.
  const naturesFiltrables = KINDS.filter((k) => k.value.startsWith("press_"));

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Communiqués envoyés"
        subtitle="Ce qui est parti à la presse, à qui, et quand — envois, relances, réponses et retombées."
      />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <select
          value={kind}
          onChange={(e) => setKind(e.target.value)}
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white"
        >
          <option value="">Tous les gestes presse</option>
          {naturesFiltrables.map((k) => (
            <option key={k.value} value={k.value}>
              {k.label}
            </option>
          ))}
        </select>
        <Input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Rechercher dans l'objet de l'envoi…"
          className="max-w-xs"
        />
        {data ? <span className="text-sm text-slate-500">{data.meta.total} ligne(s)</span> : null}
      </div>

      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Spinner />
        </div>
      ) : isError ? (
        <EmptyState
          icon="⚠️"
          title="Registre indisponible"
          description="Le registre des envois n'a pas pu être chargé."
        />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon="📨"
          title="Aucun envoi consigné"
          description="Les envois se consignent depuis la fiche d'une rédaction ou d'un journaliste. Ils apparaîtront ici."
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-[11px] uppercase text-slate-500 dark:border-slate-800">
                <th className="px-4 py-2 text-left">Date</th>
                <th className="px-4 py-2 text-left">Geste</th>
                <th className="px-4 py-2 text-left">Cible</th>
                <th className="px-4 py-2 text-left">Rédaction</th>
                <th className="px-4 py-2 text-left">Objet</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((e) => (
                <tr key={e.id} className="border-b border-slate-100 dark:border-slate-800">
                  <td className="px-4 py-2 whitespace-nowrap text-slate-500 tabular-nums">
                    {dateFr(e.occurred_at ?? e.created_at)}
                  </td>
                  <td className="px-4 py-2 whitespace-nowrap">{libelleKind(e.kind)}</td>
                  <td className="px-4 py-2">
                    {e.cible_type === "redaction" ? (
                      <Link
                        to="/media/$mediaId"
                        params={{ mediaId: String(e.subject_id) }}
                        className="font-medium text-brand-600 hover:underline dark:text-brand-400"
                      >
                        {e.cible}
                      </Link>
                    ) : (
                      <Link
                        to="/journalists/$journalistId"
                        params={{ journalistId: String(e.subject_id) }}
                        className="font-medium text-brand-600 hover:underline dark:text-brand-400"
                      >
                        {e.cible}
                      </Link>
                    )}
                  </td>
                  <td className="px-4 py-2 text-slate-500">
                    {e.redaction ?? (
                      // Ambre, comme sur la fiche journaliste : un rattachement
                      // en attente est un arbitrage à rendre, pas une absence.
                      <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                        à rattacher
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2 text-slate-700 dark:text-slate-200">{e.title ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
