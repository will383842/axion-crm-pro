/**
 * GARDE — le jeu factice de la démo ne ressemble à rien de réel (DM0-DEMO).
 *
 * Le dépôt est PUBLIC. Une démo montrée à des prospects qui afficherait une
 * vraie adresse, une vraie adresse IP ou un vrai SIREN serait à la fois un
 * problème de protection des données et un problème d'image. Ces règles sont
 * tenues ici, sur TOUT ce que contient `demoDb` (base en mémoire), pas sur un
 * échantillon :
 *
 *  · toute adresse e-mail finit par `@example.test` ou par un domaine `.example` ;
 *  · toute adresse IP est dans une plage de DOCUMENTATION (RFC 5737, RFC 3849) ;
 *  · tout SIREN a une clé de Luhn FAUSSE (il ne peut désigner aucune entreprise) ;
 *  · aucun nom de la liste des personnes réelles connues du projet ;
 *  · même graine, même jeu : deux générations sont identiques à l'octet.
 */
import { describe, it, expect } from 'vitest';
import { genererJeu } from '@/demo/donnees';
import { demoDb } from '@/demo/base';

// ── Fonctions pures (éprouvées par le témoin plus bas) ────────────────────

/** Parcourt récursivement une valeur et renvoie chaque chaîne avec son chemin. */
function chaines(valeur: unknown, chemin = '$', sortie: Array<[string, string]> = []): Array<[string, string]> {
  if (typeof valeur === 'string') {
    sortie.push([chemin, valeur]);
  } else if (Array.isArray(valeur)) {
    valeur.forEach((v, i) => chaines(v, `${chemin}[${i}]`, sortie));
  } else if (valeur !== null && typeof valeur === 'object') {
    for (const [cle, v] of Object.entries(valeur)) chaines(v, `${chemin}.${cle}`, sortie);
  }
  return sortie;
}

/** Parcourt récursivement et renvoie la valeur de chaque champ nommé `cle`. */
function champs(valeur: unknown, cle: string, sortie: unknown[] = []): unknown[] {
  if (Array.isArray(valeur)) {
    valeur.forEach((v) => champs(v, cle, sortie));
  } else if (valeur !== null && typeof valeur === 'object') {
    for (const [k, v] of Object.entries(valeur)) {
      if (k === cle) sortie.push(v);
      champs(v, cle, sortie);
    }
  }
  return sortie;
}

