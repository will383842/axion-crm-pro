/**
 * `demoDb` — la base en mémoire de la démo.
 *
 * Collections typées (`demoDb.campagnes`, `demoDb.contacts`…), identifiants
 * stables (ceux du jeu à graine fixe), et persistance de SESSION : chaque geste
 * (ajout, modification) est rejoué sur le jeu régénéré au rechargement, pour
 * qu'un « F5 » pendant une présentation ne perde rien. « Remettre la démo à
 * zéro » efface ce journal (`reinitialiser()`).
 *
 * Pourquoi un journal et pas une copie de la base : le jeu complet pèse
 * plusieurs centaines de kilo-octets ; le journal, quelques gestes. Le jeu,
 * lui, se régénère à l'identique depuis la graine.
 *
 * Rien ne se supprime (règle n° 2) : pas de méthode de suppression. On
 * modifie (archiver, marquer « ne plus écrire »).
 *
 * Zones des lots suivants : `demoDb.etendre('<zone>', (alea) => donnees)`.
 */
import { genererJeu } from './donnees';
import type { EtatEnvois, JeuDemo, NomCollection } from './donnees/types';
import { graine, type Alea } from './graine';

export const CLE_SESSION = 'axion-demo:journal';
const VERSION_JOURNAL = 1;

type Identifiant = string | number;
type Element<K extends NomCollection> = JeuDemo[K][number];

type Geste =
  | { sorte: 'ajouter'; collection: string; valeur: unknown }
  | { sorte: 'modifier'; collection: string; id: Identifiant; patch: Record<string, unknown> }
  | { sorte: 'etat'; nom: 'etatEnvois'; patch: Record<string, unknown> };

interface Journal {
  version: number;
  gestes: Geste[];
}

function lireSession(): Geste[] {
  try {
    const brut = globalThis.sessionStorage?.getItem(CLE_SESSION);
    if (!brut) return [];
    const journal = JSON.parse(brut) as Journal;
    return journal.version === VERSION_JOURNAL && Array.isArray(journal.gestes) ? journal.gestes : [];
  } catch {
    return [];
  }
}

function ecrireSession(gestes: Geste[]): void {
  try {
    if (gestes.length === 0) globalThis.sessionStorage?.removeItem(CLE_SESSION);
    else globalThis.sessionStorage?.setItem(CLE_SESSION, JSON.stringify({ version: VERSION_JOURNAL, gestes } satisfies Journal));
  } catch {
    // Navigation privée, stockage plein : la démo marche, sans persistance.
  }
}

/** Une collection de la base : lecture, ajout, modification. Jamais de suppression. */
export class Collection<T extends { id: Identifiant }> {
  constructor(
    private readonly lire: () => T[],
    private readonly noter: (geste: Geste) => void,
    private readonly nom: string,
  ) {}

  tous(): readonly T[] {
    return this.lire();
  }

  trouver(id: Identifiant): T | undefined {
    return this.lire().find((e) => e.id === id || String(e.id) === String(id));
  }

  filtrer(predicat: (e: T) => boolean): T[] {
    return this.lire().filter(predicat);
  }

  compter(): number {
    return this.lire().length;
  }

  ajouter(valeur: T): T {
    const copie = structuredClone(valeur);
    this.lire().push(copie);
    this.noter({ sorte: 'ajouter', collection: this.nom, valeur: copie });
    return copie;
  }

  modifier(id: Identifiant, patch: Partial<T>): T | undefined {
    const cible = this.trouver(id);
    if (!cible) return undefined;
    Object.assign(cible, structuredClone(patch));
    this.noter({ sorte: 'modifier', collection: this.nom, id: cible.id, patch: patch });
    return cible;
  }

  /** Prochain identifiant numérique libre (pour les collections à id entier). */
  prochainId(): number {
    return this.lire().reduce((max, e) => (typeof e.id === 'number' && e.id > max ? e.id : max), 0) + 1;
  }
}

type Fabrique<T> = (alea: Alea) => T;

class BaseDemo {
  private jeu: JeuDemo;
  private zones = new Map<string, { fabrique: Fabrique<unknown>; donnees: unknown }>();
  private gestes: Geste[] = [];
  private enRejeu = false;

  constructor() {
    this.jeu = genererJeu();
    this.rejouer(lireSession(), true);
  }

  private noter = (geste: Geste): void => {
    if (this.enRejeu) return;
    this.gestes.push(geste);
    ecrireSession(this.gestes);
  };

  private collection<K extends NomCollection>(nom: K): Collection<Element<K> & { id: Identifiant }> {
    return new Collection<Element<K> & { id: Identifiant }>(
      () => this.jeu[nom],
      this.noter,
      nom,
    );
  }

  /** Collection d'une zone étendue (`etendre`), tableau d'objets à `id`. */
  private collectionDeZone(nom: string): Collection<{ id: Identifiant }> | undefined {
    const [zone, cle] = nom.split(':');
    const donnees = zone !== undefined ? (this.zones.get(zone)?.donnees as Record<string, unknown> | undefined) : undefined;
    const tableau = cle !== undefined ? donnees?.[cle] : undefined;
    if (!Array.isArray(tableau)) return undefined;
    return new Collection(() => tableau as Array<{ id: Identifiant }>, this.noter, nom);
  }

