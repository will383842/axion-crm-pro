/**
 * Désinscriptions (DM2-CNF) : le registre « ne plus écrire » (jamais effacé),
 * « Puis-je écrire à… ? » et l'export du registre en preuve.
 * Maquette : `maquettes/desinscriptions.html`.
 */
import { useState } from 'react';
import { Download } from 'lucide-react';
import { Button, KpiCard, PageHeader } from '@/components/ui';
import { exporterRegistre, useRegistre } from './composants/api';
import { Annonce, CLASSE_BOUTON_MOBILE, messageDErreur } from './composants/commun';
import { PuisJeEcrire } from './composants/PuisJeEcrire';
import { BoutonInscrire, RegistreOpposition } from './composants/RegistreOpposition';

export function DesinscriptionsPage() {
  const [recherche, setRecherche] = useState('');
  const [exportErreur, setExportErreur] = useState<string | null>(null);
  const [exportFait, setExportFait] = useState(false);
  const global = useRegistre('');
  const resume = global.data?.resume;

  async function exporter() {
    setExportErreur(null);
    setExportFait(false);
    try {
      await exporterRegistre();
      setExportFait(true);
    } catch (e) {
      setExportErreur(messageDErreur(e, 'L’export n’a pas pu être produit. Réessayez.'));
    }
  }

  return (
    <div>
      <PageHeader
        title="Désinscriptions"
        surTitre="Envoi"
        subtitle={`${resume?.total ?? '…'} oppositions, jamais effacées. Partagées avec le CRM.`}
        actions={
          <>
            <BoutonInscrire />
            <Button variant="secondary" iconLeft={<Download className="h-4 w-4" aria-hidden />} className={CLASSE_BOUTON_MOBILE} onClick={() => void exporter()}>
              Exporter le registre (preuve)
            </Button>
          </>
        }
      />
      <Annonce message={exportErreur} ton="erreur" />
      <Annonce message={exportFait ? 'Le registre a été téléchargé (fichier CSV).' : null} />
      <div className="space-y-4">
        <PuisJeEcrire />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <KpiCard label="Oppositions" value={resume?.total ?? '—'} sublabel="jamais effacées" tone="slate" />
          <KpiCard label="Ce mois" value={resume?.ce_mois ?? '—'} sublabel={`dont ${resume?.ce_mois_par_lien ?? 0} par le lien`} tone="slate" />
          <KpiCard label="Domaines entiers" value={resume?.domaines_entiers ?? '—'} sublabel={`${resume?.organisations ?? 0} organisation(s) entière(s)`} tone="slate" />
        </div>
        <RegistreOpposition recherche={recherche} onRecherche={setRecherche} />
      </div>
    </div>
  );
}
