import { useParams } from '@tanstack/react-router';
import { PageShell } from '@/components/ui/PageShell';

export function CompanyDetailPage() {
  const { companyId } = useParams({ strict: false }) as { companyId?: string };
  return (
    <PageShell title="Fiche entreprise" subtitle={`ID interne : ${companyId ?? '—'}`}>
      <p className="text-sm text-slate-600">Détail entreprise (Sprint 10).</p>
    </PageShell>
  );
}
