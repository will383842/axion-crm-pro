import { useEffect, useState } from 'react';

type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'axion-theme';

function resolveTheme(t: Theme): 'light' | 'dark' {
  if (t === 'system') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  return t;
}

function applyTheme(t: Theme): void {
  const resolved = resolveTheme(t);
  document.documentElement.classList.toggle('dark', resolved === 'dark');
  document.documentElement.setAttribute('data-theme', resolved);
}

export function DarkModeToggle() {
  const [theme, setTheme] = useState<Theme>(() => {
    return (localStorage.getItem(STORAGE_KEY) as Theme) ?? 'system';
  });

  useEffect(() => {
    applyTheme(theme);
    localStorage.setItem(STORAGE_KEY, theme);
  }, [theme]);

  useEffect(() => {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const listener = () => theme === 'system' && applyTheme('system');
    mq.addEventListener('change', listener);
    return () => mq.removeEventListener('change', listener);
  }, [theme]);

  /*
   * D28-015 — la cible tactile des trois boutons valait le glyphe plus
   * `px-2 py-1`, soit 22,9 × 24 px mesurés le 2026-08-22 : sous le minimum de
   * 24 × 24 px du WCAG 2.2 (critère 2.5.8). Ce sélecteur vit dans l'en-tête de
   * la coquille, donc sur TOUS les écrans — le défaut était multiplié par le
   * nombre de vues (33 nœuds fautifs en clair, 32 en sombre).
   *
   * `min-h-6 min-w-6` (24 px) posent un plancher sans figer la boîte : `px-2`
   * continue d'élargir un glyphe plus large. `inline-flex` et le centrage sont
   * nécessaires — sans eux, un `min-width` sur un bouton en flux laisserait le
   * glyphe collé à gauche de sa propre cible.
   *
   * Effet de bord assumé : l'en-tête gagne au plus ~1 px de large par bouton
   * (la hauteur, elle, valait déjà 24 px) — pas de saut de mise en page.
   */
  return (
    <div className="inline-flex rounded-md border border-slate-200 bg-white p-0.5 text-xs dark:border-slate-700 dark:bg-slate-800">
      {(['light', 'system', 'dark'] as const).map((t) => (
        <button
          key={t}
          onClick={() => setTheme(t)}
          aria-pressed={theme === t}
          aria-label={`Theme ${t}`}
          className={`inline-flex min-h-6 min-w-6 items-center justify-center rounded px-2 py-1 transition ${
            theme === t ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700'
          }`}
        >
          {t === 'light' ? '☀' : t === 'dark' ? '☾' : '⚙'}
        </button>
      ))}
    </div>
  );
}
