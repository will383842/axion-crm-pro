/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-assignment -- les réponses JSON de la démo sont lues telles quelles */
/**
 * Démo d'Envoi (L8-F03c) : les handlers rendent exactement les formes du contrat
 * amendé — feu vert, chauffe, boîtes, séquences.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { getResponse } from 'msw';
import { demoDb } from '@/demo/base';
import { cleRoute, handlers } from '@/demo/handlers';

const ORIGINE = 'http://localhost';

async function appeler(methode: string, chemin: string, corps?: unknown) {
  const init: RequestInit = { method: methode };
  if (corps !== undefined) {
    init.body = JSON.stringify(corps);
    init.headers = { 'content-type': 'application/json' };
  }
  const reponse = await getResponse(handlers, new Request(`${ORIGINE}/api/v1${chemin}`, init));
  expect(reponse, `${methode} ${chemin}`).toBeDefined();
  const texte = await reponse!.text();
  return { statut: reponse!.status, json: texte === '' ? null : (JSON.parse(texte) as Record<string, any>) };
}

beforeEach(() => demoDb.reinitialiser());

describe('démo — feu vert', () => {
  it('rend les points au nouveau format, bloquants_restants et verrous', async () => {
    const { statut, json } = await appeler('GET', '/envoi/feu-vert');
    expect(statut).toBe(200);
    expect(json!['verrous']).toMatchObject({ envoi_reel_actif: false, fournisseurs_temoins_requis: 6 });
    for (const p of json!['points']) {
      expect(Object.keys(p).sort()).toEqual(['bloquant', 'code', 'detail', 'etat', 'fait', 'groupe', 'libelle', 'nature', 'preuve', 'source']);
      expect(p.code).toMatch(/^(auto|manuel)\./);
      expect(p.code.startsWith(`${p.nature}.`)).toBe(true);
      expect(p.fait).toBe(p.etat === 'fait');
      expect(['verrous', 'banc', 'dns', 'serveur', 'temoins', 'conformite', 'exploitation']).toContain(p.groupe);
      expect(['fait', 'a_faire', 'attend_serveur_reel']).toContain(p.etat);
    }
    const restants = json!['points'].filter((p: any) => p.bloquant && !p.fait).length;
    expect(json!['bloquants_restants']).toBe(restants);
    expect(json!['faits']).toBe(json!['points'].filter((p: any) => p.fait).length);
  });

  it('pose puis retire une preuve sur un point manuel', async () => {
    const pose = await appeler('POST', '/envoi/feu-vert/manuel.boucles_retour/fait', { preuve: 'Inscription faite.' });
    expect(pose.statut).toBe(200);
    expect(pose.json).toMatchObject({ code: 'manuel.boucles_retour', etat: 'fait', fait: true, preuve: { texte: 'Inscription faite.' } });
    const retire = await appeler('POST', '/envoi/feu-vert/manuel.boucles_retour/fait', { preuve: 'Erreur de saisie.', fait: false });
    expect(retire.json).toMatchObject({ etat: 'a_faire', fait: false, preuve: null });
  });

  it('404 code inconnu, 422 code auto.* ou preuve avec une adresse e-mail ou hors longueur', async () => {
    expect((await appeler('POST', '/envoi/feu-vert/manuel.inconnu/fait', { preuve: 'x'.repeat(12) })).statut).toBe(404);
    expect((await appeler('POST', '/envoi/feu-vert/auto.dns_au_vert/fait', { preuve: 'x'.repeat(12) })).statut).toBe(422);
    expect((await appeler('POST', '/envoi/feu-vert/manuel.boucles_retour/fait', { preuve: 'écrit à jean@exemple.test' })).statut).toBe(422);
    expect((await appeler('POST', '/envoi/feu-vert/manuel.boucles_retour/fait', { preuve: '' })).statut).toBe(422);
    expect((await appeler('POST', '/envoi/feu-vert/manuel.boucles_retour/fait', { preuve: 'a'.repeat(501) })).statut).toBe(422);
  });
});

describe('démo — feu vert : règles de la preuve', () => {
  const poser = (code: string, preuve: unknown) => appeler('POST', `/envoi/feu-vert/${code}/fait`, { preuve });
  it('refuse en 422 une preuve de moins de 10 caractères', async () => {
    expect((await poser('manuel.boucles_retour', 'court')).statut).toBe(422);
    expect((await poser('manuel.boucles_retour', '   123456789   ')).statut).toBe(422);
    expect((await poser('manuel.boucles_retour', '1234567890')).statut).toBe(200);
  });
  it('422 pour tout code auto. même inconnu, AVANT le 404', async () => {
    expect((await poser('auto.nexiste_pas', 'x'.repeat(12))).statut).toBe(422);
    expect((await poser('manuel.nexiste_pas', 'x'.repeat(12))).statut).toBe(404);
  });
  it('détecte une adresse avec espaces autour du @ ou un @ pleine chasse', async () => {
    expect((await poser('manuel.boucles_retour', 'écrit à jean @ exemple.test ok')).statut).toBe(422);
    expect((await poser('manuel.boucles_retour', 'écrit à jean＠exemple.test ok')).statut).toBe(422);
    expect((await poser('manuel.boucles_retour', 'Inscription faite ce jour.')).statut).toBe(200);
  });
});

describe('démo — chauffe', () => {
  it('rend budget, goulot, termes, ressources et table des paliers', async () => {
    const { json } = await appeler('GET', '/envoi/chauffe');
    expect(Object.keys(json!).sort()).toEqual(['budget_du_jour', 'goulot', 'jour', 'paliers', 'plafond_boite', 'ressources', 'termes']);
    expect(json!['jour']).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(Object.keys(json!['termes']).sort()).toEqual(['capacite_reponse', 'courbe_domaine', 'courbe_ip', 'plafond_boites', 'plafond_serveur']);
    expect(json!['goulot']).toHaveProperty('terme');
    expect(json!['paliers'].map((p: any) => p.palier)).toEqual(['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']);
    expect(json!['ressources'].length).toBeGreaterThan(0);
    for (const r of json!['ressources']) {
      expect(Object.keys(r).sort()).toEqual(['envoyes_aujourdhui', 'id', 'jours_avant_palier_suivant', 'jours_effectifs', 'libelle', 'motif_tenue', 'palier', 'peut_reculer', 'tenu_jusqu_au', 'total_palier', 'type']);
    }
  });

  it('reculer : un palier, une seule fois par jour ; 409 en P1 ; 404 inconnu ; pas de route avancer', async () => {
    demoDb.domaines.modifier('dom-c', { chauffe: { palier: 'P4', jours_effectifs: 12, total_palier: 60, tenu_jusqu_au: null } });
    const un = await appeler('POST', '/envoi/chauffe/domaine:dom-c/reculer');
    expect(un.statut).toBe(200);
    expect(un.json).toMatchObject({ id: 'domaine:dom-c', palier: 'P3', peut_reculer: false });
    const deux = await appeler('POST', '/envoi/chauffe/domaine:dom-c/reculer');
    expect(deux.statut).toBe(200);
    expect(deux.json!['palier']).toBe('P3');
    expect((await appeler('POST', '/envoi/chauffe/domaine:inconnu/reculer')).statut).toBe(404);
    expect(handlers.map(cleRoute).filter((c) => /chauffe[^ ]*avancer/.test(c))).toEqual([]);
  });

  it('reculer depuis P1 donne 409', async () => {
    demoDb.domaines.modifier('dom-c', { chauffe: { palier: 'P1', jours_effectifs: 1, total_palier: 10, tenu_jusqu_au: null } });
    expect((await appeler('POST', '/envoi/chauffe/domaine:dom-c/reculer')).statut).toBe(409);
  });
});

describe('démo — chauffe : l’IP et le domaine de même nom sont indépendants', () => {
  const chauffeDe = async (id: string) => (await appeler('GET', '/envoi/chauffe')).json!['ressources'].find((r: any) => r.id === id);

  it('reculer l’IP ne touche pas le domaine, et inversement', async () => {
    const domaineAvant = await chauffeDe('domaine:dom-c');
    const ipAvant = await chauffeDe('ip:192.0.2.11');
    expect(domaineAvant.palier).toBe(ipAvant.palier);
    expect((await appeler('POST', '/envoi/chauffe/ip:192.0.2.11/reculer')).json).toMatchObject({ palier: 'P1' });
    expect(await chauffeDe('domaine:dom-c')).toEqual(domaineAvant);
    const ipApres = await chauffeDe('ip:192.0.2.11');
    expect((await appeler('POST', '/envoi/chauffe/domaine:dom-c/reculer')).json).toMatchObject({ palier: 'P1' });
    expect(await chauffeDe('ip:192.0.2.11')).toEqual(ipApres);
  });

  it('le verrou « un recul par jour » est par ressource (type et identifiant)', async () => {
    demoDb.domaines.modifier('dom-c', { chauffe: { palier: 'P4', jours_effectifs: 12, total_palier: 60, tenu_jusqu_au: null } });
    await appeler('POST', '/envoi/chauffe/domaine:dom-c/reculer');
    const ip = await chauffeDe('ip:192.0.2.11');
    expect(ip.peut_reculer).toBe(true);
    expect((await chauffeDe('domaine:dom-c')).peut_reculer).toBe(false);
  });

  it('l’IP reculée ne change pas la chauffe enregistrée du domaine', async () => {
    await appeler('POST', '/envoi/chauffe/ip:192.0.2.11/reculer');
    expect(demoDb.domaines.trouver('dom-c')?.chauffe?.palier).toBe('P2');
  });
});

describe('démo — boîtes', () => {
  it('liste et fiche sans chauffe, identifiants, hôte ni port', async () => {
    const liste = await appeler('GET', '/envoi/boites');
    const fiche = await appeler('GET', '/envoi/boites/boi-paul');
    const texte = JSON.stringify([liste.json, fiche.json]);
    expect(texte).not.toMatch(/identifiants_chiffres|"hote"|"port"/);
    for (const b of [...liste.json!['data'], fiche.json!['boite']]) {
      expect(b).not.toHaveProperty('chauffe');
      expect(b).toHaveProperty('quota_jour');
      expect(b).toHaveProperty('envoyes_aujourdhui');
    }
  });

  it('ajout et test de connexion : 501 au palier 0', async () => {
    expect((await appeler('POST', '/envoi/boites', { adresse: 'a@prospection-a.example', domaine_id: 'dom-a', nom_affiche: 'A' })).statut).toBe(501);
    expect((await appeler('POST', '/envoi/boites/test-connexion', { adresse: 'a@prospection-a.example', domaine_id: 'dom-a' })).statut).toBe(501);
  });

  it('suspendre puis réactiver ; réactiver refusé (409) pendant une pause automatique', async () => {
    expect((await appeler('POST', '/envoi/boites/boi-claire/suspendre')).json).toMatchObject({ statut: 'suspendue' });
    expect((await appeler('POST', '/envoi/boites/boi-claire/reactiver')).statut).toBe(200);
    demoDb.boites.modifier('boi-claire', { statut: 'suspendue', etat: 'rouge' });
    expect((await appeler('POST', '/envoi/boites/boi-claire/reactiver')).statut).toBe(409);
  });
});

describe('démo — séquences', () => {
  it('liste au format du contrat, sans corps de message', async () => {
    const { json } = await appeler('GET', '/envoi/sequences');
    expect(json!['sequences'].length).toBeGreaterThan(0);
    for (const s of json!['sequences']) {
      expect(Object.keys(s).sort()).toEqual(['creee_le', 'id', 'inscriptions', 'motif_pause', 'nb_etapes', 'nom', 'prochain_envoi', 'reservee_aux_temoins', 'statut']);
      expect(['active', 'en_pause', 'terminee']).toContain(s.statut);
      expect(s.nb_etapes).toBeGreaterThanOrEqual(1);
      expect(s.nb_etapes).toBeLessThanOrEqual(3);
      expect(Object.keys(s.inscriptions).sort()).toEqual(['arretees', 'en_cours', 'reportees', 'terminees', 'total']);
    }
  });

  it('fiche : étapes 1 à 3, peut_reprendre et raison_refus_reprise', async () => {
    const { json } = await appeler('GET', '/envoi/sequences/seq-04');
    expect(json!['etapes'].map((e: any) => e.rang)).toEqual([1, 2, 3]);
    expect(Object.keys(json!['etapes'][0]).sort()).toEqual(['delai_jours', 'desinscriptions', 'envoyes', 'objet', 'rang', 'rebonds_durs', 'reponses']);
    expect(json).toMatchObject({ peut_reprendre: false });
    expect(typeof json!['raison_refus_reprise']).toBe('string');
    expect((await appeler('GET', '/envoi/sequences/inconnue')).statut).toBe(404);
  });

  it('pause puis reprise ; reprise refusée (409) sous frein ou arrêt général', async () => {
    expect((await appeler('POST', '/envoi/sequences/seq-01/pause')).json).toMatchObject({ statut: 'en_pause', motif_pause: 'manuelle', peut_reprendre: true });
    expect((await appeler('POST', '/envoi/sequences/seq-01/reprise')).json).toMatchObject({ statut: 'active', motif_pause: null });
    expect((await appeler('POST', '/envoi/sequences/seq-04/reprise')).statut).toBe(409);
  });
});
