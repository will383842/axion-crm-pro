import { useParams, Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Card, EmptyState, Spinner, cn } from "@/components/ui";
import { api } from "@/lib/api";
import { MEDIA_TYPE_OPTIONS, type MediaItem } from "./MediaListPage";

interface JournalistItem {
  id: number;
  first_name: string | null;
  last_name: string | null;
  role: string | null;
  beat: string | null;
  email: string | null;
  opt_out: boolean;
}

interface MediaDetail extends MediaItem {
  siren: string | null;
  region_code: string | null;
  postcode: string | null;
  source: string | null;
  socials: Record<string, string> | null;
  journalists?: JournalistItem[];
  children?: MediaItem[];
  parent?: MediaItem | null;
}

function typeLabel(v: string | null): string {
  return MEDIA_TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v ?? "—";
}

export function MediaDetailPage() {
  const { mediaId } = useParams({ strict: false });

  const { data, isLoading, isError } = useQuery<MediaDetail>({
    queryKey: ["media", mediaId],
    queryFn: async () => {
      const r = await api.get<MediaDetail>(`/media/${mediaId}`);
      return r.data;
    },
    enabled: !!mediaId,
  });

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (isError || !data) {
    return (
      <div className="px-6 py-6">
        <EmptyState icon="📰" title="Média introuvable" description="Ce média n'existe pas ou a été supprimé." />
      </div>
    );
  }

  const familyLabel =
    data.media_family === "audiovisual_production"
      ? "Production audiovisuelle"
      : data.media_family === "editorial"
        ? "Rédactionnel"
        : null;

  const rows: Array<[string, string | null | undefined]> = [
    ["Type", typeLabel(data.media_type)],
    ["Famille", familyLabel],
    ["Périodicité", data.periodicity],
    ["Thème éditorial", data.editorial_theme],
    ["Zone de diffusion", data.diffusion_zone],
    ["Éditeur", data.publisher],
    ["Département", data.department_code],
    ["Région", data.region_code],
    ["Ville", data.city],
    ["Code postal", data.postcode],
    ["SIREN", data.siren],
    ["N° CPPAP", data.cppap_number],
    ["N° ARCOM", data.arcom_id],
    ["Source", data.source],
  ];

  return (
    <div className="px-6 py-6">
      <div className="mb-4 text-sm text-slate-500">
        <Link to="/media" className="hover:text-slate-900 dark:hover:text-white">
          Médias
        </Link>{" "}
        / <span className="text-slate-700 dark:text-slate-200">{data.name}</span>
      </div>

      <h1 className="mb-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{data.name}</h1>
      <div className="mb-6 flex flex-wrap items-center gap-2 text-sm text-slate-500">
        <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
          {typeLabel(data.media_type)}
        </span>
        {data.parent ? (
          <span className="text-xs">
            Chaîne :{" "}
            <Link
              to="/media/$mediaId"
              params={{ mediaId: String(data.parent.id) }}
              className="font-medium text-brand-600 hover:underline dark:text-brand-400"
            >
              {data.parent.name}
            </Link>
          </span>
        ) : null}
        {data.enrich_status ? <span className="text-xs">enrichissement : {data.enrich_status}</span> : null}
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Fiche */}
        <Card className="lg:col-span-2">
          <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Informations</h2>
          <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
            {rows.map(([k, v]) => (
              <div key={k} className="flex justify-between border-b border-slate-100 py-1.5 dark:border-slate-800">
                <dt className="text-xs text-slate-500">{k}</dt>
                <dd className="text-sm font-medium text-slate-800 dark:text-slate-200">{v || "—"}</dd>
              </div>
            ))}
          </dl>
        </Card>

        {/* Contacts */}
        <Card>
          <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Contact rédaction</h2>
          <ul className="space-y-2 text-sm">
            <li>
              <span className="text-xs text-slate-500">Site web</span>
              <br />
              {data.website ? (
                <a href={data.website} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline dark:text-brand-400">
                  {data.website}
                </a>
              ) : (
                <span className="text-slate-400">—</span>
              )}
            </li>
            <li>
              <span className="text-xs text-slate-500">Email rédaction</span>
              <br />
              <span className="text-slate-800 dark:text-slate-200">{data.email || "—"}</span>
              {data.email_confidence ? (
                <span
                  className={cn(
                    "ml-2 rounded px-1.5 py-0.5 text-[11px] font-semibold",
                    data.email_confidence === "A"
                      ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                      : data.email_confidence === "B"
                        ? "bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300"
                        : "bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300",
                  )}
                  title="Confiance : A = email sur le domaine du site, B = domaine pro, C = boîte grand public"
                >
                  Confiance {data.email_confidence}
                </span>
              ) : null}
            </li>
            <li>
              <span className="text-xs text-slate-500">Téléphone</span>
              <br />
              <span className="text-slate-800 dark:text-slate-200">{data.phone || "—"}</span>
            </li>
          </ul>
        </Card>
      </div>

      {/* Journalistes */}
      <Card className="mt-6">
        <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">
          Journalistes {data.journalists?.length ? `(${data.journalists.length})` : ""}
        </h2>
        {data.journalists && data.journalists.length > 0 ? (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-[11px] uppercase text-slate-500 dark:border-slate-800">
                <th className="py-2 text-left">Nom</th>
                <th className="py-2 text-left">Rôle</th>
                <th className="py-2 text-left">Rubrique</th>
                <th className="py-2 text-left">Email</th>
              </tr>
            </thead>
            <tbody>
              {data.journalists.map((j) => (
                <tr key={j.id} className={cn("border-b border-slate-100 dark:border-slate-800", j.opt_out && "opacity-50")}>
                  <td className="py-2">{[j.first_name, j.last_name].filter(Boolean).join(" ") || "—"}</td>
                  <td className="py-2 text-slate-500">{j.role || "—"}</td>
                  <td className="py-2 text-slate-500">{j.beat || "—"}</td>
                  <td className="py-2 text-slate-500">{j.opt_out ? "opt-out" : j.email || "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <p className="text-sm text-slate-500">
            Aucun journaliste rattaché. Le contact presse fiable reste l'email rédaction ci-dessus (+ le
            directeur de publication en mentions légales).
          </p>
        )}
      </Card>

      {/* Émissions / médias enfants */}
      {data.children && data.children.length > 0 ? (
        <Card className="mt-6">
          <h2 className="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Émissions / médias liés ({data.children.length})</h2>
          <ul className="flex flex-wrap gap-2">
            {data.children.map((c) => (
              <li key={c.id}>
                <Link
                  to="/media/$mediaId"
                  params={{ mediaId: String(c.id) }}
                  className="rounded-lg bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200"
                >
                  {c.name}
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}
