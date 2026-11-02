/**
 * L'assistant « Nouvelle campagne » : 5 étapes, brouillon enregistré tout seul,
 * rien ne part sans le code reçu par e-mail.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Frise } from './Frise';
import { EtapeCible } from './EtapeCible';
import { EtapeMessage } from './EtapeMessage';
import { EtapeDepart } from './EtapeDepart';
import { EtapeControles } from './EtapeControles';
import { EtapeValidation } from './EtapeValidation';
import { Recapitulatif } from './Recapitulatif';
import { cibleDeContrat, contenuEtape, premierJourOuvre, saisieDepuis, SAISIE_INITIALE, type Saisie } from './saisie';
import { creerBrouillon, enregistrerEtape, useContexte, useEstimation, useMessagesTypes, type Brouillon, type CibleProposee } from './api';
import { heureDeParis, jourDeParis } from './outils';

const DELAI_ENREGISTREMENT = 600;

export function Assistant({
  cibles,
  brouillonInitial,
  cibleInitiale,
}: {
  cibles: readonly CibleProposee[];
  brouillonInitial: Brouillon | null;
  /** Cible pré-remplie par l'adresse (`?etiquette=` ou `?entreprise=`). */
  cibleInitiale: CibleProposee | null;
}) {
  const navigate = useNavigate();
  const [saisie, setSaisie] = useState<Saisie>(() =>
    brouillonInitial
      ? saisieDepuis(brouillonInitial)
      : cibleInitiale
        ? { ...SAISIE_INITIALE, cible: { type: cibleInitiale.type, reference: cibleInitiale.reference, libelle: cibleInitiale.libelle }, nom: `Campagne ${cibleInitiale.libelle}` }
        : SAISIE_INITIALE,
  );
  const reprise = brouillonInitial ? Math.min(5, Math.max(1, brouillonInitial.etape_courante)) : 1;
  const [etape, setEtape] = useState(reprise);
  const [plusLoin, setPlusLoin] = useState(reprise);
  const [bloquants, setBloquants] = useState<number | null>(null);
  const [version, setVersion] = useState(0);
  const [enregistreA, setEnregistreA] = useState<string | null>(brouillonInitial ? heureDeParis(brouillonInitial.mis_a_jour_le) : null);
  const [brouillonId, setBrouillonId] = useState<string | null>(brouillonInitial?.id ?? null);
  const [campagneId, setCampagneId] = useState<string | null>(brouillonInitial?.campagne_id ?? null);

  const saisieRef = useRef(saisie);
  saisieRef.current = saisie;
  const etapeRef = useRef(etape);
  etapeRef.current = etape;
  const idRef = useRef<string | null>(brouillonId);
  const creationRef = useRef<Promise<string> | null>(null);
  const sale = useRef(false);
  const minuterie = useRef<number | null>(null);
  const zoneEtape = useRef<HTMLDivElement>(null);
  const premierRendu = useRef(true);

  const estimation = useEstimation(brouillonId, cibleDeContrat(saisie));
  const messages = useMessagesTypes(false);
  const contexte = useContexte();
  const restant = estimation.data?.restant ?? null;

  const assurerBrouillon = useCallback(async (): Promise<string> => {
    if (idRef.current !== null) return idRef.current;
    creationRef.current ??= creerBrouillon(saisieRef.current.nom.trim() === '' ? 'Nouvelle campagne' : saisieRef.current.nom).then((b) => {
      idRef.current = b.id;
      setBrouillonId(b.id);
      void navigate({
        to: '/envoi/campagnes/nouvelle',
        search: ((precedent: Record<string, unknown>) => ({ ...precedent, brouillon: b.id })) as never,
        replace: true,
      });
      return b.id;
    });
    return creationRef.current;
  }, [navigate]);

  const sauver = useCallback(
    async (numero: number): Promise<void> => {
      if (minuterie.current !== null) {
        window.clearTimeout(minuterie.current);
        minuterie.current = null;
      }
      sale.current = false;
      const id = await assurerBrouillon();
      const b = await enregistrerEtape(id, numero, contenuEtape(numero, saisieRef.current));
      setEnregistreA(heureDeParis(b.mis_a_jour_le));
      setVersion((v) => v + 1);
    },
    [assurerBrouillon],
  );

  const modifier = useCallback((patch: Partial<Saisie>) => {
    setSaisie((s) => ({ ...s, ...patch }));
    sale.current = true;
    setBloquants(null);
  }, []);

  // Enregistrement tout seul, un court instant après la dernière saisie (étapes 1 à 3).
  useEffect(() => {
    if (!sale.current || etapeRef.current > 3) return;
    if (minuterie.current !== null) window.clearTimeout(minuterie.current);
    minuterie.current = window.setTimeout(() => {
      minuterie.current = null;
      void sauver(etapeRef.current).catch(() => {
        // Réessayé à la saisie suivante ; l'étape suivante échoue visiblement si besoin.
      });
    }, DELAI_ENREGISTREMENT);
    return () => {
      if (minuterie.current !== null) window.clearTimeout(minuterie.current);
    };
  }, [saisie, sauver]);

  // Le focus suit l'étape (clavier et lecteur d'écran), sauf à l'ouverture.
  useEffect(() => {
    if (premierRendu.current) {
      premierRendu.current = false;
      return;
    }
    zoneEtape.current?.focus();
  }, [etape]);

  const aller = (n: number) => {
    setEtape(n);
    setPlusLoin((p) => Math.max(p, n));
  };
  const avancer = async (de: number) => {
    await sauver(de);
    aller(de + 1);
  };

  const accessible = bloquants !== null && bloquants === 0 ? 5 : Math.min(4, plusLoin);

  const nomsMessages = useMemo(
    () => saisie.sequence.map((l) => messages.data?.data.find((m) => m.id === l.message_id)?.nom).filter((n): n is string => n !== undefined),
    [saisie.sequence, messages.data],
  );
  const jourDepart = useMemo(() => {
    if (saisie.depart === 'immediat') return contexte.data ? premierJourOuvre(jourDeParis(new Date(contexte.data.maintenant))) : null;
    return saisie.jour !== '' ? premierJourOuvre(saisie.jour) : null;
  }, [saisie.depart, saisie.jour, contexte.data]);

  return (
    <div>
      <Frise courante={etape} accessible={accessible} onAller={aller} />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div ref={zoneEtape} tabIndex={-1} className="min-w-0 outline-none">
          {etape === 1 ? (
            <EtapeCible saisie={saisie} modifier={modifier} cibles={cibles} brouillonId={brouillonId} suite={() => { void avancer(1).catch(() => undefined); }} />
          ) : null}
          {etape === 2 ? (
            <EtapeMessage
              saisie={saisie}
              modifier={modifier}
              suite={() => avancer(2)}
              retour={() => { aller(1); }}
              assurerBrouillon={async () => {
                await sauver(2);
                return assurerBrouillon();
              }}
            />
          ) : null}
          {etape === 3 ? (
            <EtapeDepart saisie={saisie} modifier={modifier} restant={restant ?? 0} suite={() => { void avancer(3).catch(() => undefined); }} retour={() => { aller(2); }} />
          ) : null}
          {etape === 4 ? (
            <EtapeControles brouillonId={brouillonId} empreinte={String(version)} suite={() => { aller(5); }} retour={() => { aller(3); }} onBloquants={setBloquants} />
          ) : null}
          {etape === 5 && brouillonId !== null ? (
            <EtapeValidation
              brouillonId={brouillonId}
              saisie={saisie}
              restant={restant ?? 0}
              campagneDejaValidee={campagneId}
              retour={() => { aller(4); }}
              onChangement={() => { setCampagneId(null); setVersion((v) => v + 1); }}
            />
          ) : null}
        </div>
        <Recapitulatif saisie={saisie} restant={restant} nomsMessages={nomsMessages} enregistreA={enregistreA} jourDepart={jourDepart} />
      </div>
    </div>
  );
}
