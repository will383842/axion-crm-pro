/**
 * Ajout rapide d'un contact (DM1-CON).
 *
 * Deux règles : la PROVENANCE est obligatoire (sans elle, aucune fiche), et un
 * doublon probable est SIGNALÉ pendant la saisie — on propose de fusionner ou
 * de garder les deux, jamais de décider à la place de l'utilisateur.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import { useAntiRebond } from '@/hooks/useAntiRebond';
import { ChampSaisie } from './ChampSaisie';
import { PROVENANCES, erreursDeChamps, messageServeur, type AjoutReponse, type DoublonPropose, type DoublonsReponse } from './types';

interface Saisie {
  first_name: string;
  last_name: string;
  email: string;
  organisation: string;
  fonction: string;
  provenance: string;
}

const VIDE: Saisie = { first_name: '', last_name: '', email: '', organisation: '', fonction: '', provenance: '' };
const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const RAISONS: Record<DoublonPropose['raison'], string> = {
  meme_email: 'même adresse e-mail',
  meme_nom: 'même nom',
};

export function AjoutRapideContact({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [saisie, setSaisie] = useState<Saisie>(VIDE);
  const [erreurs, setErreurs] = useState<string[]>([]);
  const client = useQueryClient();

  useEffect(() => {
    if (open) {
      setSaisie(VIDE);
      setErreurs([]);
    }
  }, [open]);

  const modifier = (champ: keyof Saisie, valeur: string) => setSaisie((s) => ({ ...s, [champ]: valeur }));
  const differee = useAntiRebond(saisie);
  const assezPourChercher = RE_EMAIL.test(differee.email.trim()) || (differee.first_name.trim() !== '' && differee.last_name.trim() !== '');

  const doublons = useQuery<DoublonsReponse>({
    queryKey: ['crm', 'contacts-doublons', differee.email, differee.first_name, differee.last_name, differee.organisation],
    enabled: open && assezPourChercher,
    queryFn: async () => {
      const params = new URLSearchParams({ email: differee.email.trim(), prenom: differee.first_name.trim(), nom: differee.last_name.trim(), organisation: differee.organisation.trim() });
      return (await api.get<DoublonsReponse>(`/crm/contacts/doublons?${params.toString()}`)).data;
    },
  });
  const propositions = assezPourChercher ? (doublons.data?.data ?? []) : [];

  const envoyer = useMutation({
    mutationFn: async (options: { doublon?: 'fusionner' | 'garder'; fusionnerAvec?: number }) =>
      (await api.post<AjoutReponse>('/crm/contacts', { ...saisie, doublon: options.doublon, fusionner_avec: options.fusionnerAvec })).data,
    onSuccess: (r) => {
      toast.success(r.resultat === 'fusionne' ? 'Fiches fusionnées' : 'Contact ajouté');
      void client.invalidateQueries({ queryKey: ['crm'] });
      onClose();
    },
    onError: (e) => {
      const liste = erreursDeChamps(e);
      setErreurs(liste.length > 0 ? liste : [messageServeur(e) ?? 'L’ajout n’a pas pu être enregistré.']);
    },
  });

  const verifier = (): boolean => {
    const liste: string[] = [];
    if (saisie.last_name.trim() === '') liste.push('Le nom est obligatoire.');
    if (!RE_EMAIL.test(saisie.email.trim())) liste.push('Indiquez une adresse e-mail valide.');
    if (saisie.provenance === '') liste.push('La provenance est obligatoire : d’où vient cette personne ?');
    setErreurs(liste);
    return liste.length === 0;
  };

  const ajouter = (options: { doublon?: 'fusionner' | 'garder'; fusionnerAvec?: number }) => {
    if (verifier()) envoyer.mutate(options);
  };

  const premier = propositions[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ajouter un contact"
      description="Une fiche en quelques secondes. La provenance est obligatoire."
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button variant="primary" loading={envoyer.isPending} onClick={() => ajouter(premier ? { doublon: 'garder' } : {})}>
            {premier ? 'Garder les deux fiches' : 'Ajouter ce contact'}
          </Button>
        </>
      }
    >
      <form
        className="grid max-h-[60vh] gap-3 overflow-y-auto pr-1 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault();
          ajouter(premier ? { doublon: 'garder' } : {});
        }}
        noValidate
      >
        <ChampSaisie label="Prénom">{(id, cls) => <input id={id} className={cls} value={saisie.first_name} onChange={(e) => modifier('first_name', e.target.value)} autoComplete="off" />}</ChampSaisie>
        <ChampSaisie label="Nom" obligatoire>{(id, cls) => <input id={id} className={cls} value={saisie.last_name} onChange={(e) => modifier('last_name', e.target.value)} autoComplete="off" />}</ChampSaisie>
        <ChampSaisie label="E-mail" obligatoire>{(id, cls) => <input id={id} type="email" className={cls} value={saisie.email} onChange={(e) => modifier('email', e.target.value)} autoComplete="off" />}</ChampSaisie>
        <ChampSaisie label="Organisation">{(id, cls) => <input id={id} className={cls} value={saisie.organisation} onChange={(e) => modifier('organisation', e.target.value)} autoComplete="off" />}</ChampSaisie>
        <ChampSaisie label="Fonction">{(id, cls) => <input id={id} className={cls} value={saisie.fonction} onChange={(e) => modifier('fonction', e.target.value)} autoComplete="off" />}</ChampSaisie>
        <ChampSaisie label="Provenance" obligatoire aide="D’où vient cette personne ? Elle sert à justifier la fiche.">
          {(id, cls) => (
            <select id={id} className={cls} value={saisie.provenance} onChange={(e) => modifier('provenance', e.target.value)}>
              <option value="">Choisir…</option>
              {PROVENANCES.map((p) => (
                <option key={p.valeur} value={p.valeur}>
                  {p.libelle}
                </option>
              ))}
            </select>
          )}
        </ChampSaisie>

        {erreurs.length > 0 ? (
          <div role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200 sm:col-span-2">
            <ul className="list-disc pl-4">
              {erreurs.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          </div>
        ) : null}

        {premier ? (
          <div role="status" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 sm:col-span-2">
            <p className="font-medium">Cette personne existe peut-être déjà</p>
            <ul className="mt-1 text-xs">
              {propositions.map((d) => (
                <li key={d.id}>
                  {d.nom_complet} · {d.email} · {d.organisation} ({RAISONS[d.raison]})
                </li>
              ))}
            </ul>
            <div className="mt-2 flex flex-wrap gap-2">
              <Button type="button" variant="secondary" size="sm" className="h-11 md:h-8" loading={envoyer.isPending} onClick={() => ajouter({ doublon: 'fusionner', fusionnerAvec: premier.id })}>
                Fusionner avec la fiche existante
              </Button>
            </div>
          </div>
        ) : null}
        {/* Entrée au clavier : le bouton de validation est dans le pied de la fenêtre. */}
        <button type="submit" className="sr-only" tabIndex={-1} aria-hidden>
          Valider
        </button>
      </form>
    </Modal>
  );
}
