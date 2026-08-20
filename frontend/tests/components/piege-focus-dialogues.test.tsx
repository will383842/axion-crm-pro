/**
 * GARDE D28-003 — « Modal, Drawer et GlobalSearch déclarent aria-modal="true"
 * sans piéger le focus ».
 *
 * Ce que la garde mesure, et pourquoi ainsi
 * ─────────────────────────────────────────
 * L'agent 28 a compté des SORTIES : combien de fois, en tabulant depuis
 * l'intérieur du dialogue, le focus se retrouve hors du dialogue. Son relevé :
 *
 *     A. Recherche globale : 6 Tab -> SORTIES du dialogue : 6 / 6
 *     B. Drawer mobile     : 10 Tab, dans le dialogue ? [false x10] -> 10 / 10
 *     C. Modal mobile      : 8 Tab -> SORTIES : 8 / 8
 *
 * On rejoue exactement ce protocole sous jsdom, avec `user-event` (dont le
 * `tab()` respecte `preventDefault()` sur le keydown — c'est ce qui rend la
 * mesure honnête : si le produit ne prévient pas, user-event déplace le focus
 * comme le ferait le navigateur).
 *
 * TROIS TÉMOINS, parce qu'un compteur de sorties à zéro peut mentir de trois
 * façons :
 *   1. « la sonde ne sait pas dire OUI » -> `ModaleSansPiege`, réplique exacte
 *      du markup d'AVANT correctif, doit rendre des sorties > 0 ;
 *   2. « le dialogue est vide, donc rien ne peut sortir » -> la sonde EXIGE au
 *      moins 2 éléments focalisables dans le dialogue et lève sinon ;
 *   3. « aucun dialogue n'a été trouvé » -> la sonde lève si le conteneur
 *      passé est nul.
 */
import { useState, type ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Modal, Drawer } from '@/components/ui/Modal';
import { GlobalSearch } from '@/components/ui/GlobalSearch';
import { renderScreen } from '../helpers/renderScreen';

/** Le sélecteur des éléments atteignables au clavier (ordre du document). */
const FOCALISABLES =
  'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),' +
  'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

interface Releve {
  /** Nombre de coups de Tab qui ont fini HORS du dialogue. */
  sorties: number;
  /** Nombre de coups joués. */
  coups: number;
  /** Éléments focalisables comptés dans le dialogue au moment du relevé. */
  focalisables: number;
  /** L'élément qui avait le focus juste après l'ouverture. */
  focusInitial: Element | null;
}

/**
 * Tabule `coups` fois et compte les sorties.
 *
 * ⚠️ Les deux `throw` ci-dessous ne sont pas de la défense : ce sont les
 * témoins 2 et 3. Sans eux, un dialogue vide ou absent rendrait
 * `sorties = 0` — un vert qui ne veut rien dire.
 */
async function releverSorties(
  dialogue: HTMLElement | null,
  coups: number,
  user: ReturnType<typeof userEvent.setup>,
  minimumFocalisables = 2,
): Promise<Releve> {
  if (!dialogue) {
    throw new Error('SONDE INVALIDE : aucun conteneur role="dialog" trouve.');
  }
  const focalisables = dialogue.querySelectorAll(FOCALISABLES).length;
  if (focalisables < minimumFocalisables) {
    throw new Error(
      `SONDE INVALIDE : ${focalisables} element(s) focalisable(s) dans le dialogue. ` +
        `Le minimum exige est ${minimumFocalisables}.`,
    );
  }
  const focusInitial = document.activeElement;
  let sorties = 0;
  for (let i = 0; i < coups; i += 1) {
    await user.tab();
    if (!dialogue.contains(document.activeElement)) sorties += 1;
  }
  return { sorties, coups, focalisables, focusInitial };
}

/** Harnais : un déclencheur HORS dialogue, pour mesurer la restitution du focus. */
function Harnais({
  rendre,
}: {
  rendre: (ouvert: boolean, fermer: () => void) => ReactNode;
}) {
  const [ouvert, setOuvert] = useState(false);
  return (
    <div>
      <button type="button" onClick={() => setOuvert(true)}>
        Declencheur
      </button>
      <button type="button">Voisin apres</button>
      {rendre(ouvert, () => setOuvert(false))}
    </div>
  );
}

/**
 * TÉMOIN 1 — le markup d'AVANT correctif, recopié de `Modal.tsx:43` tel qu'il
 * était : `role="dialog" aria-modal="true"`, Échap câblé, et RIEN d'autre.
 * La sonde doit le condamner. Si elle ne le condamne pas, elle ne mesure rien.
 */
function ModaleSansPiege({ ouvert, children }: { ouvert: boolean; children: ReactNode }) {
  if (!ouvert) return null;
  return (
    <div className="fixed inset-0" role="dialog" aria-modal="true" data-testid="temoin">
      <div>{children}</div>
    </div>
  );
}

