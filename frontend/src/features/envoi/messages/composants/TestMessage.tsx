/** « M'envoyer un test » : à vos adresses de test seulement, jamais à une personne de la cible. */
import { useState } from 'react';
import { Button, Modal } from '@/components/ui';
import { CHAMP } from '../../creation/composants';
import { envoyerTestMessage, useExemples } from '../../creation/api';

export function TestMessage({
  ouvert,
  onFermer,
  objet,
  objetB,
  corps,
  onEnvoye,
}: {
  ouvert: boolean;
  onFermer: () => void;
  objet: string;
  objetB: string | null;
  corps: string;
  onEnvoye: (texte: string) => void;
}) {
  const exemples = useExemples();
  const [aMoi, setAMoi] = useState(true);
  const [temoins, setTemoins] = useState(true);
  const [deux, setDeux] = useState(true);
  const [exemple, setExemple] = useState<string | undefined>(undefined);
  const [enCours, setEnCours] = useState(false);

  const envoyer = async () => {
    setEnCours(true);
    try {
      const n = await envoyerTestMessage({
        objet,
        objet_b: objetB,
        corps,
        a_moi: aMoi,
        boites_temoins: temoins,
        deux_versions: objetB !== null && deux,
        ...(exemple ? { destinataire_exemple_id: exemple } : {}),
      });
      onEnvoye(`Test envoyé à ${n} ${n > 1 ? 'adresses' : 'adresse'} de test. Personne d’autre n’a été contacté.`);
    } catch {
      onEnvoye('Le test n’a pas pu partir. Réessayez dans un instant.');
    } finally {
      setEnCours(false);
      onFermer();
    }
  };

  return (
    <Modal
      open={ouvert}
      onClose={onFermer}
      title="M’envoyer un test"
      description="Le test part vers vos adresses de test seulement. Aucune personne de vos listes n’est contactée."
      footer={
        <>
          <Button variant="ghost" onClick={onFermer}>Annuler</Button>
          <Button variant="primary" loading={enCours} disabled={!aMoi && !temoins} onClick={() => { void envoyer(); }}>Envoyer le test</Button>
        </>
      }
    >
      <div className="space-y-2">
        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-900">
          <input type="checkbox" className="h-4 w-4 accent-[var(--color-produit)]" checked={aMoi} onChange={(e) => { setAMoi(e.target.checked); }} /> À moi
        </label>
        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-900">
          <input type="checkbox" className="h-4 w-4 accent-[var(--color-produit)]" checked={temoins} onChange={(e) => { setTemoins(e.target.checked); }} /> Aux boîtes témoins (4)
        </label>
        {objetB !== null ? (
          <label className="flex min-h-11 items-center gap-2 text-sm text-slate-900">
            <input type="checkbox" className="h-4 w-4 accent-[var(--color-produit)]" checked={deux} onChange={(e) => { setDeux(e.target.checked); }} /> Les deux versions (A et B)
          </label>
        ) : null}
        <div>
          <label htmlFor="test-exemple" className="mb-1 block text-xs font-medium text-slate-600">Exemple de destinataire</label>
          <select id="test-exemple" className={CHAMP} value={exemple ?? exemples.data?.[0]?.id ?? ''} onChange={(e) => { setExemple(e.target.value); }}>
            {(exemples.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.libelle}</option>)}
          </select>
        </div>
      </div>
    </Modal>
  );
}
