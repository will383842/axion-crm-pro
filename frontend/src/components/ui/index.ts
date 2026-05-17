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
export { ErrorBoundary } from './ErrorBoundary';
export { Skeleton, CompaniesTableSkeleton } from './Skeleton';
export { FormField } from './FormField';
export { DarkModeToggle } from './DarkModeToggle';
export { GlobalSearch } from './GlobalSearch';