/** Toute forme de mesure de clic : clic, cliqué, liens_cliques, clicks… */
const MOTIF_CLIC = /cli(?:c|qu)|click/i;
const MOTIF_OUVERTURE = /ouverture|pixel|automatique_probable|raison_automatique/i;
const MOTIF_EMAIL = /[\w.+-]+@([\w-]+(?:\.[\w-]+)+)/g;
const MOTIF_IPV4 = /\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g;
const MOTIF_IPV6 = /\b([0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/gi;

export function adresseFactice(adresse: string): boolean {
  const domaine = adresse.split('@')[1] ?? '';
  return domaine === 'example.test' || domaine.endsWith('.example') || domaine === 'exemple.invalid';
}

export function ipDeDocumentation(ip: string): boolean {
  return /^192\.0\.2\.\d{1,3}$/.test(ip) || /^198\.51\.100\.\d{1,3}$/.test(ip) || /^203\.0\.113\.\d{1,3}$/.test(ip);
}

export function ipv6DeDocumentation(ip: string): boolean {
  return /^2001:0?db8:/i.test(ip);
}

/** Clé de Luhn : vraie si le numéro passe le contrôle (donc POURRAIT être réel). */
export function luhnValide(numero: string): boolean {
  let somme = 0;
  const chiffres = numero.split('').reverse();
  chiffres.forEach((c, i) => {
    let n = Number(c);
    if (i % 2 === 1) {
      n *= 2;
      if (n > 9) n -= 9;
    }
    somme += n;
  });
  return somme % 10 === 0;
}

/**
 * Personnes RÉELLES connues du projet : figures publiques de la presse (le
 * dépôt a déjà exposé par erreur des journalistes nommés, voir
 * `infra/scripts/garde-donnees-personnelles.sh`) et l'adresse réelle du
 * propriétaire. Un nom de démo ne doit jamais tomber sur l'une d'elles.
 */
const PERSONNES_REELLES = [
  'Élise Lucet',
  'Léa Salamé',
  'Anne-Sophie Lapix',
  'Laurent Delahousse',
  'Gilles Bouleau',
  'Patrick Cohen',
  'Nicolas Demorand',
  'Apolline de Malherbe',
  'Jean-Jacques Bourdin',
  'Sonia Mabrouk',
  'Emmanuel Macron',
];
const ADRESSES_REELLES = ['axion-ia.com', 'axion-crm-pro.com', 'gmail.com', 'outlook.fr', 'orange.fr'];

function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

// ── Le jeu réel ───────────────────────────────────────────────────────────

const instantane = demoDb.instantane();
const toutes = chaines(instantane);

describe('jeu factice — la garde a bien quelque chose sous les yeux', () => {
  it('trouve des milliers de chaînes, des adresses, des IP et des SIREN', () => {
    expect(toutes.length).toBeGreaterThan(5000);
    expect(toutes.filter(([, v]) => v.includes('@')).length).toBeGreaterThan(900);
    expect(toutes.filter(([, v]) => new RegExp(MOTIF_IPV4.source).test(v)).length).toBeGreaterThanOrEqual(2);
    expect(champs(instantane, 'siren').length).toBeGreaterThanOrEqual(300);
  });

  it('a les volumes annoncés par le lot', () => {
    expect(demoDb.entreprises.compter()).toBeGreaterThanOrEqual(280);
    expect(demoDb.contacts.compter()).toBeGreaterThanOrEqual(850);
    expect(demoDb.campagnes.compter()).toBe(12);
    expect(demoDb.domaines.compter()).toBe(4);
    expect(demoDb.boites.compter()).toBe(8);
    expect(demoDb.serveurs.compter()).toBe(2);
    expect(demoDb.reponses.compter()).toBe(60);
    expect(demoDb.notifications.compter()).toBe(6);
    expect(demoDb.espaces.tous().map((e) => e.name)).toEqual(['Axion (démo)']);
  });

  it('garde les repères des maquettes', () => {
    const texte = JSON.stringify(instantane);
    for (const repere of [
      'Paul Morin',
      'Yves Roche',
      'Boulangerie des Tilleuls',
      'Le Courrier du Rhône',
      'Léa Fournier',
      'Fédération BTP Rhône',
      'Salon des artisans de Vienne',
      'salon-artisans-2026',
      'Organisateurs Rhône-Alpes',
      'prospection-a.example',
      'camille@prospection-b.example',
    ]) {
      const [prenom, ...reste] = repere.split(' ');
      // Les personnes sont rangées en prénom / nom séparés : on cherche les deux.
      const trouve = texte.includes(repere) || (reste.length === 1 && texte.includes(`"${prenom}"`) && texte.includes(`"${reste[0]}"`));
      expect(trouve, repere).toBe(true);
    }
  });
});

describe('jeu factice — rien de réel', () => {
  it('toute adresse e-mail est en @example.test ou en .example', () => {
    const fautives: string[] = [];
    for (const [chemin, v] of toutes) {
      for (const m of v.matchAll(MOTIF_EMAIL)) {
        if (!adresseFactice(m[0])) fautives.push(`${chemin} : ${m[0]}`);
      }
    }
    expect(fautives).toEqual([]);
  });

  it('tout domaine ou site cité est en .example', () => {
    const fautifs: string[] = [];
    for (const [chemin, v] of toutes) {
      for (const m of v.matchAll(/https?:\/\/([\w.-]+)/g)) {
        const hote = m[1] ?? '';
        if (!hote.endsWith('.example') && hote !== 'demo.invalid') fautifs.push(`${chemin} : ${m[0]}`);
      }
    }
    expect(fautifs).toEqual([]);
  });

  it('toute adresse IP est dans une plage de documentation', () => {
    const fautives: string[] = [];
    for (const [chemin, v] of toutes) {
      for (const m of v.matchAll(MOTIF_IPV4)) {
        if (!ipDeDocumentation(m[0])) fautives.push(`${chemin} : ${m[0]}`);
      }
      for (const m of v.matchAll(MOTIF_IPV6)) {
        if (m[0].split(':').length >= 3 && !ipv6DeDocumentation(m[0])) fautives.push(`${chemin} : ${m[0]}`);
      }
    }
    expect(fautives).toEqual([]);
  });

  it('tout SIREN a 9 chiffres et une clé de Luhn fausse', () => {
    const fautifs = champs(instantane, 'siren').filter(
      (s) => typeof s !== 'string' || !/^\d{9}$/.test(s) || luhnValide(s),
    );
    expect(fautifs).toEqual([]);
  });

  it('aucun nom de personne réelle connue du projet, aucune adresse réelle', () => {
    const texte = normaliser(JSON.stringify(instantane));
    const prenomsNoms = normaliser(
      JSON.stringify(
        [...demoDb.contacts.tous(), ...demoDb.journalistes.tous()].map((p) => `${p.first_name} ${p.last_name}`),
      ),
    );
    const trouves = PERSONNES_REELLES.filter((n) => texte.includes(normaliser(n)) || prenomsNoms.includes(normaliser(n)));
    expect(trouves).toEqual([]);
    expect(ADRESSES_REELLES.filter((d) => texte.includes(d))).toEqual([]);
  });

  it('aucune date de démo ne dépend de l’horloge réelle', () => {
    // Toutes les dates du jeu tiennent entre le 1er décembre 2026 et fin 2027.
    const dates = toutes.filter(([, v]) => /^\d{4}-\d{2}-\d{2}T/.test(v)).map(([, v]) => v);
    expect(dates.length).toBeGreaterThan(1000);
    expect(dates.filter((d) => d < '2026-12-01' || d > '2027-12-31')).toEqual([]);
  });
});

describe('jeu factice — rien n’arrive dans le futur', () => {
  it('réponses et événements d’envoi sont tous passés', () => {
    const maintenantIso = '2027-01-12T08:30:00.000Z';
    const dates = [
      ...demoDb.reponses.tous().map((r) => r.recue_le),
      ...demoDb.evenementsEnvoi.tous().map((e) => e.survenu_le),
      ...demoDb.destinataires.tous().flatMap((d) => [d.dernier_evenement_le]),
      ...demoDb.notifications.tous().map((n) => n.created_at),
    ].filter((d): d is string => typeof d === 'string');
    expect(dates.length).toBeGreaterThan(1500);
    expect(dates.filter((d) => d > maintenantIso)).toEqual([]);
  });
});

describe('jeu factice — graine fixe', () => {
  it('deux générations avec la même graine sont identiques à l’octet', () => {
    expect(JSON.stringify(genererJeu())).toBe(JSON.stringify(genererJeu()));
  });

  it('la base remise à zéro revient au jeu généré', () => {
    demoDb.reinitialiser();
    expect(JSON.stringify(demoDb.instantane().jeu)).toBe(JSON.stringify(genererJeu()));
  });
});

describe('jeu factice — aucune mesure de lecture (ni clic, ni ouverture)', () => {
  it('aucun clic n’est suivi : ni champ, ni événement « clic »', () => {
    expect(demoDb.destinataires.tous().some((d) => 'clics' in d)).toBe(false);
    expect(demoDb.campagnes.tous().some((c) => 'clics' in c)).toBe(false);
    expect(demoDb.evenementsEnvoi.tous().some((e) => (e.type as string) === 'clic')).toBe(false);
    expect(JSON.stringify(genererJeu())).not.toMatch(MOTIF_CLIC);
  });

  it.each(['liens_cliques', 'Lien cliqué', 'nombre_clics', 'taux_clic', 'clicks'])(
    'témoin : le motif anti-clic rougit sur « %s »',
    (forme) => {
      expect(forme).toMatch(MOTIF_CLIC);
    },
  );

  it('aucune ouverture : ni champ, ni événement, ni mot, ni automatique_probable', () => {
    const jeu = JSON.stringify(genererJeu());
    expect(jeu).not.toMatch(MOTIF_OUVERTURE);
    expect(demoDb.campagnes.tous().some((c) => 'suivre_ouvertures' in c || 'ouvertures' in c)).toBe(false);
    expect(demoDb.destinataires.tous().some((d) => 'ouverture' in d)).toBe(false);
    expect(demoDb.evenementsEnvoi.tous().some((e) => (e.type as string) === 'ouverture')).toBe(false);
    expect(demoDb.evenementsEnvoi.tous().some((e) => 'automatique_probable' in e)).toBe(false);
  });

  it.each(['ouverture', 'suivre_ouvertures', 'automatique_probable', 'raison_automatique', 'pixel'])(
    'témoin : le motif anti-ouverture rougit sur « %s »',
    (forme) => {
      expect(forme).toMatch(MOTIF_OUVERTURE);
    },
  );
});

// ── TÉMOIN : la garde sait rougir ─────────────────────────────────────────

describe('jeu factice — TÉMOIN', () => {
  it('voit une vraie adresse, une vraie IP et un SIREN valide', () => {
    expect(adresseFactice('paul.morin@example.test')).toBe(true);
    expect(adresseFactice('redaction@courrier-rhone.example')).toBe(true);
    expect(adresseFactice('contact@axion-ia.com')).toBe(false);
    expect(ipDeDocumentation('192.0.2.10')).toBe(true);
    expect(ipDeDocumentation('8.8.8.8')).toBe(false);
    expect(ipv6DeDocumentation('2001:db8::1')).toBe(true);
    // 000000018 : forme valide (clé de Luhn juste) ; 000000019 : clé fausse.
    expect(luhnValide('000000018')).toBe(true);
    expect(luhnValide('000000019')).toBe(false);
  });
});
