/**
 * NR-21 bis — le menu latéral d'aujourd'hui : chaque entrée (cible et libellé)
 * existe toujours. C'est la photo « menu identique à aujourd'hui » du plan 30
 * (risque n° 1) : l'écran de Will en production ne change pas sans que ce test
 * le dise. Lecture STATIQUE de `src/components/layout/Sidebar.tsx`.
 *
 * Retirer ou renommer une entrée fait rougir ; en AJOUTER est permis (le lot
 * DM0-COQ ajoutera le sélecteur de produit sans toucher aux entrées du CRM).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const SOURCE = readFileSync(resolve(__dirname, '../../src/components/layout/Sidebar.tsx'), 'utf8').replace(/\/\/.*$/gm, '');

const ENTREES_ATTENDUES: [string, string][] = [
  ['/', 'Tableau de bord'], ['/admin/observability', 'Santé du système'], ['/audiences', 'Audiences'],
  ['/audit-logs', 'Journal des actions'], ['/campaigns', 'Collectes'], ['/companies', 'Entreprises'],
  ['/console/arbitrage', 'Personnes à rattacher'], ['/console/contacts', 'Contacts'],
  ['/console/lettre-et-guide', 'Newsletter et guide'], ['/console/propositions', 'Propositions à valider'],
  ['/console/vivier', 'Candidats'], ['/contacts', 'Contacts'], ['/coverage', 'Carte de France'],
  ['/doublons', 'Doublons à vérifier'], ['/evenements', 'Événements'], ['/federations', 'Fédérations et ordres'],
  ['/international/roumanie', 'Entreprises en Roumanie'], ['/journalists', 'Journalistes'], ['/listes', 'Listes'],
  ['/llm/proxy-providers', 'Serveurs relais'], ['/llm/rotations', 'Rotation des accès'],
  ['/llm/router', 'Moteurs d’IA'], ['/media', 'Médias'], ['/presse/envois', 'Communiqués envoyés'],
  ['/rgpd/ai-act', 'Registre de l’IA'], ['/rgpd/requests', 'Demandes RGPD'],
  ['/scraper-runs', 'Historique des collectes'], ['/settings', 'Paramètres'], ['/tags', 'Étiquettes'],
  ['/users', 'Utilisateurs'],
];

function presentes(): Set<string> {
  return new Set([...SOURCE.matchAll(/to:\s*'([^']+)',\s*label:\s*'([^']+)'/g)].map((m) => `${m[1]}|${m[2]}`));
}

describe('NR-21 bis : le menu latéral', () => {
  it('la lecture voit bien les entrées (témoin de couverture)', () => {
    expect(presentes().size).toBeGreaterThanOrEqual(30);
  });

  it('chaque entrée d’aujourd’hui (cible et libellé) existe toujours', () => {
    const vues = presentes();
    const disparues = ENTREES_ATTENDUES.filter(([to, label]) => !vues.has(`${to}|${label}`)).map(([to, label]) => `${to} « ${label} »`);
    expect(disparues).toEqual([]);
  });
});
