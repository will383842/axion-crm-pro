/**
 * Bloc « Événements et démarche » de la fiche entreprise : les événements
 * qu'organise cette fiche et l'état de la démarche de Will sur chacun.
 * Invisible pour une fiche qui n'organise rien — la quasi-totalité.
 */
import { useQuery } from "@tanstack/react-query";
import { Link } from "@tanstack/react-router";

import { Card, CardEyebrow, CardHeader, CardTitle } from "@/components/ui";
import { api } from "@/lib/api";
import type { EvenementResume } from "@/features/evenements/EvenementsPage";
import { Pastille } from "@/features/evenements/Pastille";
import {
  INTERVENTIONS,
  PARTICIPATIONS,
  libelle,
  quand,
  tonIntervention,
} from "@/features/evenements/libelles";

export function EvenementsCard({ companyId }: { companyId: number }) {
  const { data } = useQuery({
    queryKey: ["evenements-entreprise", companyId],
    queryFn: async () =>
      (await api.get<{ data: EvenementResume[] }>(`/companies/${companyId}/evenements`)).data.data,
  });

  if (!data || data.length === 0) return null;

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Organisateur</CardEyebrow>
          <CardTitle>Événements et démarche</CardTitle>
        </div>
      </CardHeader>
      <ul className="mt-2 divide-y divide-slate-100">
        {data.map((e) => (
          <li key={e.id} className="py-2 text-sm">
            <Link
              to="/evenements/$eventId"
              params={{ eventId: String(e.id) }}
              className="font-medium text-sky-700 hover:underline"
            >
              {e.nom}
            </Link>
            <div className="text-xs text-slate-500">{quand(e)}</div>
            <div className="mt-1 flex flex-wrap gap-1">
              <Pastille ton="gris">{libelle(PARTICIPATIONS, e.participation)}</Pastille>
              <Pastille ton={tonIntervention(e.intervention)}>
                {libelle(INTERVENTIONS, e.intervention)}
              </Pastille>
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
