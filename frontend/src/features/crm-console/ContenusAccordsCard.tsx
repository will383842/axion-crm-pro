/**
 * Bloc « Contenus et accords » de la fiche d'une personne (PV-6), en LECTURE.
 * D'où vient la personne, quels guides reçus, quels accords et quelles
 * versions de texte ; l'opposition est une mise à l'écart bien visible.
 * Tout est rendu en texte (échappé par React) : aucun HTML brut.
 */
import { Card, CardTitle, StatusPill } from '@/components/ui';
import type { ContenusAccords } from './types';

const NATURE_LABELS: Record<string, string> = {
  information: 'Information',
  optin: 'Accord pour de futures campagnes',
  retrait: 'Retrait',
};

function date(value: string | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('fr-FR');
}

export function ContenusAccordsCard({ bloc }: { bloc: ContenusAccords | undefined }) {
  const b = bloc;
  const vide = b === undefined || (b.origine === null && b.guides.length === 0 && b.consentements.length === 0 && !b.opposition.actif);

  return (
    <Card>
      <CardTitle>Contenus et accords</CardTitle>
      {b?.opposition.actif === true && (
        <div role="alert" data-testid="bandeau-opposition">
          <StatusPill tone="danger">Opposition : mise à l’écart de tout envoi</StatusPill>
          <div>Depuis le {date(b.opposition.date)}. Les données sont gardées.</div>
        </div>
      )}
      {vide || b === undefined ? (
        <p>Aucune information de contenu ou d’accord reçue pour cette personne.</p>
      ) : (
        <div>
          <div>
            <strong>Origine</strong>
            {b.origine === null ? (
              <div>—</div>
            ) : (
              <div>
                Mot : {b.origine.mot ?? '—'} · Réseau : {b.origine.reseau ?? '—'} · Guide : {b.origine.guide ?? '—'} ·{' '}
                {date(b.origine.date)}
              </div>
            )}
          </div>
          <div>
            <strong>Guides reçus</strong>
            {b.guides.length === 0 ? (
              <div>—</div>
            ) : (
              <ul>
                {b.guides.map((g, i) => (
                  <li key={`${g.guide ?? ''}-${g.date ?? ''}-${i}`}>
                    {g.guide ?? '—'} · {date(g.date)}
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <strong>Accords</strong>
            {b.consentements.length === 0 ? (
              <div>—</div>
            ) : (
              <ul>
                {b.consentements.map((c, i) => (
                  <li key={`${c.nature ?? ''}-${c.date ?? ''}-${i}`}>
                    {NATURE_LABELS[c.nature ?? ''] ?? c.nature ?? '—'} · {date(c.date)} · source {c.source ?? '—'} · texte version{' '}
                    {c.version ?? '—'}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Card>
  );
}
