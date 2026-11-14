/**
 * Alertes d'envoi (DM2-CNF) : en cours, réglées, « ce que le système a fait »,
 * « que faire ». Console (cloche) et e-mail seulement.
 * Maquette : `maquettes/alertes.html`.
 */
import { Link } from '@tanstack/react-router';
import { Check } from 'lucide-react';
import { Button, Card, KpiCard, PageHeader, StatusPill, type StatusTone } from '@/components/ui';
import { useAlertes, useRegler, type AlerteEnvoi } from './composants/api';
import { CLASSE_BOUTON_MOBILE, Etat } from './composants/commun';
import { dateEtHeure } from './composants/format';
import { LIBELLE_TYPE_ALERTE } from './composants/typesAlerte';

const NIVEAU: Record<AlerteEnvoi['niveau'], { libelle: string; ton: StatusTone }> = {
  a_faire: { libelle: 'À faire', ton: 'danger' },
  attention: { libelle: 'Attention', ton: 'warning' },
  nouveau: { libelle: 'Nouveau', ton: 'info' },
  fait: { libelle: 'Fait', ton: 'success' },
};

function CarteAlerte({ alerte }: { alerte: AlerteEnvoi }) {
  const regler = useRegler();
  const niveau = NIVEAU[alerte.niveau];
  return (
    <Card>
      <article aria-labelledby={`alerte-${alerte.id}`}>
        <div className="flex flex-wrap items-center gap-2">
          <h3 id={`alerte-${alerte.id}`} className="text-sm font-semibold text-slate-900">{alerte.titre}</h3>
          <StatusPill tone={niveau.ton}>{niveau.libelle}</StatusPill>
          {alerte.type !== undefined ? <span className="text-xs text-slate-600">{LIBELLE_TYPE_ALERTE[alerte.type]}</span> : null}
          <span className="ml-auto text-xs text-slate-500">{dateEtHeure(alerte.survenue_le)}</span>
        </div>
        <dl className="mt-3 space-y-1 text-sm text-slate-700">
          <div><dt className="inline font-semibold">Ce qui se passe : </dt><dd className="inline">{alerte.ce_qui_se_passe}</dd></div>
          <div><dt className="inline font-semibold">Ce que le système a fait : </dt><dd className="inline">{alerte.ce_que_le_systeme_a_fait}</dd></div>
          <div><dt className="inline font-semibold">Que faire : </dt><dd className="inline">{alerte.que_faire}</dd></div>
        </dl>
        <div className="mt-3 flex flex-wrap items-center gap-3">
          {alerte.lien !== null ? (
            <Link to={alerte.lien as never} className="inline-flex h-9 items-center text-sm font-medium text-produit underline-offset-2 hover:underline max-md:h-11">
              Ouvrir l’élément concerné
            </Link>
          ) : null}
          {!alerte.close ? (
            <Button size="sm" variant="secondary" iconLeft={<Check className="h-3.5 w-3.5" aria-hidden />} loading={regler.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => regler.mutate(alerte.id)}>
              Marquer comme réglée
            </Button>
          ) : (
            <span className="text-xs text-slate-500">Réglée</span>
          )}
        </div>
      </article>
    </Card>
  );
}

export function AlertesEnvoiPage() {
  const requete = useAlertes();
  const ouvertes = requete.data?.filter((a) => !a.close).length;
  const reglees = requete.data?.filter((a) => a.close).length;
  return (
    <div>
      <PageHeader
        title="Alertes d’envoi"
        surTitre="Envoi"
        subtitle={`${ouvertes ?? '…'} alerte(s) en cours. Console et e-mail seulement.`}
        actions={
          <Link to="/envoi/reglages/alertes" className="inline-flex h-9 items-center justify-center rounded-lg bg-produit px-4 text-sm font-medium text-white shadow-produit hover:bg-produit-fonce max-md:h-11">
            Régler les alertes
          </Link>
        }
      />
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <KpiCard label="En cours" value={ouvertes ?? '—'} tone="amber" />
        <KpiCard label="Réglées" value={reglees ?? '—'} tone="emerald" />
        <KpiCard label="Canaux" value="2" sublabel="console + e-mail" tone="slate" />
      </div>
      <Etat requete={requete} contexte="les alertes d’envoi" vide={(d) => d.length === 0}>
        {(alertes) => (
          <ul className="space-y-3">
            {[...alertes]
              .sort((a, b) => Number(a.close) - Number(b.close) || b.survenue_le.localeCompare(a.survenue_le))
              .map((a) => (
                <li key={a.id}>
                  <CarteAlerte alerte={a} />
                </li>
              ))}
          </ul>
        )}
      </Etat>
    </div>
  );
}
