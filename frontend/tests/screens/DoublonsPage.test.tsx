/**
 * GARDE — l'onglet « Doublons à vérifier » (chantier 5, 2026-09-30).
 *
 *  1. la paire montre la fiche GARDÉE et la fiche ABSORBÉE, avec leur motif ;
 *  2. « Fusionner » demande confirmation, puis appelle la bonne paire — et
 *     rien ne part si l'on refuse la confirmation ;
 *  3. « Ce ne sont pas des doublons » écarte la BONNE paire ;
 *  4. un refus du serveur (409) affiche son motif, en clair.
 *
 * Fixtures FICTIVES (dépôt public).
 */
import { afterEach, describe, it, expect, vi } from "vitest";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { HttpHandler } from "msw";

import { DoublonsPage } from "@/features/doublons/DoublonsPage";
import { renderScreen } from "../helpers/renderScreen";
import { recordGet, recordPost } from "../msw/handlers";

// Le Toaster n'est pas monté par `renderScreen` : on observe les appels.
const notes = vi.hoisted(() => ({ succes: vi.fn(), erreur: vi.fn() }));
vi.mock("sonner", () => ({ toast: { success: notes.succes, error: notes.erreur } }));

const FICHE = {
  denomination: null,
  siren: null,
  identifiant: null,
  code_postal: "69001",
  ville: "LYON",
  site: "https://zz-club.example.invalid",
  source: "insee",
  nb_contacts: 0,
  protegee: false,
};

const PAIRE = {
  id: 41,
  motif: "nom_cp",
  motif_libelle:
    "Même nom et même code postal, sites différents ou absents (fiche sans SIREN et fiche avec SIREN)",
  score: 0.85,
  fusion_auto: false,
  garde: { ...FICHE, id: 7, denomination: "ZZ CLUB GARDE", siren: "940000007", nb_contacts: 2 },
  absorbee: {
    ...FICHE,
    id: 9,
    denomination: "ZZ Club absorbe",
    identifiant: "evt:zz-club",
    source: "evenements-pro",
    protegee: true,
  },
};

const LISTE = {
  data: [PAIRE],
  meta: {
    total: 1,
    page: 1,
    per_page: 50,
    par_motif: { nom_cp: 1 },
    adresses_partagees: { cabinet_comptable: 3 },
    motifs: { nom_cp: PAIRE.motif_libelle, nom_cp_site: "Même nom, même code postal et même site" },
  },
};

async function monter(handlers: HttpHandler[] = []) {
  const liste = recordGet("/doublons", LISTE);
  await renderScreen(<DoublonsPage />, {
    path: "/doublons",
    handlers: [liste.handler, ...handlers],
    landingRoutes: ["/companies/$companyId"],
  });
  await screen.findByText("ZZ CLUB GARDE");
  return liste;
}

afterEach(() => {
  vi.restoreAllMocks();
  notes.succes.mockReset();
  notes.erreur.mockReset();
});

describe("onglet Doublons à vérifier", () => {
  it("montre la fiche gardée, la fiche absorbée, le motif et les adresses partagées", async () => {
    await monter();

    const paire = within(screen.getByTestId("paire-41"));
    expect(paire.getByText(PAIRE.motif_libelle)).toBeInTheDocument();
    expect(paire.getByText("Fiche gardée")).toBeInTheDocument();
    expect(paire.getByText("Fiche absorbée (corbeille)")).toBeInTheDocument();
    expect(paire.getByText("SIREN 940000007")).toBeInTheDocument();
    expect(paire.getByText("Identifiant evt:zz-club")).toBeInTheDocument();
    expect(paire.getByText(/protégée/)).toBeInTheDocument();
    expect(screen.getByText(/3 cabinet comptable/)).toBeInTheDocument();
  });

  it("« Fusionner » demande confirmation puis fusionne CETTE paire", async () => {
    const confirmer = vi.spyOn(window, "confirm").mockReturnValue(true);
    const fusion = recordPost("/doublons/41/fusionner", { fusion_id: 5 });
    await monter([fusion.handler]);

    await userEvent.click(screen.getByRole("button", { name: "Fusionner" }));

    await waitFor(() => expect(fusion.bodies).toHaveLength(1));
    expect(confirmer).toHaveBeenCalledTimes(1);
    expect(String(confirmer.mock.calls[0]?.[0])).toContain("ZZ Club absorbe");
  });

  it("témoin : confirmation refusée, AUCUNE fusion ne part", async () => {
    const confirmer = vi.spyOn(window, "confirm").mockReturnValue(false);
    const fusion = recordPost("/doublons/41/fusionner", { fusion_id: 5 });
    await monter([fusion.handler]);

    await userEvent.click(screen.getByRole("button", { name: "Fusionner" }));

    expect(confirmer).toHaveBeenCalledTimes(1);
    // La confirmation est synchrone : si un envoi devait partir, il serait
    // déjà enregistré après ce tour de boucle.
    await new Promise((r) => setTimeout(r, 50));
    expect(fusion.bodies).toHaveLength(0);
  });

  it("« Ce ne sont pas des doublons » écarte CETTE paire", async () => {
    const ignorer = recordPost("/doublons/41/ignorer", { ok: true });
    await monter([ignorer.handler]);

    await userEvent.click(screen.getByRole("button", { name: "Ce ne sont pas des doublons" }));

    await waitFor(() => expect(ignorer.bodies).toHaveLength(1));
  });

  it("un refus du serveur affiche son motif", async () => {
    vi.spyOn(window, "confirm").mockReturnValue(true);
    const refus = recordPost(
      "/doublons/41/fusionner",
      { error: "sirens_differents", message: "Les deux fiches portent deux SIREN différents." },
      409,
    );
    await monter([refus.handler]);

    await userEvent.click(screen.getByRole("button", { name: "Fusionner" }));

    await waitFor(() =>
      expect(notes.erreur).toHaveBeenCalledWith("Les deux fiches portent deux SIREN différents."),
    );
    expect(notes.succes).not.toHaveBeenCalled();
  });
});
