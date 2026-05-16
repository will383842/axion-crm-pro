import { PageShell } from '@/components/ui/PageShell';
import { useTranslation } from 'react-i18next';

export function CampaignsStub() {
  const { t } = useTranslation();
  return (
    <PageShell title="Campagnes" subtitle={t('phase2.stub.title')}>
      <div className="rounded-xl border-2 border-dashed border-amber-200 bg-amber-50 p-6 text-amber-900">
        {t('phase2.stub.description')}
      </div>
    </PageShell>
  );
}
