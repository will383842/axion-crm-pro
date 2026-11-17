/** Récapitulatif du brouillon, à droite de l'assistant (en dessous sur téléphone). */
import { CalendarClock, Mail, Share2, Target, Timer } from 'lucide-react';
import type { ReactNode } from 'react';
import { dateCourte, dateDeFin, dateLongue, joursNecessaires } from './outils';
import { libelleReseau } from '@/features/origines/libelles';
import type { Saisie } from './saisie';

function Ligne({ icone, titre, children }: { icone: ReactNode; titre: string; children: ReactNode }) {
  return (
    <div className="flex items-start gap-3 py-2">
      <span className="mt-0.5 text-slate-400" aria-hidden>{icone}</span>
      <div className="min-w-0">
        <p className="text-xs text-slate-600">{titre}</p>
        <p className="break-words text-sm font-semibold text-slate-900">{children}</p>
      </div>
    </div>
  );
}

/** « Uniquement les personnes venues de : … par le mot … pour le guide … » (parties absentes omises). */
export function phraseOrigine(s: Pick<Saisie, 'reseaux' | 'mots' | 'guides'>): string {
  const parties: string[] = [];
  if (s.reseaux.length > 0) parties.push(`de : ${s.reseaux.map((r) => libelleReseau(r) ?? r).join(', ')}`);
  if (s.mots.length > 0) parties.push(`par le mot ${s.mots.join(', ')}`);
  if (s.guides.length > 0) parties.push(`pour le guide ${s.guides.join(', ')}`);
  return `Uniquement les personnes venues ${parties.join(', ')}`;
}

export function Recapitulatif({
  saisie,
  restant,
  nomsMessages,
  enregistreA,
  jourDepart,
}: {
  saisie: Saisie;
  restant: number | null;
  nomsMessages: string[];
  enregistreA: string | null;
  /** Jour d'envoi réel du premier message (AAAA-MM-JJ), s'il est connu. */
  jourDepart: string | null;
}) {
  const jours = restant === null ? 0 : joursNecessaires(restant, saisie.plafond);
  const fin = jourDepart !== null && jours > 0 ? dateDeFin(jourDepart, jours) : null;
  return (
    <aside aria-label="Récapitulatif" className="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200 md:p-5">
      <div className="mb-2 flex items-center justify-between">
        <h2 className="text-lg font-bold tracking-tight text-slate-900">Récapitulatif</h2>
        <span className="text-xs font-medium text-slate-500">Brouillon</span>
      </div>
      <div className="divide-y divide-slate-200">
        <Ligne icone={<Target className="h-4 w-4" />} titre="Cible">
          {saisie.cible ? `${saisie.cible.libelle}${restant !== null ? ` · ${restant.toLocaleString('fr-FR')}` : ''}` : 'Pas encore choisie'}
        </Ligne>
        {saisie.reseaux.length + saisie.mots.length + saisie.guides.length > 0 ? (
          <Ligne icone={<Share2 className="h-4 w-4" />} titre="Réseau d’origine">
            {phraseOrigine(saisie)}
          </Ligne>
        ) : null}
        <Ligne icone={<Mail className="h-4 w-4" />} titre="Message">
          {nomsMessages.length > 0 ? nomsMessages.join(', ') : 'Pas encore choisi'}
          {saisie.ab ? ' · test A/B' : ''}
        </Ligne>
        <Ligne icone={<CalendarClock className="h-4 w-4" />} titre="Départ">
          {saisie.depart === 'immediat' ? 'Dès la validation' : saisie.jour !== '' ? `${dateLongue(saisie.jour)} · ${saisie.heure}` : 'Pas encore choisi'}
        </Ligne>
        <Ligne icone={<Timer className="h-4 w-4" />} titre="Fin prévue">
          {fin ? dateCourte(fin) : '—'}
        </Ligne>
      </div>
      <p className="mt-3 text-xs text-slate-600">
        {enregistreA ? `Brouillon enregistré à ${enregistreA}.` : 'Le brouillon s’enregistre tout seul.'}
      </p>
    </aside>
  );
}
