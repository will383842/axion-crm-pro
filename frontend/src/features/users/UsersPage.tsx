import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldCheck, ShieldOff, UserPlus, Users as UsersIcon } from 'lucide-react';
import {
  Avatar,
  Button,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  Input,
  Modal,
  PageHeader,
  QueryErrorState,
  StatusPill,
  cn,
  TableScroll,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface UserRow {
  id: string;
  email: string;
  name: string;
  current_workspace_id?: string | null;
  totp_enabled_at?: string | null;
  first_login_completed_at?: string | null;
  last_login_at?: string | null;
  roles?: string[];
}

const ROLE_OPTIONS = [
  { value: 'viewer', label: 'Lecteur (lecture seule)' },
  { value: 'operator', label: 'Opérateur (édition)' },
  { value: 'admin', label: 'Admin (gestion équipe)' },
  { value: 'owner', label: 'Propriétaire (owner)' },
];

const GRID = 'minmax(220px,1.4fr) minmax(220px,1.6fr) minmax(160px,1fr) 140px 200px';

function roleToneFor(role: string) {
  if (role === 'owner') return 'danger';
  if (role === 'admin') return 'warning';
  if (role === 'operator') return 'info';
  return 'neutral';
}

function roleLabelFor(role: string): string {
  const map: Record<string, string> = {
    owner: 'Propriétaire',
    admin: 'Admin',
    operator: 'Opérateur',
    viewer: 'Lecteur',
  };
  return map[role.toLowerCase()] ?? role;
}

export function UsersPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteName, setInviteName] = useState('');
  const [inviteRole, setInviteRole] = useState('operator');

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<{ data: UserRow[] }>('/users')).data,
  });

  const inviteMut = useMutation({
    // H46-008 — le generique ferme le `any` que rendait `api.post` sans
    // parametre : `.data` traversait le `mutationFn` et echappait a `tsc`. La
    // suppression `no-unsafe-return` qui gelait ce defaut a ete retiree de
    // `frontend/eslint-suppressions.json` dans le meme geste.
    //
    // 🔴 X39-027 — LE BOUTON TAPAIT A COTE. Cet appel visait
    // `POST /users/invite`, une route qui N'EXISTE PAS : le commentaire qui
    // etait ici le disait lui-meme (« `grep -rn invite backend/routes` ne rend
    // RIEN »), et concluait que le point d'entree manquant etait « un defaut
    // DISTINCT, hors de ce constat ». Il l'etait — et il est referme ICI.
    //
    // Le serveur porte bien la creation, et depuis le 2026-08-23 :
    // `POST /users` (`UsersController::store`), sous `permission:users.manage`.
    // Elle rend `201 { data: <compte> }`, d'ou le type ci-dessous, qui n'est
    // plus `unknown` : la forme est desormais MESUREE, pas supposee.
    //
    // ⚠️ `name` est `required` cote serveur. L'envoyer manquait a l'ancien
    // corps `{ email, role }` : meme en corrigeant la seule URL, l'appel
    // repondrait 422. D'ou le champ ajoute au formulaire.
    mutationFn: async () =>
      (
        await api.post<{ data: UserRow; invitation_envoyee: boolean }>('/users', {
          email: inviteEmail,
          name: inviteName,
          role: inviteRole,
        })
      ).data,
    onSuccess: (reponse) => {
      // 🔴 D25-001, meme ecran — « l'ecran d'administration ou le mensonge
      // coutait le plus cher ».
      //
      // Ce toast a dit successivement DEUX choses fausses, et pour la meme
      // raison : il affirmait a la place du serveur. D'abord « Invitation
      // envoyee », alors que rien ne partait. Puis, apres correction, « aucun
      // e-mail n'est envoye » — vrai ce jour-la, faux des que le SMTP a ete
      // branche.
      //
      // L'ecran NE PEUT PAS SAVOIR : `MOCK_MODE` et `MAIL_MAILER` sont des
      // reglages serveur, invisibles d'ici. La seule sortie honnete est de
      // RELAYER ce que le serveur rapporte, jamais de le deviner. D'ou
      // `invitation_envoyee`, calcule par `UsersController::remettreInvitation()`
      // d'apres ce qui s'est REELLEMENT passe — envoi reussi, mode maquette, ou
      // transport en echec.
      if (reponse.invitation_envoyee) {
        toast.success('Compte créé — l’invitation vient de partir par e-mail');
      } else {
        toast.success(
          'Compte créé — aucun e-mail n’est parti : prévenez la personne, elle se connectera via « mot de passe oublié »',
        );
      }
      setOpen(false);
      setInviteEmail('');
      setInviteName('');
      setInviteRole('operator');
      qc.invalidateQueries({ queryKey: ['users'] });
    },
    onError: () => toast.error('Impossible de créer le compte'),
  });

  const rows = data?.data ?? [];

  /**
   * 🔴 D25-001 — l'écran d'administration où le mensonge coûtait le plus cher.
   *
   * `/users` est une route ADMIN : un opérateur sans le rôle reçoit 403, et
   * c'est le cas NOMINAL, pas l'exception. Il lisait alors « Aucun utilisateur
   * — Invite ton premier collaborateur », bouton « Inviter » compris : l'écran
   * lui affirmait que l'équipe était vide ET l'invitait à recruter, sur un
   * espace de travail peuplé qu'il n'avait pas le droit de voir. Le POST
   * d'invitation qui aurait suivi serait reparti en 403, sans plus
   * d'explication.
   *
   * ⚠️ `data === undefined` : React Query v5 conserve la dernière liste réussie
   * si un rafraîchissement échoue ; on ne l'efface pas.
   */
  const echec = error !== null && data === undefined;

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Utilisateurs"
        subtitle="4 rôles RBAC : owner / admin / operator / viewer (Spatie Permission teams)."
        // 🔴 X39-028 — le bouton disparait quand le serveur a REFUSE la vue.
        //
        // Mesure du 2026-08-23 : un compte `viewer` lisait « Vous n'avez pas
        // les droits sur cette vue » et se voyait offrir, juste au-dessus,
        // « Inviter un utilisateur ». Un compte qui ne peut meme pas consulter
        // la liste des membres etait invite a en recruter — et le POST qui
        // aurait suivi serait reparti en 403.
        //
        // On se branche sur `echec`, deja calcule ligne 113 pour le corps de la
        // page : le bandeau et l'action disent enfin la meme chose.
        //
        // ⚠️ Ce n'est PAS une garde de droits. L'interface ne consulte toujours
        // aucune permission (constat D22-006, ouvert) : elle se contente de ne
        // plus proposer une action apres que le serveur a refuse la lecture.
        // La vraie garde est cote serveur, et c'est elle qui protege.
        actions={
          echec ? null : (
            <Button
              variant="primary"
              iconLeft={<UserPlus className="h-3.5 w-3.5" />}
              onClick={() => setOpen(true)}
            >
              Inviter un utilisateur
            </Button>
          )
        }
      />

      {echec ? (
        <QueryErrorState
          error={error}
          contexte="la liste des membres de l’espace de travail"
          onRetry={() => void refetch()}
        />
      ) : isLoading ? (
        <CompaniesTableSkeleton rows={5} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<UsersIcon className="h-10 w-10" />}
          title="Aucun utilisateur"
          description="Invite ton premier collaborateur."
          action={
            <Button variant="primary" iconLeft={<UserPlus className="h-3.5 w-3.5" />} onClick={() => setOpen(true)}>
              Inviter
            </Button>
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          {/* D30-002 — conteneur a defilement horizontal. Sans lui, les 1020 px
              de largeur minimale de ce tableau etaient coupes net par le
              `overflow-hidden` de la Card, sans aucun moyen de les atteindre. */}
          <TableScroll template={GRID}>
          <div
            role="row"
            className={cn(
              'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
              'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            <div>Utilisateur</div>
            <div>Email</div>
            <div>Rôles</div>
            <div>2FA</div>
            <div>Dernière connexion</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((u) => (
              <div
                key={u.id}
                role="row"
                className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                style={{ gridTemplateColumns: GRID }}
              >
                <div className="flex min-w-0 items-center gap-3">
                  <Avatar name={u.name} size="sm" />
                  <div className="min-w-0 truncate font-medium text-slate-900 dark:text-white">
                    {u.name}
                  </div>
                </div>
                <div className="truncate text-slate-600 dark:text-slate-300">{u.email}</div>
                <div className="flex flex-wrap gap-1">
                  {(u.roles ?? []).length === 0 ? (
                    <span className="text-xs text-slate-400">—</span>
                  ) : (
                    (u.roles ?? []).map((r) => (
                      <StatusPill key={r} tone={roleToneFor(r)}>
                        {roleLabelFor(r)}
                      </StatusPill>
                    ))
                  )}
                </div>
                <div>
                  {u.totp_enabled_at ? (
                    <StatusPill tone="success">
                      <ShieldCheck className="-ml-0.5 mr-0.5 h-3 w-3" /> Activé
                    </StatusPill>
                  ) : (
                    <StatusPill tone="warning">
                      <ShieldOff className="-ml-0.5 mr-0.5 h-3 w-3" /> Non activé
                    </StatusPill>
                  )}
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  {u.last_login_at
                    ? new Date(u.last_login_at).toLocaleString('fr-FR')
                    : 'Jamais'}
                </div>
              </div>
            ))}
          </div>
          </TableScroll>
        </Card>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Inviter un utilisateur"
        // 🔴 X39-027 / D25-001 — cette phrase a annonce, tour a tour, un envoi
        // qui n'avait pas lieu (« Un email d'invitation sera envoye ») puis une
        // absence d'envoi qui a cesse d'etre vraie (« Aucun e-mail n'est
        // envoye »). Les deux affirmaient AVANT d'agir, sur un reglage que
        // l'ecran ne voit pas.
        //
        // Elle ne dit donc plus que ce qui est vrai dans TOUS les cas : le
        // compte naît sans mot de passe. Ce qui est arrive au courriel est
        // annonce APRES coup, par le message de succes, d'apres le drapeau
        // `invitation_envoyee` que rend le serveur.
        description="Le compte est créé immédiatement, sans mot de passe. La personne reçoit un lien pour en choisir un — et vous saurez, juste après, s’il est réellement parti."
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Annuler
            </Button>
            <Button
              variant="primary"
              onClick={() => inviteMut.mutate()}
              loading={inviteMut.isPending}
              // `name` est `required` cote serveur au meme titre que `email` :
              // les deux gardent le bouton, sinon l'appel part pour un 422.
              disabled={!inviteEmail || !inviteName}
            >
              Créer le compte
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Email</span>
            <Input
              type="email"
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
              placeholder="prenom.nom@exemple.com"
              required
            />
          </label>
          {/* 🔴 X39-027 — champ ABSENT du formulaire alors que le serveur
              l'exige (`'name' => ['required', ...]`). Sans lui, l'appel part
              pour un 422 que l'ecran ne sait pas expliquer. */}
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Nom</span>
            <Input
              type="text"
              value={inviteName}
              onChange={(e) => setInviteName(e.target.value)}
              placeholder="Prénom Nom"
              required
            />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Rôle</span>
            <select
              value={inviteRole}
              onChange={(e) => setInviteRole(e.target.value)}
              className="h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            >
              {ROLE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </Modal>
    </div>
  );
}
