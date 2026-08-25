import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Button, Card, Input } from "@/components/ui";
import { api } from "@/lib/api";
import { toast } from "sonner";

/**
 * LE JOURNAL DES ÉCHANGES PRESSE — saisie + historique, partagés.
 *
 * ── Pourquoi un composant partagé et non une seconde copie ────────────────
 * Ce panneau existait sur la fiche journaliste. En l'ajoutant à la fiche
 * rédaction, la voie rapide était de le recopier. Ç'aurait été le même défaut
 * que celui refusé côté base : deux exemplaires d'un même geste, qui divergent
 * au premier ajustement. On ajoute un motif d'échange une fois, ou on
 * l'ajoute deux fois — et la deuxième fois, on l'oublie.
 *
 * La seule différence réelle entre les deux surfaces est l'URL de
 * consignation et la clé de cache à invalider. Elles sont donc des `props`.
 */

/**
 * Natures d'échange proposées à la saisie.
 *
 * Miroir strict de `MediaController::KINDS_PRESSE` et de
 * `JournalistsController::KINDS_PRESSE`, eux-mêmes sous-ensembles de
 * `Taxonomy::ACTIVITY_KINDS`. Proposer ici une valeur que la base refuse
 * fabriquerait une erreur 422 incompréhensible à l'écran.
 */
export const KINDS: Array<{ value: string; label: string }> = [
  { value: "press_release_sent", label: "Communiqué envoyé" },
  { value: "press_followup", label: "Relance" },
  { value: "press_reply", label: "Réponse reçue" },
  { value: "press_coverage", label: "Retombée publiée" },
  { value: "linkedin_message", label: "Message LinkedIn" },
  { value: "call", label: "Appel" },
];

export function libelleKind(kind: string | null): string {
  return KINDS.find((k) => k.value === kind)?.label ?? kind ?? "—";
}

export function dateFr(iso: string | null): string {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleDateString("fr-FR", { day: "2-digit", month: "short", year: "numeric" });
}

export interface TimelineItem {
  id: number;
  kind: string | null;
  title: string | null;
  content: string | null;
  occurred_at: string | null;
  created_at: string | null;
  /**
   * Renseigné uniquement sur une fiche RÉDACTION : nom du journaliste par qui
   * l'échange est passé. `null` ⇒ l'échange visait la rédaction elle-même.
   * Absent des fiches journaliste, où la question ne se pose pas.
   */
  via?: string | null;
}

export function ConsignerEchange({
  endpoint,
  queryKey,
}: {
  endpoint: string;
  queryKey: Array<string | undefined>;
}) {
  // Valeur littérale, pas `KINDS[0].value` : `noUncheckedIndexedAccess` rend
  // l'indexation potentiellement `undefined`, et le défaut d'un formulaire ne
  // doit pas dépendre de l'ORDRE d'une liste d'affichage.
  const [kind, setKind] = useState<string>("press_release_sent");
  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");
  const queryClient = useQueryClient();

  const consigner = useMutation({
    mutationFn: async () => {
      await api.post(endpoint, { kind, title, content: content || null });
    },
    onSuccess: () => {
      setTitle("");
      setContent("");
      toast.success("Échange consigné");
      void queryClient.invalidateQueries({ queryKey });
    },
    onError: () => toast.error("La consignation a échoué"),
  });

  return (
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
  );
}

export function HistoriqueEchanges({
  timeline,
  vide,
}: {
  timeline: TimelineItem[];
  vide?: string;
}) {
  return (
    <Card padding="none" className="overflow-hidden">
      <h2 className="border-b border-slate-200 px-5 py-4 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-white">
        Historique · {timeline.length}
      </h2>
      {timeline.length === 0 ? (
        <p className="px-5 py-8 text-center text-sm text-slate-500">
          {vide ?? "Aucun échange consigné. Le premier communiqué envoyé apparaîtra ici."}
        </p>
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {timeline.map((t) => (
            <li key={t.id} className="px-5 py-3">
              <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium text-slate-900 dark:text-white">
                  {t.title ?? libelleKind(t.kind)}
                </span>
                <span className="shrink-0 text-xs text-slate-500 tabular-nums">
                  {dateFr(t.occurred_at ?? t.created_at)}
                </span>
              </div>
              <span className="text-xs text-slate-500">
                {libelleKind(t.kind)}
                {/* « via Untel » n'est pas décoratif : on ne relance pas un
                    journal comme on relance une personne. */}
                {t.via ? <span className="text-slate-400"> · via {t.via}</span> : null}
              </span>
              {t.content && (
                <p className="mt-1 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300">{t.content}</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
