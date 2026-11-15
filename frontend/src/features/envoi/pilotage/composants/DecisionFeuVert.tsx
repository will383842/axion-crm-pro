/**
 * F-FV — décision finale du feu vert (contrat K17-FV : `POST /envoi/feu-vert/decision`).
 * « Accorder » : `If-Match` = empreinte lue (428 sans, 412 périmée, 409 bloquant rouge) ; « Retirer » : toujours
 * accepté par le serveur, mais un motif invalide (10 à 500 caractères, sans adresse) donne 422.
 * Gestes réservés à `workspaces.manage` (masqués tant que les droits sont inconnus). Aucune personne affichée.
 */
import { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Button, Card, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import { PERMISSION_GERER_ESPACE, permissionsDe } from '@/features/origines/droits';
import type { components } from '../../api/pilotage';
import { messageErreur } from '../../infrastructure/composants/format';
import { AIDE_MOTIF, bloquantsRouges, libelleAccord, RAPPEL_ENVOI_REEL } from '../feuVertLibelles';

type Liste = components['schemas']['ListeFeuVert'];
type Decision = components['schemas']['DecisionFeuVert']['decision'];

const MOTIF_MIN = 10;
const MOTIF_MAX = 500;
const CLE = ['envoi', 'feu-vert'];

const quand = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

function useProprietaire(): boolean {
  const { data } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<{ roles?: string[]; permissions?: string[] }>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  return permissionsDe(data).has(PERMISSION_GERER_ESPACE);
}

export function SaisieDecision({ decision, figee, onFermer, onPerime }: { decision: Decision; figee: string; onFermer: () => void; onPerime: () => void }) {
  const qc = useQueryClient();
  const id = useId();
  const [motif, setMotif] = useState('');
  const [local, setLocal] = useState<string | null>(null);
  const accorde = decision === 'accorde';
  const longueur = motif.trim().length;
  const envoyer = useMutation({
    mutationFn: async () => {
      const corps = { decision, motif: motif.trim() };
      if (!accorde) return (await api.post<Liste>('/envoi/feu-vert/decision', corps)).data;
      // « accorde » ne part jamais sans If-Match : l'empreinte est celle figée à l'ouverture de la fenêtre.
      return (await api.post<Liste>('/envoi/feu-vert/decision', corps, { headers: { 'If-Match': figee } })).data;
    },
    onSuccess: () => { onFermer(); void qc.invalidateQueries({ queryKey: CLE }); },
    // 412 : l'état a changé depuis l'ouverture. On relit la liste et on FERME la fenêtre : il faut la rouvrir
    // depuis la liste relue, sinon un second clic enverrait la nouvelle empreinte sur une liste que l'on n'a pas vue.
    onError: (e) => {
      if (isAxiosError(e) && e.response?.status === 412) {
        void qc.invalidateQueries({ queryKey: CLE });
        onPerime();
      }
    },
  });
  const confirmer = () => {
    if (accorde && !figee) { setLocal('Aucune empreinte de la liste : relisez la liste avant d’accorder. Rien n’a été envoyé.'); return; }
    if (motif.normalize('NFKC').includes('@')) { setLocal('Le motif ne doit pas contenir d’adresse e-mail (aucun « @ »). Rien n’a été envoyé.'); return; }
    setLocal(null);
    envoyer.mutate();
  };
  const statut = envoyer.isError && isAxiosError(envoyer.error) ? envoyer.error.response?.status : undefined;
  const refusServeur = envoyer.isError ? messageErreur(envoyer.error, 'La décision n’a pas abouti. Rien n’a été enregistré. Réessayez dans un instant.') : null;
  const refus = local ?? refusServeur;
  return (
    <Modal
      open
      onClose={onFermer}
      size="md"
      title={accorde ? 'Accorder le feu vert ?' : 'Retirer le feu vert ?'}
      footer={
        <>
          <Button variant="ghost" onClick={onFermer}>Annuler</Button>
          <Button disabled={longueur < MOTIF_MIN} loading={envoyer.isPending} onClick={confirmer}>
            {accorde ? 'Confirmer l’accord' : 'Confirmer le retrait'}
          </Button>
        </>
      }
    >
      <div className="space-y-2">
        <p className="text-sm text-slate-700">{accorde ? RAPPEL_ENVOI_REEL : 'Le retrait arrête le feu vert tout de suite. Il est toujours accepté, à une condition : un motif valide.'}</p>
        <label htmlFor={id} className="block text-sm font-medium text-slate-900">Motif</label>
        <p id={`${id}-aide`} className="text-xs text-slate-600">{AIDE_MOTIF}</p>
        <textarea id={id} autoFocus rows={3} maxLength={MOTIF_MAX} value={motif} onChange={(e) => setMotif(e.target.value)} aria-describedby={`${id}-aide`} aria-invalid={refus !== null} className="w-full rounded-lg px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-300 focus:outline-none focus:ring-2" />
        <p className="text-xs text-slate-600">{longueur} / {MOTIF_MAX}</p>
        {refus ? (
          <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
            {refus}
            {!accorde && local === null ? (statut === 422
              ? ' Le retrait n’a pas été enregistré : le feu vert reste en l’état tant que le motif n’est pas valide.'
              : ' Le retrait n’a pas été enregistré : le feu vert reste en l’état.') : ''}
          </p>
        ) : null}
      </div>
    </Modal>
  );
}

export function DecisionFeuVert({ liste }: { liste: Liste }) {
  const proprietaire = useProprietaire();
  // Fenêtre ouverte : décision + empreinte figées à l'ouverture (jamais relues depuis la liste qui change ensuite).
  const [ouverte, setOuverte] = useState<{ decision: Decision; figee: string } | null>(null);
  const [perime, setPerime] = useState(false);
  const ouvrir = (decision: Decision) => { setPerime(false); setOuverte({ decision, figee: liste.empreinte_liste ?? '' }); };
  const rouges = bloquantsRouges(liste.points);
  const d = liste.decision ?? null;
  return (
    <section aria-labelledby="titre-decision">
      <Card>
        <h2 id="titre-decision" className="text-lg font-bold text-slate-900">Décision finale</h2>
        <p className="mt-1 text-sm text-slate-600">{RAPPEL_ENVOI_REEL}</p>
        <p className="mt-3 text-sm text-slate-900">
          {d ? (
            <>
              <strong>{d.decision === 'accorde' ? 'Accordé' : 'Retiré'}</strong> · le {quand(d.le)}
              <span className="block text-slate-700">{d.motif}</span>
            </>
          ) : 'Aucune décision pour l’instant.'}
        </p>
        <p className="mt-2 text-sm text-slate-700">{libelleAccord(rouges)}</p>
        {proprietaire ? (
          <div className="mt-3 flex flex-wrap gap-2">
            <Button className="min-h-11 md:min-h-9" disabled={rouges > 0 || !liste.empreinte_liste} onClick={() => ouvrir('accorde')}>Accorder le feu vert</Button>
            <Button variant="secondary" className="min-h-11 md:min-h-9" onClick={() => ouvrir('retire')}>Retirer le feu vert</Button>
          </div>
        ) : null}
        {perime ? <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">Pendant que la fenêtre était ouverte, l’état a changé, relisez-le puis recommencez. Rien n’a été enregistré.</p> : null}
        {ouverte ? <SaisieDecision decision={ouverte.decision} figee={ouverte.figee} onFermer={() => setOuverte(null)} onPerime={() => { setOuverte(null); setPerime(true); }} /> : null}
      </Card>
    </section>
  );
}
