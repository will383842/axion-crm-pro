/** Les versions d'un message type : on n'écrase jamais, on restaure ou on valide. */
import { Button } from '@/components/ui';
import { Bloc } from '../../creation/composants';
import type { MessageTypeEtat } from '../../creation/api';

export function VersionsMessage({
  message,
  occupe,
  onRestaurer,
  onValider,
}: {
  message: MessageTypeEtat;
  occupe: boolean;
  onRestaurer: (numero: number) => void;
  onValider: (numero: number) => void;
}) {
  const versions = [...message.versions].sort((a, b) => b.numero - a.numero);
  return (
    <Bloc titre="Versions" mot={`v${message.version_courante}`} etiquette="Versions">
      <ul className="divide-y divide-slate-100">
        {versions.map((v) => {
          const validee = message.version_validee === v.numero;
          const actuelle = message.version_courante === v.numero;
          return (
            <li key={v.numero} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span className="min-w-0 text-sm text-slate-900">
                <strong className="font-semibold">Version {v.numero}</strong>
                <span className="ml-2 text-xs text-slate-600">{[actuelle ? 'actuelle' : null, validee ? 'validée' : null].filter(Boolean).join(', ')}</span>
              </span>
              <span className="flex flex-wrap gap-2">
                {!actuelle ? (
                  <Button variant="ghost" size="sm" disabled={occupe} aria-label={`Restaurer la version ${v.numero}`} onClick={() => { onRestaurer(v.numero); }}>
                    Restaurer
                  </Button>
                ) : null}
                {!validee ? (
                  <Button variant="secondary" size="sm" disabled={occupe} aria-label={`Valider la version ${v.numero}`} onClick={() => { onValider(v.numero); }}>
                    Valider
                  </Button>
                ) : null}
              </span>
            </li>
          );
        })}
      </ul>
    </Bloc>
  );
}
