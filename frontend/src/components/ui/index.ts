/**
 * Design System 2026 — Axion CRM Pro
 * Tous les composants atomiques sont centralisés ici.
 * Import depuis '@/components/ui' (ex : `import { Button, KpiCard } from '@/components/ui';`)
 */

export { cn } from './cn';

// Foundation primitives
export { Button, type ButtonProps, type ButtonVariant, type ButtonSize } from './Button';
export { IconButton, type IconButtonProps } from './IconButton';
export { Card, CardHeader, CardTitle, CardEyebrow, CardFooter, type CardProps } from './Card';
export { KpiCard, type KpiCardProps, type KpiTone } from './KpiCard';
export { SegmentedControl, type SegOption } from './SegmentedControl';
export { StatusPill, mapStatusToTone, type StatusPillProps, type StatusTone } from './StatusPill';
export { Tabs, type TabItem } from './Tabs';
export { Spinner } from './Spinner';
export { Tooltip } from './Tooltip';
export { Modal, Drawer, type ModalProps } from './Modal';
// D30-002 — le conteneur à défilement des tableaux « grille ». Sans lui, dix
// écrans amputaient de 52 % à 68 % de chaque ligne sur téléphone.
export {
  TableScroll,
  largeurMinimaleGrille,
  type TableScrollProps,
  type OptionsLargeurGrille,
} from './TableScroll';
// D28-003 — le piège de focus qui tient la promesse de `aria-modal="true"`.
export { usePiegeFocus, elementsFocalisables, type OptionsPiegeFocus } from './useFocusTrap';
export { DropdownMenu, type MenuItem } from './DropdownMenu';
export { Breadcrumbs, type Crumb } from './Breadcrumbs';
export { PageHeader, LiveBadge, type PageHeaderProps } from './PageHeader';
export { Toolbar, SearchInput } from './Toolbar';
export { Avatar } from './Avatar';
export { Stat } from './Stat';
export { Input, type InputProps } from './Input';

// Legacy / existing (backwards compat)
export { PageShell, type PageShellProps } from './PageShell';
export { QualityBadge } from './QualityBadge';
export { SizeCategoryBadge } from './SizeCategoryBadge';
export { EmptyState } from './EmptyState';
// D25-001 — l'état d'échec, DISTINCT de l'état vide. `EmptyState` dit « il n'y
// a rien » ; `QueryErrorState` dit « on n'a pas pu savoir, et voici pourquoi ».
// Les confondre, c'était le défaut mesuré sur 31 écrans sur 35.
export { QueryErrorState, type QueryErrorStateProps } from './QueryErrorState';
// D25-004 — le troisième cas, que `QueryErrorState` ne peut PAS décrire faute
// d'objet `error` : le serveur répond 200 avec un corps vide. React Query tient
// la requête pour RÉUSSIE et `data` reste absente — sans cette branche, l'écran
// reste bloqué sur son sablier pour toujours.
export { ReponseVideState, type ReponseVideStateProps } from './ReponseVideState';
export { ErrorBoundary } from './ErrorBoundary';
export { Skeleton, CompaniesTableSkeleton } from './Skeleton';
export { FormField } from './FormField';
export { DarkModeToggle } from './DarkModeToggle';
export { GlobalSearch } from './GlobalSearch';
