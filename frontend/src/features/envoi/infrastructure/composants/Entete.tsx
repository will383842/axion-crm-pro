/** DM2-INF — en-tête commun des pages d'infrastructure (sur-titre « Envoi », un seul bouton plein). */
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui';

export function Entete({ title, phrase, actions }: { title: ReactNode; phrase?: ReactNode; actions?: ReactNode }) {
  return <PageHeader title={title} surTitre="Envoi" subtitle={phrase} actions={actions} gradient={false} />;
}