  /**
   * Applique des gestes enregistrés. `conserver` : les garder au journal (au
   * démarrage), même ceux d'une zone pas encore étendue — ils seront appliqués
   * par `etendre`, et ne doivent pas disparaître de la session d'ici là.
   */
  private rejouer(gestes: Geste[], conserver: boolean): void {
    this.enRejeu = true;
    try {
      for (const g of gestes) {
        if (g.sorte === 'etat') {
          Object.assign(this.jeu.etatEnvois, g.patch);
        } else {
          const coll =
            g.collection in this.jeu
              ? this.collection(g.collection as NomCollection)
              : this.collectionDeZone(g.collection);
          if (coll && g.sorte === 'ajouter') coll.ajouter(g.valeur as never);
          else if (coll && g.sorte === 'modifier') coll.modifier(g.id, g.patch);
        }
        if (conserver) this.gestes.push(g);
      }
    } finally {
      this.enRejeu = false;
    }
  }

  // ── Collections typées ───────────────────────────────────────────────────
  get espaces() { return this.collection('espaces'); }
  get utilisateurs() { return this.collection('utilisateurs'); }
  get notifications() { return this.collection('notifications'); }
  get entreprises() { return this.collection('entreprises'); }
  get contacts() { return this.collection('contacts'); }
  get medias() { return this.collection('medias'); }
  get journalistes() { return this.collection('journalistes'); }
  get federations() { return this.collection('federations'); }
  get evenements() { return this.collection('evenements'); }
  get audiences() { return this.collection('audiences'); }
  get listes() { return this.collection('listes'); }
  get etiquettes() { return this.collection('etiquettes'); }
  get campagnes() { return this.collection('campagnes'); }
  get domaines() { return this.collection('domaines'); }
  get boites() { return this.collection('boites'); }
  get serveurs() { return this.collection('serveurs'); }
  get destinataires() { return this.collection('destinataires'); }
  get evenementsEnvoi() { return this.collection('evenementsEnvoi'); }
  get reponses() { return this.collection('reponses'); }
  get messagesTypes() { return this.collection('messagesTypes'); }
  get reglesAlerte() { return this.collection('reglesAlerte'); }
  get desinscriptions() { return this.collection('desinscriptions'); }
  get opposition() { return this.collection('opposition'); }

  // ── État global des envois (objet unique) ────────────────────────────────
  get etatEnvois(): { lire: () => EtatEnvois; modifier: (patch: Partial<EtatEnvois>) => EtatEnvois } {
    return {
      lire: () => this.jeu.etatEnvois,
      modifier: (patch) => {
        Object.assign(this.jeu.etatEnvois, patch);
        this.noter({ sorte: 'etat', nom: 'etatEnvois', patch: patch });
        return this.jeu.etatEnvois;
      },
    };
  }

  /**
   * Ajoute les données d'une zone (lot d'écran) générées par SA graine.
   * Appelé deux fois avec la même zone, rend les mêmes données (la première
   * fabrique gagne). Les tableaux d'objets à `id` de la zone deviennent des
   * collections : `demoDb.zone('<zone>', '<clé>')`.
   */
  etendre<T>(zone: string, fabrique: Fabrique<T>): T {
    const deja = this.zones.get(zone);
    if (deja) return deja.donnees as T;
    const donnees = fabrique(graine(zone));
    this.zones.set(zone, { fabrique: fabrique, donnees });
    // Les gestes déjà notés sur cette zone (session) sont rejoués.
    const siens = this.gestes.filter((g) => g.sorte !== 'etat' && g.collection.startsWith(`${zone}:`));
    if (siens.length > 0) this.rejouer(siens, false);
    return donnees;
  }

  /** Collection d'une zone étendue (`etendre`) : `demoDb.zone('reponses', 'raccourcis')`. */
  zone<T extends { id: Identifiant }>(zone: string, cle: string): Collection<T> {
    const coll = this.collectionDeZone(`${zone}:${cle}`);
    if (!coll) throw new Error(`Zone de démo inconnue : ${zone}:${cle} (appelez demoDb.etendre d'abord)`);
    return coll as unknown as Collection<T>;
  }

  /** Revient au jeu d'origine et efface la persistance de session. */
  reinitialiser(): void {
    this.gestes = [];
    ecrireSession([]);
    this.jeu = genererJeu();
    for (const [zone, z] of this.zones) z.donnees = z.fabrique(graine(zone));
  }

  /** Vue complète, pour les gardes (aucune copie profonde : lecture seule). */
  instantane(): { jeu: JeuDemo; zones: Record<string, unknown> } {
    return { jeu: this.jeu, zones: Object.fromEntries([...this.zones].map(([k, v]) => [k, v.donnees])) };
  }

  /** Nombre de gestes enregistrés depuis la dernière remise à zéro. */
  nombreDeGestes(): number {
    return this.gestes.length;
  }
}

export type { BaseDemo };
export const demoDb = new BaseDemo();
