import type { ReactNode } from "react";

import type { Ton } from "./libelles";

const TONS: Record<Ton, string> = {
  gris: "bg-slate-100 text-slate-700",
  vert: "bg-emerald-100 text-emerald-800",
  ambre: "bg-amber-100 text-amber-800",
  rouge: "bg-rose-100 text-rose-800",
  bleu: "bg-sky-100 text-sky-800",
};

export function Pastille({ children, ton }: { children: ReactNode; ton: Ton }) {
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TONS[ton]}`}>{children}</span>
  );
}
