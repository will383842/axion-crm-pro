/**
 * Messages types : versionnés, archivés (jamais supprimés), une version à
 * valider avant de servir dans une campagne. Lot DM2-ASS.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Archive, Mail, Sparkles } from 'lucide-react';
import { Button, EmptyState, KpiCard, Modal, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { messageErreur } from '../infrastructure/composants/format';
import { OngletsCampagnes } from '../campagnes/OngletsCampagnes';
import { useGestesMessages, useMessagesTypes } from '../creation/api';
import { StatutMessage } from './composants/StatutMessage';
import { SuggestionIA } from './composants/SuggestionIA';

export function MessagesTypesPage() {
  const produit = useProduitCourant();
  const [archives, setArchives] = useState(false);
  const [suggestion, setSuggestion] = useState(false);
  const messages = useMessagesTypes(archives);
  const { archiver } = useGestesMessages();
  const [aArchiver, setAArchiver] = useState<{ id: string; nom: string } | null>(null);
  const liste = messages.data?.data ?? [];
  const actifs = liste.filter((m) => m.statut !== 'archive');
  const valides = actifs.filter((m) => m.statut === 'valide').length;
  const aRelire = actifs.filter((m) => m.statut === 'a_relire').length;

  const actions = (
    <>
      <Button variant="secondary" iconLeft={<Sparkles className="h-4 w-4" aria-hidden />} onClick={() => { setSuggestion(true); }}>
        Brouillon par l’IA
      </Button>
      <Link
        to="/envoi/campagnes/messages/$messageId"
        params={{ messageId: 'nouveau' }}
        className="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-produit px-4 text-sm font-medium text-white shadow-produit hover:bg-produit-fonce md:h-9"
      >
        Nouveau message
      </Link>
    </>
  );

  return (
    <div>
      <PageHeader
        title="Messages types"
        surTitre={NOM_DU_PRODUIT[produit]}
        subtitle={
          messages.data ? (
            <>
              <strong className="font-semibold text-slate-700">{actifs.length} {actifs.length > 1 ? 'messages' : 'message'}</strong>, {valides} {valides > 1 ? 'validés' : 'validé'}.
            </>
          ) : undefined
        }
        actions={messages.data ? actions : undefined}
      />
      <OngletsCampagnes />
      <div className="mt-4">
        <SuggestionIA ouverte={suggestion} onFermer={() => { setSuggestion(false); void messages.refetch(); }} />

        {messages.isError ? (
          <QueryErrorState error={messages.error} contexte="les messages types" onRetry={() => { void messages.refetch(); }} />
        ) : messages.isPending ? (
          <div role="status" aria-label="Chargement en cours" className="space-y-3">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-28 w-full" />)}
            </div>
            <Skeleton className="h-64 w-full" />
          </div>
        ) : liste.length === 0 ? (
          <EmptyState
            icon={<Mail />}
            title="Aucun message type"
            description="Un message type garde son objet, son texte et toutes ses versions. Écrivez le premier : il servira à chaque campagne."
            action={
              <Link to="/envoi/campagnes/messages/$messageId" params={{ messageId: 'nouveau' }} className="inline-flex h-9 items-center rounded-lg bg-produit px-4 text-sm font-medium text-white hover:bg-produit-fonce">
                Nouveau message
              </Link>
            }
          />
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <KpiCard label="Messages types" value={actifs.length} sublabel="jamais supprimés : archivés" tone="sky" />
              <KpiCard label="Validés" value={valides} tone="emerald" />
              <KpiCard label="À relire" value={aRelire} tone="amber" />
              <KpiCard label="Blocs réutilisables" value={messages.data.blocs.length} sublabel="signatures, appels" tone="violet" />
            </div>

            <label className="mt-4 flex min-h-11 items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" className="h-4 w-4 accent-[var(--color-produit)]" checked={archives} onChange={(e) => { setArchives(e.target.checked); }} />
              Afficher les messages archivés
            </label>

            <ul aria-label="Messages types" className="mt-2 divide-y divide-slate-100 rounded-2xl bg-white ring-1 ring-slate-200/70">
              <li aria-hidden className="hidden gap-3 px-4 py-2 text-xs font-medium text-slate-500 md:grid md:grid-cols-[minmax(0,2fr)_80px_120px_minmax(0,1.5fr)_220px]">
                <span>Message type</span><span>Version</span><span>Statut</span><span>Utilisé par</span><span />
              </li>
              {liste.map((m) => (
                <li key={m.id} className="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,2fr)_80px_120px_minmax(0,1.5fr)_220px] md:items-center md:gap-3">
                  <Link to="/envoi/campagnes/messages/$messageId" params={{ messageId: m.id }} className="min-w-0 break-words text-sm font-semibold text-slate-900 hover:text-produit">
                    {m.nom}
                  </Link>
                  <span className="text-sm text-slate-700"><span className="text-xs text-slate-500 md:hidden">Version </span>v{m.version_courante}</span>
                  <span><StatutMessage statut={m.statut} /></span>
                  <span className="text-sm text-slate-700">
                    {m.utilise_par > 0 ? `${m.utilise_par} ${m.utilise_par > 1 ? 'campagnes' : 'campagne'}` : '—'}
                  </span>
                  <span className="flex flex-wrap gap-2 md:justify-end">
                    <Link
                      to="/envoi/campagnes/messages/$messageId"
                      params={{ messageId: m.id }}
                      className="inline-flex h-11 items-center rounded-lg px-3 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50 md:h-9"
                      aria-label={`Ouvrir ${m.nom}`}
                    >
                      Ouvrir
                    </Link>
                    {m.statut === 'archive' ? (
                      <span className="max-w-48 self-center text-xs text-slate-600">Archivé : ne peut pas être rétabli pour l’instant.</span>
                    ) : (
                      <Button
                        variant="ghost"
                        aria-label={`Archiver ${m.nom}`}
                        iconLeft={<Archive className="h-4 w-4" aria-hidden />}
                        loading={archiver.isPending && archiver.variables?.id === m.id}
                        onClick={() => { archiver.reset(); setAArchiver({ id: m.id, nom: m.nom }); }}
                      >
                        Archiver
                      </Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </>
        )}
      </div>
      <Modal
        open={aArchiver !== null}
        onClose={() => { setAArchiver(null); }}
        size="sm"
        title="Archiver ce message ?"
        footer={
          <>
            <Button variant="ghost" onClick={() => { setAArchiver(null); }}>Annuler</Button>
            <Button
              variant="destructive"
              loading={archiver.isPending}
              onClick={() => {
                if (aArchiver !== null) archiver.mutate({ id: aArchiver.id, archive: true }, { onSuccess: () => { setAArchiver(null); } });
              }}
            >
              Archiver le message
            </Button>
          </>
        }
      >
        <p className="text-sm text-slate-700">
          <strong className="font-semibold text-slate-900">{aArchiver?.nom}</strong> ne servira plus dans les nouvelles campagnes. L’archivage ne peut pas être annulé pour l’instant.
        </p>
        {archiver.isError ? <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{messageErreur(archiver.error, 'L’archivage n’a pas abouti. Rien n’a changé : réessayez dans un instant.')}</p> : null}
      </Modal>
    </div>
  );
}
