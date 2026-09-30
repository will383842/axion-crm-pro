/**
 * Sur la FICHE d'une entreprise : les listes manuelles qui la contiennent, et
 * le geste « ajouter à une liste » — pour l'organisation, ou pour les
 * personnes cochées (« seulement certains contacts »). Retirer la fiche d'une
 * liste ne supprime pas la fiche.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';
import { AjouterAUneListe } from './AjouterAUneListe';
import { chargerListes, messageServeur, retirerDeLaListe } from './listes';

export function ListesDeLaFiche({
  companyId,
  contacts,
}: {
  companyId: number;
  contacts: Array<{ id: number; first_name?: string | null; last_name: string; role?: string | null }>;
}) {
  const qc = useQueryClient();
  const [personnes, setPersonnes] = useState<Set<number>>(new Set());
  const [organisation, setOrganisation] = useState(true);

  const listes = useQuery({
    queryKey: ['listes-manuelles', { company_id: companyId }],
    queryFn: () => chargerListes({ company_id: companyId }),
  });
  const contenantes = (listes.data ?? []).filter((l) => l.contient);

  const retrait = useMutation({
    mutationFn: (listeId: number) => retirerDeLaListe(listeId, { company_ids: [companyId] }),
    onSuccess: () => {
      toast.success('Fiche retirée de la liste (la fiche elle-même est intacte).');
      void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Retrait impossible.'),
  });

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Ciblage</CardEyebrow>
          <CardTitle>Listes manuelles</CardTitle>
        </div>
      </CardHeader>
      {contenantes.length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Cette organisation n’est dans aucune liste.</p>
      ) : (
        <ul className="mb-3 space-y-1 text-sm">
          {contenantes.map((l) => (
            <li key={l.id} className="flex items-center justify-between gap-2">
              <Link to="/listes/$listeId" params={{ listeId: String(l.id) }} className="text-indigo-700 hover:underline dark:text-indigo-300">
                {l.nom}
              </Link>
              <button
                type="button"
                className="text-xs text-slate-500 underline hover:text-rose-600"
                onClick={() => retrait.mutate(l.id)}
                disabled={retrait.isPending}
              >
                Retirer
              </button>
            </li>
          ))}
        </ul>
      )}
      <fieldset className="mt-3 space-y-1 text-sm">
        <legend className="mb-1 text-xs font-semibold uppercase tracking-wider text-slate-500">Ajouter à une liste</legend>
        <label className="flex items-center gap-2">
          <input type="checkbox" checked={organisation} onChange={(e) => setOrganisation(e.target.checked)} />
          L’organisation
        </label>
        {contacts.map((c) => (
          <label key={c.id} className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={personnes.has(c.id)}
              onChange={() =>
                setPersonnes((actuelles) => {
                  const suivantes = new Set(actuelles);
                  if (suivantes.has(c.id)) suivantes.delete(c.id);
                  else suivantes.add(c.id);
                  return suivantes;
                })
              }
            />
            {[c.first_name, c.last_name].filter(Boolean).join(' ')}
            {c.role !== null && c.role !== undefined && c.role !== '' ? <span className="text-xs text-slate-500">· {c.role}</span> : null}
          </label>
        ))}
      </fieldset>
      <div className="mt-3">
        <AjouterAUneListe
          companyIds={organisation ? [companyId] : []}
          contactIds={[...personnes]}
          onAjoute={() => setPersonnes(new Set())}
        />
      </div>
    </Card>
  );
}
