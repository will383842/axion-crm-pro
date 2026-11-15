/** F-SEQ — libellés en clair des séquences. */
import type { StatusTone } from '@/components/ui';
import type { StatutSequence } from './api';

export const STATUT_LIBELLE: Record<StatutSequence, string> = { active: 'Active', en_pause: 'En pause', terminee: 'Terminée' };
export const STATUT_TON: Record<StatutSequence, StatusTone> = { active: 'success', en_pause: 'warning', terminee: 'neutral' };

export const nombre = (n: number): string => n.toLocaleString('fr-FR');

export const etapes = (n: number): string => `${n} ${n > 1 ? 'étapes' : 'étape'}`;

const jour = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
export const dateHeure = (iso: string | null): string => (iso === null ? '—' : jour.format(new Date(iso)));
