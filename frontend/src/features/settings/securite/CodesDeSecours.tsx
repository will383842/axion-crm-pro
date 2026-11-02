import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Card, CardEyebrow, CardHeader, CardTitle, Button } from '@/components/ui';
import { api } from '@/lib/api';
import { DemandeCode, messageCode } from './DemandeCode';

export function CodesDeSecours() {
  const qc = useQueryClient();
  const [ouvert, setOuvert] = useState(false);
  const [codes, setCodes] = useState<string[] | null>(null);
  const etat = useQuery({
    queryKey: ['compte', 'codes-secours'],
    queryFn: async () => (await api.get<{ restants: number }>('/compte/codes-secours')).data,
  });

  return (
    <Card>
      <CardHeader>
        <div>
          <CardEyebrow>Sécurité</CardEyebrow>
          <CardTitle className="mt-1 text-lg font-bold">Codes de secours</CardTitle>
        </div>
      </CardHeader>
      <p className="mb-4 text-sm text-slate-600">
        Ils permettent d’entrer si vous n’avez plus accès à votre boîte e-mail. Chacun ne sert qu’une fois.
        {etat.data ? ` Il vous en reste ${etat.data.restants}.` : ''}
      </p>
      {codes !== null ? (
        <ul className="mb-4 grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3">
          {codes.map((c) => (
            <li key={c} className="font-mono text-sm text-slate-800">{c}</li>
          ))}
        </ul>
      ) : null}
      <Button variant="secondary" onClick={() => setOuvert(true)}>
        Générer de nouveaux codes
      </Button>
      {ouvert ? (
        <DemandeCode
          titre="Générer de nouveaux codes ?"
          description="Les anciens codes cesseront de fonctionner. Saisissez le code reçu par e-mail pour confirmer. Les nouveaux codes ne s’affichent qu’une fois."
          libelleAction="Générer les codes"
          onClose={() => setOuvert(false)}
          onConfirmer={async (code) => {
            try {
              const { data } = await api.post<{ recovery_codes: string[] }>('/compte/codes-secours/regenerer', { code });
              setCodes(data.recovery_codes);
              setOuvert(false);
              void qc.invalidateQueries({ queryKey: ['compte', 'codes-secours'] });
            } catch (e) {
              throw new Error(messageCode(e));
            }
          }}
        />
      ) : null}
    </Card>
  );
}
