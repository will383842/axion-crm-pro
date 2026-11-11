/**
 * GARDE — contrats d'Envoi amendés par L8-F03c : feu vert, chauffe, boîtes, séquences.
 *
 * Vérifie, dans les descriptions OpenAPI elles-mêmes : champs obligatoires,
 * énumérations, routes déclarées, et l'absence de toute route « témoins » ou
 * « tout arrêter » dans les zones amendées (pilotage, infrastructure, séquences).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const SOURCES = path.resolve(ICI, '..', '..', '..', 'backend', 'openapi', 'envoi');
const lire = (zone: string) => readFileSync(path.join(SOURCES, `${zone}.yaml`), 'utf8');

/** Texte d'un schéma de `components.schemas` (de sa clé à la clé suivante, à quatre espaces). */
function schema(zone: string, nom: string): string {
  const texte = lire(zone);
  const debut = texte.indexOf(`\n    ${nom}:\n`);
  expect(debut, `${zone}.yaml : schéma ${nom}`).toBeGreaterThanOrEqual(0);
  const suite = texte.slice(debut + 1);
  const fin = suite.slice(1).search(/\n {4}\S|\n {2}\S|\n\S/);
  return fin < 0 ? suite : suite.slice(0, fin + 1);
}

/** Texte d'une route (de `  /chemin:` à la route suivante). */
function route(zone: string, chemin: string): string {
  const texte = lire(zone);
  const debut = texte.indexOf(`\n  ${chemin}:\n`);
  expect(debut, `${zone}.yaml : route ${chemin}`).toBeGreaterThanOrEqual(0);
  const suite = texte.slice(debut + 1);
  const fin = suite.slice(1).search(/\n {2}\/|\ncomponents:/);
  return fin < 0 ? suite : suite.slice(0, fin + 1);
}

function requis(bloc: string): string[] {
  const m = /required:\s*\[([^\]]*)\]/s.exec(bloc);
  return m ? m[1]!.split(',').map((x) => x.trim()).filter(Boolean) : [];
}

describe('contrat F03c — champs obligatoires', () => {
  const cas: Array<[string, string, string[]]> = [
    ['pilotage', 'PointFeuVert', ['code', 'libelle', 'groupe', 'nature', 'etat', 'fait', 'bloquant', 'source', 'detail', 'preuve']],
    ['pilotage', 'VerrousFeuVert', ['envoi_reel_actif', 'mode_temoins', 'signature_presente', 'signature_date', 'fournisseurs_temoins_couverts', 'fournisseurs_temoins_requis']],
    ['infrastructure', 'GoulotChauffe', ['terme', 'ressource_id', 'libelle']],
    ['infrastructure', 'TermesChauffe', ['courbe_ip', 'courbe_domaine', 'plafond_boites', 'plafond_serveur', 'capacite_reponse']],
    ['infrastructure', 'RessourceChauffe', ['id', 'type', 'libelle', 'palier', 'jours_effectifs', 'jours_avant_palier_suivant', 'total_palier', 'envoyes_aujourdhui', 'tenu_jusqu_au', 'motif_tenue', 'peut_reculer']],
    ['infrastructure', 'PalierTable', ['palier', 'jours_min', 'jours_max', 'total_jour']],
    ['commun', 'Chauffe', ['palier', 'jours_effectifs', 'total_palier', 'tenu_jusqu_au']],
    ['sequences', 'SequenceResume', ['id', 'nom', 'statut', 'motif_pause', 'reservee_aux_temoins', 'nb_etapes', 'inscriptions', 'prochain_envoi', 'creee_le']],
    ['sequences', 'InscriptionsSequence', ['total', 'en_cours', 'terminees', 'arretees', 'reportees']],
    ['sequences', 'EtapeSequence', ['rang', 'delai_jours', 'objet', 'envoyes', 'reponses', 'rebonds_durs', 'desinscriptions']],
  ];
  for (const [zone, nom, attendus] of cas) {
    it(`${zone}.yaml · ${nom} exige ${attendus.length} champs`, () => {
      expect(requis(schema(zone, nom)).sort()).toEqual([...attendus].sort());
    });
  }

  it('la fiche d’une séquence ajoute étapes, peut_reprendre, raison_refus_reprise', () => {
    expect(requis(schema('sequences', 'SequenceDetail'))).toEqual(['etapes', 'peut_reprendre', 'raison_refus_reprise']);
  });

  it('le feu vert rend aussi bloquants_restants et verrous, et plus « (15 points) »', () => {
    const r = route('pilotage', '/envoi/feu-vert');
    expect(requis(r)).toEqual(expect.arrayContaining(['points', 'faits', 'total', 'bloquants_restants', 'verrous']));
    expect(r).not.toMatch(/15 points/);
  });

  it('la chauffe rend jour, budget_du_jour, goulot, termes, ressources, paliers, plafond_boite — et plus boîtes, courbe, fins prévues', () => {
    const r = route('infrastructure', '/envoi/chauffe');
    expect(requis(r)).toEqual(['jour', 'budget_du_jour', 'plafond_boite', 'goulot', 'termes', 'ressources', 'paliers']);
    for (const retire of ['boites', 'fins_prevues', 'courbe']) expect(requis(r)).not.toContain(retire);
    expect(r).not.toMatch(/fins_prevues|courbe:/);
  });
});