describe('D28-003 — piege de focus des dialogues', () => {
  it("TEMOIN 1 : la sonde condamne une modale sans piege (le markup d'avant correctif)", async () => {
    const user = userEvent.setup();
    render(
      <Harnais
        rendre={(ouvert) => (
          <ModaleSansPiege ouvert={ouvert}>
            <input aria-label="Champ temoin" />
            <button type="button">Bouton temoin</button>
          </ModaleSansPiege>
        )}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Declencheur' }));
    const temoin = screen.getByTestId('temoin');
    const releve = await releverSorties(temoin, 6, user);

    // La sonde sait dire OUI : elle voit le focus s'echapper.
    expect(releve.sorties).toBeGreaterThan(0);
    expect(releve.focalisables).toBe(2);
  });

  it('TEMOIN 2 : la sonde refuse un dialogue vide au lieu de le declarer vert', async () => {
    const user = userEvent.setup();
    render(
      <div role="dialog" aria-modal="true" data-testid="vide">
        <p>Rien de focalisable ici.</p>
      </div>,
    );
    await expect(releverSorties(screen.getByTestId('vide'), 4, user)).rejects.toThrow(
      /SONDE INVALIDE : 0 element/,
    );
    // Et elle refuse aussi un dialogue a UN seul focalisable quand le cas
    // exige deux : le seuil est un parametre, pas une opinion.
    render(
      <div role="dialog" aria-modal="true" data-testid="un-seul">
        <button type="button">Seul</button>
      </div>,
    );
    await expect(releverSorties(screen.getByTestId('un-seul'), 4, user, 2)).rejects.toThrow(
      /SONDE INVALIDE : 1 element/,
    );
  });

  it('TEMOIN 3 : la sonde refuse un conteneur absent', async () => {
    const user = userEvent.setup();
    await expect(releverSorties(null, 4, user)).rejects.toThrow(/aucun conteneur/);
  });

  it('Modal : le focus entre, ne sort pas en 8 Tab, et revient au declencheur', async () => {
    const user = userEvent.setup();
    render(
      <Harnais
        rendre={(ouvert, fermer) => (
          <Modal open={ouvert} onClose={fermer} title="Titre de la modale">
            <input aria-label="Champ un" />
            <input aria-label="Champ deux" />
            <button type="button">Valider</button>
          </Modal>
        )}
      />,
    );
    const declencheur = screen.getByRole('button', { name: 'Declencheur' });
    await user.click(declencheur);

    const dialogue = screen.getByRole('dialog');
    // 1. le focus est ENTRE dans le dialogue a l'ouverture
    expect(dialogue.contains(document.activeElement)).toBe(true);

    // 2. il n'en sort pas — 8 coups, comme le releve C de l'agent 28
    const releve = await releverSorties(dialogue, 8, user);
    expect(releve.sorties).toBe(0);
    expect(releve.focalisables).toBeGreaterThanOrEqual(4); // 3 champs + le bouton Fermer

    // 3. Shift+Tab depuis le premier ne s'echappe pas non plus
    await user.tab({ shift: true });
    expect(dialogue.contains(document.activeElement)).toBe(true);

    // 4. Echap ferme ET rend le focus au declencheur (et non a un voisin)
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(declencheur);
  });

  it('Drawer : le focus entre, ne sort pas en 10 Tab, et revient au declencheur', async () => {
    const user = userEvent.setup();
    render(
      <Harnais
        rendre={(ouvert, fermer) => (
          <Drawer open={ouvert} onClose={fermer} title="Tiroir">
            <a href="/quelque-part">Un lien</a>
            <button type="button">Une action</button>
          </Drawer>
        )}
      />,
    );
    const declencheur = screen.getByRole('button', { name: 'Declencheur' });
    await user.click(declencheur);

    const dialogue = screen.getByRole('dialog');
    expect(dialogue.contains(document.activeElement)).toBe(true);

    const releve = await releverSorties(dialogue, 10, user);
    expect(releve.sorties).toBe(0);

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.activeElement).toBe(declencheur);
  });

  it('GlobalSearch : le focus ne sort pas en 6 Tab, et revient au declencheur', async () => {
    const user = userEvent.setup();
    await renderScreen(<GlobalSearch />);

    const declencheur = screen.getByRole('button', { name: 'Recherche globale' });
    await user.click(declencheur);

    const dialogue = screen.getByRole('dialog', { name: 'Recherche globale' });
    // cmdk pose deja le focus sur le champ (autoFocus) : on le verifie,
    // le correctif ne doit pas le lui reprendre.
    expect(dialogue.contains(document.activeElement)).toBe(true);

    // ⚠️ Seuil a 1 et non 2, a dessein : le dialogue de recherche n'a QU'UN
    // element focalisable (le champ de cmdk ; les resultats sont des `<div
    // role="option">`, non focalisables, et il n'y a pas de bouton Fermer).
    // Le protocole de l'agent 28 reste tenu — il a compte 6 sorties sur 6
    // coups depuis ce meme champ ; c'est ce 6/6 qui doit devenir 0/6.
    const releve = await releverSorties(dialogue, 6, user, 1);
    expect(releve.focalisables).toBe(1);
    expect(releve.sorties).toBe(0);

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    // Le declencheur est DEMONTE pendant l'ouverture : il est re-cree a la
    // fermeture. On assure donc « le focus est sur le declencheur affiche »,
    // pas « c'est le meme noeud DOM ».
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Recherche globale' }));
  });
});
