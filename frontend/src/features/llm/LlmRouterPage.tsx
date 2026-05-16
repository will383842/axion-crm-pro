import { PageShell } from '@/components/ui/PageShell';
export function LlmRouterPage() {
  return (
    <PageShell title="LLM Router" subtitle="9 use cases × providers + fallback chain (Sprint 4).">
      <div className="flex gap-2 border-b border-slate-200">
        {['Use cases', 'Providers', 'Prompts', 'Usage'].map((t) => (
          <button key={t} className="border-b-2 border-transparent px-3 py-2 text-sm text-slate-700 hover:border-brand-600">
            {t}
          </button>
        ))}
      </div>
    </PageShell>
  );
}
