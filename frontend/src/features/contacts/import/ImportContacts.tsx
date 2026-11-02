/**
 * Import de contacts en 3 étapes (DM1-CON) : colonnes, contrôle, rapport.
 *
 * Rien n'est écrit avant « Lancer l'import ». Une ligne sans provenance n'est
 * JAMAIS importée : elle est comptée à part dans le rapport. Un gros fichier
 * tourne en arrière-plan avec une barre d'avancement.
 *
 * Fichiers lus : CSV. Un fichier Excel est expliqué (pas de nouvelle
 * dépendance dans ce lot) : il faut l'enregistrer d'abord au format CSV.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import { ChampSaisie } from '../ajout-rapide/ChampSaisie';
import {
  CHAMPS_IMPORT,
  PROVENANCES,
  erreursDeChamps,
  messageServeur,
  type BilanImport,
  type ChampImport,
  type RapportImport,
  type TravailImport,
} from '../ajout-rapide/types';
import { deviner, lireCsv, lireTexte, versLignes, type FichierLu } from './lireFichier';

const pluriel = (n: number, un: string, plusieurs: string): string => `${n.toLocaleString('fr-FR')} ${n > 1 ? plusieurs : un}`;

const ETAPES = ['Colonnes', 'Contrôle', 'Rapport'] as const;

export function ImportContacts({ open, onClose, intervalleMs = 700 }: { open: boolean; onClose: () => void; intervalleMs?: number }) {
  const client = useQueryClient();
  const [etape, setEtape] = useState<1 | 2 | 3>(1);
  const [nomFichier, setNomFichier] = useState('');
  const [fichier, setFichier] = useState<FichierLu | null>(null);
  const [correspondance, setCorrespondance] = useState<ChampImport[]>([]);
  const [provenanceParDefaut, setProvenanceParDefaut] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [bilan, setBilan] = useState<BilanImport | null>(null);
  const [travail, setTravail] = useState<TravailImport | null>(null);

  useEffect(() => {
    if (!open) return;
    setEtape(1);
    setNomFichier('');
    setFichier(null);
    setCorrespondance([]);
    setProvenanceParDefaut('');
    setMessage(null);
    setBilan(null);
    setTravail(null);
  }, [open]);

  const lignes = fichier === null ? [] : versLignes(fichier, correspondance, provenanceParDefaut);
  const nomEtEmailChoisis = correspondance.includes('nom') && correspondance.includes('email');

  const choisirFichier = async (f: File | undefined) => {
    setMessage(null);
    if (f === undefined) return;
    if (/\.xlsx?$/i.test(f.name)) {
      setFichier(null);
      setMessage('Les fichiers Excel ne sont pas lus dans cette démonstration. Dans Excel : Fichier, Enregistrer sous, puis « CSV (séparateur : point-virgule) », et choisissez ce nouveau fichier.');
      return;
    }
    const lu = lireCsv(await lireTexte(f));
    if (lu.lignes.length === 0) {
      setFichier(null);
      setMessage('Ce fichier ne contient aucune ligne à importer.');
      return;
    }
    setNomFichier(f.name);
    setFichier(lu);
    setCorrespondance(deviner(lu.colonnes));
  };

  const controler = useMutation({
    mutationFn: async () => (await api.post<BilanImport>('/crm/contacts/import/controle', { lignes })).data,
    onSuccess: (b) => {
      setBilan(b);
      setMessage(null);
      setEtape(2);
    },
    onError: (e) => setMessage(erreursDeChamps(e)[0] ?? messageServeur(e) ?? 'Le contrôle n’a pas pu se faire.'),
  });

  const lancer = useMutation({
    mutationFn: async () => (await api.post<TravailImport>('/crm/contacts/import', { lignes, nom_fichier: nomFichier })).data,
    onSuccess: (t) => {
      setTravail(t);
      setMessage(null);
      setEtape(3);
      if (t.statut === 'termine') void client.invalidateQueries({ queryKey: ['crm'] });
    },
    onError: (e) => setMessage(messageServeur(e) ?? 'L’import n’a pas pu démarrer.'),
  });

  // Gros import : on lit l'avancement jusqu'au rapport.
  const enCours = travail !== null && travail.statut === 'en_cours' ? travail.id : null;
  useEffect(() => {
    if (enCours === null || !open) return;
    const minuteur = setTimeout(() => {
      void api
        .get<TravailImport>(`/crm/contacts/import/${enCours}`)
        .then((r) => {
          setTravail(r.data);
          if (r.data.statut === 'termine') void client.invalidateQueries({ queryKey: ['crm'] });
        })
        .catch(() => setMessage('L’avancement de l’import n’a pas pu être lu.'));
    }, intervalleMs);
    return () => clearTimeout(minuteur);
  }, [enCours, travail, open, intervalleMs, client]);

  return (
    <Modal open={open} onClose={onClose} title="Importer des contacts" description={`Étape ${etape} sur 3 : ${ETAPES[etape - 1]}`} size="xl">
      <div className="max-h-[70vh] overflow-y-auto pr-1">
      <ol className="mb-4 flex gap-2 text-xs" aria-label="Étapes de l’import">
        {ETAPES.map((nom, i) => (
          <li key={nom} aria-current={etape === i + 1 ? 'step' : undefined} className={etape === i + 1 ? 'rounded-full bg-produit-pale px-3 py-1 font-semibold text-produit' : 'rounded-full bg-slate-100 px-3 py-1 text-slate-500'}>
            {i + 1}. {nom}
          </li>
        ))}
      </ol>

      {message !== null ? (
        <div role="alert" className="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">
          {message}
        </div>
      ) : null}

      {etape === 1 ? (
        <div className="flex flex-col gap-4">
          <ChampSaisie label="Fichier à importer" aide="Un fichier CSV : une ligne par personne, avec une ligne de titres.">
            {(id, cls) => <input id={id} type="file" accept=".csv,.txt,.xlsx,.xls" className={`${cls} py-1.5 file:mr-3`} onChange={(e) => void choisirFichier(e.target.files?.[0])} />}
          </ChampSaisie>

          {fichier !== null ? (
            <>
              <p className="text-sm text-slate-700">
                <strong>{nomFichier}</strong> : {pluriel(fichier.lignes.length, 'ligne lue', 'lignes lues')}
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                {fichier.colonnes.map((colonne, i) => (
                  <ChampSaisie key={`${colonne}-${i}`} label={`Colonne « ${colonne} »`}>
                    {(id, cls) => (
                      <select
                        id={id}
                        className={cls}
                        value={correspondance[i] ?? 'ignorer'}
                        onChange={(e) => setCorrespondance((c) => c.map((v, k) => (k === i ? (e.target.value as ChampImport) : v)))}
                      >
                        {CHAMPS_IMPORT.map((c) => (
                          <option key={c.valeur} value={c.valeur}>
                            {c.libelle}
                          </option>
                        ))}
                      </select>
                    )}
                  </ChampSaisie>
                ))}
              </div>
              <ChampSaisie label="Provenance des lignes qui n’en ont pas" aide="Laissez « Aucune » pour refuser ces lignes : une fiche sans provenance n’est jamais créée.">
                {(id, cls) => (
                  <select id={id} className={cls} value={provenanceParDefaut} onChange={(e) => setProvenanceParDefaut(e.target.value)}>
                    <option value="">Aucune</option>
                    {PROVENANCES.map((p) => (
                      <option key={p.valeur} value={p.valeur}>
                        {p.libelle}
                      </option>
                    ))}
                  </select>
                )}
              </ChampSaisie>
              {!nomEtEmailChoisis ? <p className="text-xs text-amber-700">Indiquez au moins la colonne du nom et celle de l’e-mail.</p> : null}
            </>
          ) : null}

          <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <Button variant="secondary" onClick={onClose}>
              Annuler
            </Button>
            <Button variant="primary" disabled={fichier === null || !nomEtEmailChoisis} loading={controler.isPending} onClick={() => controler.mutate()}>
              Contrôler le fichier
            </Button>
          </div>
        </div>
      ) : null}

      {etape === 2 && bilan !== null ? (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-slate-700">Rien n’est encore écrit. Voici ce qui se passera :</p>
          <ul className="flex flex-col gap-1 text-sm">
            <li className="font-semibold text-emerald-700">{pluriel(bilan.a_creer, 'fiche sera créée', 'fiches seront créées')}</li>
            <li>{pluriel(bilan.refusees.length, 'ligne sera refusée', 'lignes seront refusées')} (nom ou e-mail inutilisable)</li>
            <li>{pluriel(bilan.doublons.length, 'doublon sera ignoré', 'doublons seront ignorés')} (la personne existe déjà)</li>
            <li>{pluriel(bilan.sans_provenance.length, 'ligne sans provenance ne sera pas créée', 'lignes sans provenance ne seront pas créées')}</li>
          </ul>
          {bilan.a_creer > 200 ? <p className="text-xs text-slate-500">Ce fichier est gros : l’import se fera en arrière-plan, avec une barre d’avancement.</p> : null}
          <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <Button variant="secondary" onClick={() => setEtape(1)}>
              Retour
            </Button>
            <Button variant="primary" disabled={bilan.a_creer === 0} loading={lancer.isPending} onClick={() => lancer.mutate()}>
              Lancer l’import
            </Button>
          </div>
        </div>
      ) : null}

      {etape === 3 && travail !== null ? (
        travail.statut === 'en_cours' ? (
          <div className="flex flex-col gap-3">
            <p className="text-sm text-slate-700">Import en cours : vous pouvez rester sur cette page.</p>
            <div
              role="progressbar"
              aria-label="Import en cours"
              aria-valuemin={0}
              aria-valuemax={travail.total}
              aria-valuenow={travail.traitees}
              className="h-3 w-full overflow-hidden rounded-full bg-slate-100"
            >
              <div className="h-full bg-produit transition-[width]" style={{ width: `${Math.round((travail.traitees / Math.max(1, travail.total)) * 100)}%` }} />
            </div>
            <p className="text-xs text-slate-500">
              {travail.traitees.toLocaleString('fr-FR')} lignes traitées sur {travail.total.toLocaleString('fr-FR')}
            </p>
          </div>
        ) : (
          <Rapport rapport={travail.rapport!} onTerminer={onClose} />
        )
      ) : null}
      </div>
    </Modal>
  );
}

function Rapport({ rapport, onTerminer }: { rapport: RapportImport; onTerminer: () => void }) {
  return (
    <section aria-label="Rapport d’import" className="flex flex-col gap-4">
      <ul className="flex flex-col gap-1 text-sm">
        <li className="font-semibold text-emerald-700">{pluriel(rapport.crees, 'fiche créée', 'fiches créées')}</li>
        <li>{pluriel(rapport.refusees.length, 'ligne refusée', 'lignes refusées')}</li>
        <li>{pluriel(rapport.doublons.length, 'doublon', 'doublons')} (non créés)</li>
        <li>{pluriel(rapport.sans_provenance.length, 'ligne sans provenance', 'lignes sans provenance')} (non créées)</li>
      </ul>
      {rapport.refusees.length + rapport.doublons.length + rapport.sans_provenance.length > 0 ? (
        <details className="text-xs text-slate-600">
          <summary className="cursor-pointer text-sm font-medium text-slate-700">Voir le détail des lignes non importées</summary>
          <ul className="mt-2 max-h-48 overflow-auto">
            {rapport.refusees.slice(0, 50).map((l) => (
              <li key={`r${l.ligne}`}>
                Ligne {l.ligne} : {l.raison}
              </li>
            ))}
            {rapport.doublons.slice(0, 50).map((l) => (
              <li key={`d${l.ligne}`}>
                Ligne {l.ligne} : existe déjà ({l.existant})
              </li>
            ))}
            {rapport.sans_provenance.slice(0, 50).map((l) => (
              <li key={`s${l}`}>Ligne {l} : provenance manquante</li>
            ))}
          </ul>
        </details>
      ) : null}
      <div className="flex justify-end border-t border-slate-100 pt-4">
        <Button variant="primary" onClick={onTerminer}>
          Terminer
        </Button>
      </div>
    </section>
  );
}
