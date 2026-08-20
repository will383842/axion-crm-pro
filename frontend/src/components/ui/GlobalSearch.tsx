import { useEffect, useRef, useState, useCallback } from 'react';
import { Command } from 'cmdk';
import { useNavigate } from '@tanstack/react-router';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAntiRebond } from '@/hooks/useAntiRebond';
import { usePiegeFocus } from './useFocusTrap';

interface SearchResults {
  companies: { id: number; siren: string; denomination?: string|null }[];
  contacts: { id: number; first_name?: string|null; last_name: string; email?: string|null; company_id: number }[];
  tags: { id: number; slug: string; name: string }[];
}

export function GlobalSearch() {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const navigate = useNavigate();

  // Keyboard shortcut Cmd+K / Ctrl+K
  useEffect(() => {
    const down = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen((o) => !o);
      }
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', down);
    return () => document.removeEventListener('keydown', down);
  }, []);

  // G42-010 — la palette Cmd+K interrogeait `/search` a CHAQUE touche des la
  // deuxieme lettre. C'est le pire des huit sites : la palette est concue pour
  // qu'on y tape vite, et `/search` interroge TROIS tables (entreprises,
  // contacts, tags) en une requete.
  //
  // Mesure du 2026-08-20 sur `/companies`, meme cablage (voir
  // `tests/perf/recherche-anti-rebond.test.tsx`) : 11 caracteres tapes
  // lancaient 11 requetes. Apres : 1.
  //
  // ⚠️ Le champ garde `search` : les lettres s'affichent sans attendre, et le
  // message « Aucun resultat pour « … » » cite bien ce que l'operateur a tape.
  const rechercheDifferee = useAntiRebond(search);

  const { data } = useQuery({
    queryKey: ['global-search', rechercheDifferee],
    queryFn: async () => {
      if (rechercheDifferee.length < 2) return { companies: [], contacts: [], tags: [] };
      return (await api.get<SearchResults>(`/search?q=${encodeURIComponent(rechercheDifferee)}`)).data;
    },
    enabled: open && rechercheDifferee.length >= 2,
    staleTime: 30_000,
  });

  const close = useCallback(() => {
    setOpen(false);
    setSearch('');
  }, []);

  /**
   * D28-003 — le déclencheur DISPARAÎT pendant l'ouverture (ce composant rend
   * soit le bouton, soit le dialogue, jamais les deux). Le nœud DOM mémorisé
   * par le piège n'existe donc plus à la fermeture, et `focus()` sur un nœud
   * détaché ne fait rien : c'est ainsi que l'agent 28 mesurait un retour du
   * focus sur `BUTTON "Importer"`, un bouton au hasard de la page.
   *
   * On ne restructure PAS le rendu pour garder le bouton monté (cela le
   * laisserait visible sous le voile, changement visuel non demandé) : on
   * signale la disparition, et le bouton reconstruit se reprend le focus.
   */
  const refDeclencheur = useRef<HTMLButtonElement | null>(null);
  const [aRendreLeFocus, setARendreLeFocus] = useState(false);
  const refDialogue = usePiegeFocus<HTMLDivElement>(open, {
    surDeclencheurDisparu: () => setARendreLeFocus(true),
  });

  useEffect(() => {
    if (!aRendreLeFocus) return;
    refDeclencheur.current?.focus();
    setARendreLeFocus(false);
  }, [aRendreLeFocus]);

  if (!open) {
    return (
      <button
        ref={refDeclencheur}
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-500 hover:border-slate-400 dark:border-slate-600 dark:bg-slate-800"
        aria-label="Recherche globale"
      >
        <span>🔍</span>
        <span>Rechercher</span>
        <kbd className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono text-slate-600 dark:bg-slate-700">⌘K</kbd>
      </button>
    );
  }

  return (
    <div
      ref={refDialogue}
      className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 p-4 pt-24"
      onClick={close}
      role="dialog"
      aria-modal="true"
      aria-label="Recherche globale"
    >
      <div className="w-full max-w-xl rounded-xl bg-white shadow-2xl dark:bg-slate-800" onClick={(e) => e.stopPropagation()}>
        <Command className="rounded-xl" shouldFilter={false}>
          <div className="flex items-center border-b border-slate-200 px-4 dark:border-slate-700">
            <span className="mr-2 text-slate-400">🔍</span>
            <Command.Input
              autoFocus
              value={search}
              onValueChange={setSearch}
              placeholder="Rechercher entreprise, contact, tag…"
              className="flex-1 bg-transparent py-3 text-sm outline-none placeholder:text-slate-400"
            />
            <kbd className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 dark:bg-slate-700">Esc</kbd>
          </div>

          <Command.List className="max-h-96 overflow-y-auto p-2">
            {search.length < 2 && (
              <Command.Empty className="py-8 text-center text-sm text-slate-500">
                Tape au moins 2 caractères pour rechercher.
              </Command.Empty>
            )}

            {search.length >= 2 && (!data?.companies?.length && !data?.contacts?.length && !data?.tags?.length) && (
              <Command.Empty className="py-8 text-center text-sm text-slate-500">
                Aucun résultat pour « {search} ».
              </Command.Empty>
            )}

            {data?.companies && data.companies.length > 0 && (
              <Command.Group heading="Entreprises" className="mt-1 text-xs uppercase text-slate-500">
                {data.companies.map((c) => (
                  <Command.Item
                    key={c.id}
                    value={`co-${c.id}`}
                    onSelect={() => { navigate({ to: '/companies/$companyId', params: { companyId: String(c.id) } }); close(); }}
                    className="flex cursor-pointer items-center justify-between rounded-md px-3 py-2 text-sm aria-selected:bg-slate-100 dark:aria-selected:bg-slate-700"
                  >
                    <span>{c.denomination ?? c.siren}</span>
                    <span className="font-mono text-xs text-slate-400">{c.siren}</span>
                  </Command.Item>
                ))}
              </Command.Group>
            )}

            {data?.contacts && data.contacts.length > 0 && (
              <Command.Group heading="Contacts" className="mt-2 text-xs uppercase text-slate-500">
                {data.contacts.map((c) => (
                  <Command.Item
                    key={c.id}
                    value={`ct-${c.id}`}
                    onSelect={() => { navigate({ to: '/contacts' }); close(); }}
                    className="flex cursor-pointer items-center justify-between rounded-md px-3 py-2 text-sm aria-selected:bg-slate-100 dark:aria-selected:bg-slate-700"
                  >
                    <span>{[c.first_name, c.last_name].filter(Boolean).join(' ')}</span>
                    <span className="text-xs text-slate-400">{c.email ?? '—'}</span>
                  </Command.Item>
                ))}
              </Command.Group>
            )}

            {data?.tags && data.tags.length > 0 && (
              <Command.Group heading="Tags" className="mt-2 text-xs uppercase text-slate-500">
                {data.tags.map((t) => (
                  <Command.Item
                    key={t.id}
                    value={`tg-${t.id}`}
                    onSelect={() => close()}
                    className="cursor-pointer rounded-md px-3 py-2 text-sm aria-selected:bg-slate-100"
                  >
                    {t.name} <span className="ml-2 font-mono text-xs text-slate-400">#{t.slug}</span>
                  </Command.Item>
                ))}
              </Command.Group>
            )}
          </Command.List>

          <div className="flex items-center justify-between border-t border-slate-200 px-3 py-2 text-xs text-slate-400 dark:border-slate-700">
            <span>⌘K pour ouvrir / fermer</span>
            <span>↑↓ naviguer · ↵ ouvrir</span>
          </div>
        </Command>
      </div>
    </div>
  );
}
