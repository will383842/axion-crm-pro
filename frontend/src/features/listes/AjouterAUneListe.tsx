/**
 * « Ajouter à une liste » — posé dans les barres de sélection (Entreprises,
 * Contacts) et sur la fiche. Choisir une liste existante, ou en nommer une
 * nouvelle ; le compte RÉEL est annoncé (ajoutées, déjà présentes,
 * introuvables), jamais un simple « fait ».
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ListPlus } from 'lucide-react';
import { Button } from '@/components/ui';
import { ajouterALaListe, chargerListes, creerListe, messageServeur, resumerAjout } from './listes';

const NOUVELLE = 'nouvelle';

const CHAMP =
  'h-8 rounded-lg bg-white px-2 text-xs text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700';

export function AjouterAUneListe({
  companyIds = [],
  contactIds = [],
  onAjoute,
}: {
  companyIds?: number[];
  contactIds?: number[];
  onAjoute?: () => void;
}) {
  const qc = useQueryClient();
  const [choix, setChoix] = useState('');
  const [nom, setNom] = useState('');
  const listes = useQuery({ queryKey: ['listes-manuelles'], queryFn: () => chargerListes() });

  const ajout = useMutation({
    mutationFn: async () => {
      const listeId = choix === NOUVELLE ? (await creerListe(nom.trim())).id : Number(choix);
      return ajouterALaListe(listeId, {
        ...(companyIds.length > 0 ? { company_ids: companyIds } : {}),
        ...(contactIds.length > 0 ? { contact_ids: contactIds } : {}),
      });
    },
    onSuccess: (bilan) => {
      toast.success(resumerAjout(bilan));
      setNom('');
      void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
      void qc.invalidateQueries({ queryKey: ['liste-manuelle'] });
      onAjoute?.();
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Ajout à la liste impossible.'),
  });

  const nbFiches = companyIds.length + contactIds.length;
  const pret = nbFiches > 0 && (choix === NOUVELLE ? nom.trim() !== '' : choix !== '');
  const enCours = ajout.isPending;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <select
        aria-label="Liste manuelle"
        value={choix}
        onChange={(e) => setChoix(e.target.value)}
        className={CHAMP}
      >
        <option value="">Ajouter à une liste…</option>
        {(listes.data ?? []).map((l) => (
          <option key={l.id} value={String(l.id)}>
            {l.nom}
          </option>
        ))}
        <option value={NOUVELLE}>+ Nouvelle liste…</option>
      </select>
      {choix === NOUVELLE ? (
        <input
          type="text"
          aria-label="Nom de la nouvelle liste"
          placeholder="Ex. : Invités salon GOFAB"
          value={nom}
          maxLength={160}
          onChange={(e) => setNom(e.target.value)}
          className={`${CHAMP} w-56`}
        />
      ) : null}
      <Button
        size="sm"
        variant="secondary"
        iconLeft={<ListPlus className="h-3.5 w-3.5" />}
        disabled={enCours || !pret}
        loading={ajout.isPending}
        onClick={() => ajout.mutate()}
      >
        Ajouter {nbFiches > 1 ? `les ${nbFiches} fiches` : 'la fiche'}
      </Button>
    </div>
  );
}
