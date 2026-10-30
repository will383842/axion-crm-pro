import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';

/**
 * Lot C2 — ce que la console d'Axion IA envoie pour cette entreprise
 * (`companies.metadata.console`), en lecture seule. Uniquement des états, des
 * dates et des nombres : aucun montant, aucun texte de note. Aucun lien vers la
 * console (son adresse est secrète : `url_console` n'est JAMAIS affichée).
 */
export interface RecuConsole {
  statut?: string | null;
  etape?: string | null;
  sante?: string | null;
  axi_cli?: string | null;
  cloture_prevue?: string | null;
  questionnaire?: {
    envoye_le?: string | null;
    recu_le?: string | null;
    nb_reponses_equipe?: number | null;
    attendus?: number | null;
    date_limite?: string | null;
  } | null;
  prochaine_action?: { type?: string | null; date?: string | null } | null;
  taches_ouvertes?: { nb?: number | null; prochaine_le?: string | null } | null;
  nb_emails_console?: number | null;
  nb_notes_console?: number | null;
}

export interface ContactAvecRole {
  id: number;
  first_name?: string | null;
  last_name?: string | null;
  metadata?: { role_decision?: string | null } | null;
}

const STATUTS: Record<string, string> = { prospect: 'Prospect', client: 'Client' };
const ETAPES: Record<string, string> = {
  prospect: 'Prospect',
  devis_envoye: 'Devis envoyé',
  client: 'Client',
  archive: 'Archivé',
};
const SANTES: Record<string, string> = {
  bonne: 'Bonne',
  a_surveiller: 'À surveiller',
  a_risque: 'À risque',
};
const ACTIONS: Record<string, string> = {
  relance: 'Relance',
  rappel: 'Rappel',
  envoi_devis: 'Envoi du devis',
  rendez_vous: 'Rendez-vous',
  autre: 'Autre action',
};
const ROLES: Record<string, [string, string]> = {
  decideur: ['décideur', 'décideurs'],
  financeur: ['financeur', 'financeurs'],
  influenceur: ['influenceur', 'influenceurs'],
  utilisateur: ['utilisateur', 'utilisateurs'],
  referent: ['référent', 'référents'],
  bloqueur: ['bloqueur', 'bloqueurs'],
  autre: ['autre', 'autres'],
};
const CLES_CONNUES = new Set([
  'statut',
  'etape',
  'axi_cli',
  'cloture_prevue',
  'client_ref',
  'version',
  'facture_emise',
  'effectif_tranche',
  'sante',
  'questionnaire',
  'prochaine_action',
  'taches_ouvertes',
  'nb_emails_console',
  'nb_notes_console',
  'url_console',
]);

/** `AAAA-MM-JJ` (ou ISO 8601) → « 5 nov. » ; `null` si illisible. */
function jour(date?: string | null): string | null {
  const m = typeof date === 'string' ? /^(\d{4})-(\d{2})-(\d{2})/.exec(date) : null;
  if (!m) return null;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', timeZone: 'UTC' });
}

const nombre = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);

function Ligne({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-900 dark:text-white">{children}</dd>
    </div>
  );
}