describe('contrat F03c — énumérations', () => {
  const enumDe = (bloc: string, cle: string): string[] => {
    const debut = bloc.search(new RegExp(`(^|\\n)\\s*${cle}:`));
    expect(debut, cle).toBeGreaterThanOrEqual(0);
    const m = /enum:\s*\[([^\]]*)\]/.exec(bloc.slice(debut, debut + 260));
    expect(m, cle).not.toBeNull();
    return m![1]!.split(',').map((x) => x.trim());
  };

  it('feu vert : groupe, nature, état', () => {
    const b = schema('pilotage', 'PointFeuVert');
    expect(enumDe(b, 'groupe')).toEqual(['verrous', 'banc', 'dns', 'serveur', 'temoins', 'conformite', 'exploitation']);
    expect(enumDe(b, 'nature')).toEqual(['auto', 'manuel']);
    expect(enumDe(b, 'etat')).toEqual(['fait', 'a_faire', 'attend_serveur_reel']);
    expect(b).toContain("pattern: '^(auto|manuel)");
  });

  it('verrous : exactement 6 fournisseurs témoins requis', () => {
    expect(schema('pilotage', 'VerrousFeuVert')).toMatch(/fournisseurs_temoins_requis:[^\n]*const: 6/);
  });

  it('chauffe : paliers P1 à P8, types, motifs, termes du goulot', () => {
    expect(enumDe(schema('commun', 'PalierChauffe'), 'description')).toEqual(['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']);
    expect(schema('infrastructure', 'RessourceChauffe')).toContain('type: { type: string, enum: [ip, domaine] }');
    expect(enumDe(schema('infrastructure', 'RessourceChauffe'), 'motif_tenue')).toEqual(['frein', 'manuel', 'inactivite']);
    expect(enumDe(schema('infrastructure', 'TermeGoulot'), 'type')).toEqual(['courbe_ip', 'courbe_domaine', 'plafond_boites', 'plafond_serveur', 'capacite_reponse']);
  });

  it('séquences : statut, motif de pause, 1 à 3 étapes', () => {
    expect(enumDe(schema('sequences', 'StatutSequence'), 'type')).toEqual(['active', 'en_pause', 'terminee']);
    expect(enumDe(schema('sequences', 'MotifPauseSequence'), 'type')).toEqual(['manuelle', 'frein', 'arret_general', 'automatique']);
    expect(schema('sequences', 'SequenceResume')).toMatch(/nb_etapes: \{ type: integer, minimum: 1, maximum: 3 \}/);
    expect(schema('sequences', 'EtapeSequence')).toMatch(/rang: \{ type: integer, minimum: 1, maximum: 3 \}/);
  });
});

