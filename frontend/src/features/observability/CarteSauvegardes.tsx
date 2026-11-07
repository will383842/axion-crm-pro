import { DatabaseBackup } from 'lucide-react';
import { Card, CardEyebrow, CardHeader, CardTitle, StatusPill } from '@/components/ui';

/** Ce que le serveur annonce des sauvegardes (absent tant que le serveur ne les expose pas). */
export interface EtatSauvegardes {
  derniere_copie_le: string;
  derniere_copie_taille_mo?: number;
  copie_hors_site_le: string | null;
  dernier_exercice_restauration_le: string | null;
  dernier_exercice_resultat?: 'reussi' | 'echec' | null;
}

function dateHeure(iso: string | null): string {
  if (iso === null) return 'Jamais';
  return new Date(iso).toLocaleString('fr-FR', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Paris' });
}

/**
 * Carte « Sauvegardes » en LECTURE : dernière copie, copie hors site, dernier
 * exercice de restauration. Elle ne s'affiche que si le résumé de santé porte
 * `sauvegardes` (le serveur annonce la fonction) ; sinon `ObservabilityPage`
 * ne la monte pas.
 */
export function CarteSauvegardes({ etat }: { etat: EtatSauvegardes }) {
  const exercice = etat.dernier_exercice_resultat ?? null;
  return (
    <Card data-testid="carte-sauvegardes">
      <CardHeader>
        <div className="flex items-center gap-2">
          <DatabaseBackup className="size-4 text-slate-500" aria-hidden="true" />
          <div>
            <CardEyebrow>Sécurité des données</CardEyebrow>
            <CardTitle className="mt-1 text-lg font-bold">Sauvegardes</CardTitle>
          </div>
        </div>
      </CardHeader>
      <dl className="grid gap-4 p-4 pt-0 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-xs text-slate-500">Dernière copie</dt>
          <dd className="mt-1 font-medium text-slate-900">{dateHeure(etat.derniere_copie_le)}</dd>
          {etat.derniere_copie_taille_mo !== undefined && (
            <dd className="text-xs text-slate-500">{etat.derniere_copie_taille_mo.toLocaleString('fr-FR')} Mo</dd>
          )}
        </div>
        <div>
          <dt className="text-xs text-slate-500">Copie hors site</dt>
          <dd className="mt-1 font-medium text-slate-900">{dateHeure(etat.copie_hors_site_le)}</dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">Dernier exercice de restauration</dt>
          <dd className="mt-1 font-medium text-slate-900">{dateHeure(etat.dernier_exercice_restauration_le)}</dd>
          {exercice !== null && (
            <dd className="mt-1">
              <StatusPill tone={exercice === 'reussi' ? 'success' : 'danger'}>{exercice === 'reussi' ? 'Réussi' : 'Échec'}</StatusPill>
            </dd>
          )}
        </div>
      </dl>
    </Card>
  );
}