export function RecuDeLaConsoleCard({
  console: recu,
  contacts = [],
  archiveIci = false,
}: {
  console?: RecuConsole | null | undefined;
  contacts?: ContactAvecRole[] | undefined;
  /** `companies.archive_reason` est renseigné : le client est archivé ici aussi. */
  archiveIci?: boolean;
}) {
  const lignes: React.ReactNode[] = [];
  if (recu) {
    const statut = recu.statut ? (STATUTS[recu.statut] ?? null) : null;
    if (statut || recu.axi_cli) {
      lignes.push(
        <Ligne key="statut" label="Statut">
          {statut}
          {recu.axi_cli ? (
            <span className={`${statut ? 'ml-2 ' : ''}font-mono text-xs text-slate-500 dark:text-slate-400`}>
              {recu.axi_cli}
            </span>
          ) : null}
        </Ligne>,
      );
    }

    const q = recu.questionnaire;
    if (q) {
      const limite = jour(q.date_limite);
      const nbEq = q.nb_reponses_equipe;
      let date: string | null = null;
      if (jour(q.recu_le)) date = `reçu le ${jour(q.recu_le)}`;
      else if (jour(q.envoye_le)) date = `envoyé le ${jour(q.envoye_le)}`;
      let compte: string | null = null;
      if (nombre(nbEq) && nombre(q.attendus) && q.attendus > 0) {
        // Reçues sur attendues ; si plus de réponses que d'attendus, le seul nombre reçu.
        compte =
          nbEq > q.attendus
            ? `${nbEq} réponse${nbEq > 1 ? 's' : ''} d’équipe reçue${nbEq > 1 ? 's' : ''}`
            : `${nbEq} sur ${q.attendus}`;
      } else if (nombre(nbEq)) {
        compte = `${nbEq} réponse${nbEq > 1 ? 's' : ''} d’équipe reçue${nbEq > 1 ? 's' : ''}`;
      } else if (nombre(q.attendus)) {
        compte = `${q.attendus} attendu${q.attendus > 1 ? 's' : ''}`;
      }
      const texte = [date, compte].filter(Boolean).join(' · ');
      if (texte) {
        lignes.push(
          <Ligne key="questionnaire" label="Questionnaire">
            {texte}
            {limite ? `, avant le ${limite}` : ''}
          </Ligne>,
        );
      }
    }

    const decompte = new Map<string, number>();
    for (const ct of contacts) {
      const r = ct.metadata?.role_decision;
      if (r && ROLES[r]) decompte.set(r, (decompte.get(r) ?? 0) + 1);
    }
    if (decompte.size > 0) {
      const texte = [...decompte.entries()]
        .map(([r, n]) => `${n} ${n > 1 ? ROLES[r]?.[1] : ROLES[r]?.[0]}`)
        .join(', ');
      lignes.push(
        <Ligne key="roles" label="Rôles connus de la console (peut inclure des personnes parties)">
          {texte}
        </Ligne>,
      );
    }

    const action = recu.prochaine_action;
    if (action?.type && ACTIONS[action.type]) {
      const quand = jour(action.date);
      lignes.push(
        <Ligne key="action" label="Prochaine action">
          {ACTIONS[action.type]}
          <span className="text-slate-500 dark:text-slate-400">
            {' '}
            · à faire dans la console{quand ? ` · avant le ${quand}` : ''}
          </span>
        </Ligne>,
      );
    }

    const taches = recu.taches_ouvertes;
    if (taches && nombre(taches.nb)) {
      const proche = jour(taches.prochaine_le);
      lignes.push(
        <Ligne key="taches" label="Tâches">
          {taches.nb} tâche{taches.nb > 1 ? 's' : ''} ouverte{taches.nb > 1 ? 's' : ''} dans la console
          {taches.nb > 0 && proche ? ` · la plus proche : ${proche}` : ''}
        </Ligne>,
      );
    }

    const etape = recu.etape ? (ETAPES[recu.etape] ?? null) : null;
    const cloture = jour(recu.cloture_prevue);
    if (etape || cloture) {
      lignes.push(
        <Ligne key="etape" label="Étape">
          {etape ?? '—'}
          {cloture ? ` · clôture prévue le ${cloture}` : ''}
        </Ligne>,
      );
    }

    const sante = recu.sante ? (SANTES[recu.sante] ?? null) : null;
    if (sante) {
      lignes.push(
        <Ligne key="sante" label="Santé du compte">
          {sante}
        </Ligne>,
      );
    }

    if (nombre(recu.nb_emails_console) || nombre(recu.nb_notes_console)) {
      const parts: string[] = [];
      if (nombre(recu.nb_emails_console)) {
        parts.push(`${recu.nb_emails_console} e-mail${recu.nb_emails_console > 1 ? 's' : ''}`);
      }
      if (nombre(recu.nb_notes_console)) {
        parts.push(`${recu.nb_notes_console} note${recu.nb_notes_console > 1 ? 's' : ''}`);
      }
      lignes.push(
        <Ligne key="volumes" label="Dans la console">
          {parts.join(' · ')}
        </Ligne>,
      );
    }
  }

  const archive = recu?.etape === 'archive';
  const valeurs = (recu ?? {}) as Record<string, unknown>;
  const nonReconnu = Object.keys(valeurs).some((k) => !CLES_CONNUES.has(k) || valeurs[k] != null);

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Console d’Axion IA</CardEyebrow>
          <CardTitle>Reçu de la console</CardTitle>
        </div>
      </CardHeader>
      {lignes.length === 0 && !archive ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {nonReconnu ? 'Données reçues, non reconnues par cet écran' : 'Rien reçu pour l’instant'}
        </p>
      ) : (
        <>
          {lignes.length > 0 ? <dl className="space-y-3">{lignes}</dl> : null}
          {archive ? (
            <p className="mt-3 text-sm font-medium text-slate-700 dark:text-slate-300">
              {archiveIci
                ? 'Ce client est archivé dans la console : il l’est aussi ici.'
                : 'Ce client est archivé dans la console.'}
            </p>
          ) : null}
          <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Aucun montant, aucun texte de note ne passe.</p>
          <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:ring-slate-800">
            Les actions se font dans la console d’Axion IA.
          </p>
        </>
      )}
    </Card>
  );
}