describe('contrat F03c — routes et réponses', () => {
  it('feu vert : corps {preuve 10..500, fait}, 403, 404, 422', () => {
    const r = route('pilotage', '/envoi/feu-vert/{code}/fait');
    expect(r).toMatch(/preuve: \{ type: string, minLength: 10, maxLength: 500,/);
    expect(r).toMatch(/fait: \{ type: boolean, default: true \}/);
    for (const code of ["'403'", "'404'", "'422'"]) expect(r).toContain(code);
  });

  it('chauffe : id de ressource opaque, 404 et 409, aucune route « avancer »', () => {
    const r = route('infrastructure', '/envoi/chauffe/{id}/reculer');
    expect(r).toMatch(/in: path\n\s+required: true\n\s+schema: \{ type: string \}/);
    expect(r).not.toContain('commun.yaml#/components/parameters/Identifiant');
    expect(r).toContain("'409'");
    expect(lire('infrastructure')).not.toMatch(/\/envoi\/chauffe\/[^\n]*avancer|operationId: avancerChauffe/);
  });

  it('boîtes : suspendre (403), réactiver (403, 409), ajout et test de connexion en 501', () => {
    expect(route('infrastructure', '/envoi/boites/{id}/suspendre')).toContain('users.manage');
    const reactiver = route('infrastructure', '/envoi/boites/{id}/reactiver');
    expect(reactiver).toContain('workspaces.manage');
    expect(reactiver).toContain("'409'");
    expect(route('infrastructure', '/envoi/boites').match(/'501'/g)).toHaveLength(1);
    expect(route('infrastructure', '/envoi/boites/test-connexion')).toContain("'501'");
  });

  it('boîtes : ni chauffe propre, ni identifiants chiffrés, hôte ou port dans la lecture', () => {
    const boite = schema('commun', 'BoiteResume');
    expect(requis(boite)).toEqual(expect.arrayContaining(['quota_jour', 'envoyes_aujourdhui']));
    expect(requis(boite)).not.toContain('chauffe');
    expect(boite).not.toMatch(/\n {8}chauffe:/);
    const texte = lire('commun') + lire('infrastructure');
    expect(texte).not.toMatch(/^\s+(identifiants_chiffres|hote|port):/m);
    expect(schema('commun', 'DomaineResume')).toMatch(/chauffe:/);
  });

  it('séquences : lecture, fiche, pause (users.manage), reprise (workspaces.manage, 409)', () => {
    expect(route('sequences', '/envoi/sequences')).toContain('listerSequences');
    expect(route('sequences', '/envoi/sequences/{id}')).toContain('lireSequence');
    expect(route('sequences', '/envoi/sequences/{id}/pause')).toContain('users.manage');
    const reprise = route('sequences', '/envoi/sequences/{id}/reprise');
    expect(reprise).toContain('workspaces.manage');
    expect(reprise).toContain("'409'");
  });

  it('séquences : aucun corps de message ni donnée de prospect', () => {
    const texte = schema('sequences', 'EtapeSequence') + schema('sequences', 'SequenceResume');
    expect(texte).not.toMatch(/^\s+(corps|contenu|html|texte|destinataire|adresse|email|prospect|contact)\w*:/im);
  });

  it('aucune route « témoins », « temoin-off » ou « tout-arreter » dans les zones amendées', () => {
    for (const zone of ['pilotage', 'infrastructure', 'sequences']) {
      const chemins = [...lire(zone).matchAll(/^ {2}(\/\S+):\s*$/gm)].map((m) => m[1]!);
      expect(chemins.length).toBeGreaterThan(0);
      expect(chemins.filter((c) => /temoin|tout-arreter/i.test(c)), zone).toEqual([]);
    }
  });
});
