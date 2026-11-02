/** DM2-CAM — barre de progression d'envoi (couleur du produit, texte en clair à côté). */
export function BarreProgression({ envoyes, total, nom }: { envoyes: number; total: number; nom: string }) {
  const pct = total === 0 ? 0 : Math.min(100, Math.round((envoyes / total) * 100));
  return (
    <div className="flex min-w-0 items-center gap-3">
      <div
        role="progressbar"
        aria-label={`Progression de ${nom}`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={pct}
        className="h-2 min-w-16 flex-1 overflow-hidden rounded-full bg-slate-100"
      >
        <div className="h-full rounded-full bg-produit" style={{ width: `${pct}%` }} />
      </div>
      <span className="shrink-0 text-xs tabular-nums text-slate-600">
        {envoyes.toLocaleString('fr-FR')} / {total.toLocaleString('fr-FR')}
      </span>
    </div>
  );
}
