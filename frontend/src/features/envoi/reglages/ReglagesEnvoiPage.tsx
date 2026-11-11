/**
 * Réglages d'envoi, accueil (DM2-CNF) : vue d'ensemble, mode absence,
 * boucles de retour des messageries. Maquette : `maquettes/reglages-envoi.html`.
 */
import { useEffect, useId, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Bell, ChevronRight, ClipboardList, FileSignature, Gauge, UserX } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button, PageHeader, StatusPill } from '@/components/ui';
import { useApercu, useModeAbsence, type ApercuReglages } from '@/features/envoi/conformite/composants/api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from '@/features/envoi/conformite/composants/commun';
import { OngletsReglages } from './OngletsReglages';

function Entree({ to, icone, titre, detail }: { to: string; icone: ReactNode; titre: string; detail: string }) {
  return (
    <li>
      <Link to={to as never} className="flex min-h-11 items-center gap-3 rounded-xl p-3 ring-1 ring-slate-200 hover:bg-slate-50">
        <span className="text-produit" aria-hidden>{icone}</span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-semibold text-slate-900">{titre}</span>
          <span className="block text-xs text-slate-500">{detail}</span>
        </span>
        <ChevronRight className="h-4 w-4 text-slate-400" aria-hidden />
      </Link>
    </li>
  );
}

function ModeAbsence({ absence }: { absence: ApercuReglages['absence'] }) {
  const id = useId();
  const [du, setDu] = useState(absence.du ?? '');
  const [au, setAu] = useState(absence.au ?? '');
  const [message, setMessage] = useState<{ texte: string; ton: 'ok' | 'erreur' } | null>(null);
  const enregistrer = useModeAbsence();
  const [cible, setCible] = useState<boolean | null>(null);
  useEffect(() => {
    setDu(absence.du ?? '');
    setAu(absence.au ?? '');
  }, [absence.du, absence.au]);

  async function appliquer(actif: boolean) {
    setMessage(null);
    setCible(actif);
    try {
      await enregistrer.mutateAsync({ actif, du: actif ? du || null : null, au: actif ? au || null : null });
      setMessage({ texte: actif ? 'Mode absence activé : les campagnes se mettent en pause, les réponses attendent.' : 'Mode absence désactivé.', ton: 'ok' });
    } catch (e) {
      setMessage({ texte: messageDErreur(e, 'Le mode absence n’a pas pu être enregistré.'), ton: 'erreur' });
    } finally {
      setCible(null);
    }
  }

  return (
    <Bloc titre="Mode absence" droite={absence.actif ? <StatusPill tone="warning">Actif</StatusPill> : <StatusPill tone="neutral">Inactif</StatusPill>}>
      <p className="mb-3 text-sm text-slate-600">Je pars : les campagnes se mettent en pause ; les réponses attendent.</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <div><Etiquette htmlFor={`${id}-du`}>Du</Etiquette><input id={`${id}-du`} type="date" value={du} onChange={(e) => setDu(e.target.value)} className={CLASSE_CHAMP} /></div>
        <div><Etiquette htmlFor={`${id}-au`}>Au</Etiquette><input id={`${id}-au`} type="date" value={au} onChange={(e) => setAu(e.target.value)} className={CLASSE_CHAMP} /></div>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button variant="secondary" loading={enregistrer.isPending && cible === true} disabled={enregistrer.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void appliquer(true)}>
          Activer le mode absence
        </Button>
        {absence.actif ? (
          <Button variant="ghost" loading={enregistrer.isPending && cible === false} disabled={enregistrer.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void appliquer(false)}>
            Désactiver
          </Button>
        ) : null}
      </div>
      <Annonce message={message?.texte ?? null} ton={message?.ton ?? 'ok'} />
    </Bloc>
  );
}

export function ReglagesEnvoiPage() {
  const requete = useApercu();
  return (
    <div>
      <PageHeader title="Réglages d’envoi" surTitre="Envoi" subtitle="Tout ce qui règle le produit Envoi, à part du CRM." />
      <OngletsReglages />
      <Etat requete={requete} contexte="les réglages d’envoi">
        {(a) => (
          <div className="space-y-4">
            <ul className="grid gap-3 sm:grid-cols-2">
              <Entree to="/envoi/reglages/regles" icone={<Gauge className="h-5 w-5" />} titre="Règles de prudence et horaires" detail="Seuils, fenêtre d’envoi, jours ouvrés" />
              <Entree to="/envoi/reglages/identite" icone={<FileSignature className="h-5 w-5" />} titre="Identité de l’expéditeur" detail="Bloc d’information art. 14" />
              <Entree to="/envoi/reglages/alertes" icone={<Bell className="h-5 w-5" />} titre="Alertes et avis" detail={`${a.regles_actives} règle(s) d’alerte active(s) ; console et e-mail`} />
              <Entree to="/envoi/desinscriptions" icone={<UserX className="h-5 w-5" />} titre="Désinscriptions" detail={`Registre et preuve : ${a.oppositions} oppositions`} />
              <Entree to="/envoi/reglages/connexion-crm" icone={<ClipboardList className="h-5 w-5" />} titre="Connexion au CRM" detail="Adaptateur, santé, ce qui passe" />
            </ul>
            <ModeAbsence absence={a.absence} />
            <Bloc titre="Boucles de retour des messageries" droite={<StatusPill tone="warning">{a.boucles_de_retour.filter((b) => b.etat === 'a_faire').length} à faire</StatusPill>}>
              <ul className="divide-y divide-slate-100 text-sm">
                {a.boucles_de_retour.map((b) => (
                  <li key={b.nom} className="flex items-center justify-between gap-3 py-2">
                    <span><span className="block font-medium text-slate-900">{b.nom}</span><span className="block text-xs text-slate-500">{b.detail}</span></span>
                    <StatusPill tone={b.etat === 'fait' ? 'success' : 'warning'}>{b.etat === 'fait' ? 'Fait' : 'À faire'}</StatusPill>
                  </li>
                ))}
              </ul>
            </Bloc>
          </div>
        )}
      </Etat>
    </div>
  );
}
