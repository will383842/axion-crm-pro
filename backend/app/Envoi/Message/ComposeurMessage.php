<?php

namespace App\Envoi\Message;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Compose un message RFC 5322 (texte + HTML, UTF-8, sans pièce jointe).
 * COMPOSITION SEULEMENT : aucun envoi réseau, aucune mesure d'ouverture.
 */
final class ComposeurMessage
{
    public function __construct(
        private readonly string $domaineEnvoi,
        private readonly ?DkimSigneur $dkim = null,
        private readonly ControleurContenu $controleur = new ControleurContenu,
    ) {
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $domaineEnvoi) !== 1) {
            throw new InvalidArgumentException('Domaine d\'envoi invalide.');
        }
    }

    /** @throws MessageRefuse */
    public function composer(DemandeMessage $d): string
    {
        foreach ([$d->partieLocale, $d->nomAffiche, $d->replyTo, $d->destinataire, $d->sujet, $d->jetonEnvoi,
            $d->urlDesinscription, $d->mailtoDesinscription, (string) $d->inReplyTo, ...$d->references] as $v) {
            if (preg_match('/[\r\n]/', $v) === 1) {
                throw new InvalidArgumentException('Saut de ligne interdit dans un en-tête.');
            }
        }
        if (preg_match('/^[a-z0-9._+-]+$/i', $d->partieLocale) !== 1) {
            throw new InvalidArgumentException('Partie locale de l\'expéditeur invalide.');
        }
        foreach ([$d->replyTo, $d->destinataire] as $adresse) {
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Adresse invalide.');
            }
        }
        if (! str_starts_with($d->urlDesinscription, 'https://')) {
            throw new InvalidArgumentException('Le lien de désinscription doit être en https.');
        }
        if (! str_starts_with($d->mailtoDesinscription, 'mailto:')) {
            throw new InvalidArgumentException('Le lien de désinscription mailto: est obligatoire.');
        }
        if (preg_match('/^[A-Za-z0-9_.-]{8,128}$/', $d->jetonEnvoi) !== 1) {
            throw new InvalidArgumentException('Jeton d\'envoi invalide.');
        }
        if (trim($d->entite) === '' || trim($d->adressePostale) === '' || trim($d->texteArt14) === '') {
            throw new InvalidArgumentException('Entité, adresse postale et information art. 14 sont obligatoires (fournis, jamais inventés).');
        }

        $adresseFrom = $d->partieLocale . '@' . $this->domaineEnvoi;
        $piedTexte = $d->entite . "\n" . $d->adressePostale . "\n\n" . $d->texteArt14
            . "\n\nSe désinscrire : " . $d->urlDesinscription;
        $piedHtml = '<hr><p>' . $this->e($d->entite) . '<br>' . nl2br($this->e($d->adressePostale)) . '</p><p>'
            . nl2br($this->e($d->texteArt14)) . '</p><p><a href="' . $this->e($d->urlDesinscription)
            . '">Se désinscrire</a></p>';

        // Contrôle des gabarits AVANT rendu (variables sans repli), puis du résultat.
        $gabarits = [$d->sujet, $d->texte, $d->html, $piedTexte, $piedHtml, $adresseFrom, $d->replyTo,
            $d->destinataire, $d->nomAffiche, $d->urlDesinscription, $d->mailtoDesinscription];
        $this->controleur->exiger($gabarits, $d->piecesJointes);

        $sujet = $this->rendre($d->sujet, $d->variables);
        $texte = $this->rendre($d->texte, $d->variables) . "\n\n-- \n" . $this->rendre($piedTexte, $d->variables);
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
            . $this->rendre($d->html, $d->variables, true) . $piedHtml . '</body></html>';
        $this->controleur->exiger([$sujet, $texte, $html, $adresseFrom]);

        $date = ($d->date ?? new DateTimeImmutable)->format(DateTimeInterface::RFC2822);
        $boundary = '=_' . bin2hex(random_bytes(12));
        $corps = $this->partie($boundary, 'text/plain', $texte) . $this->partie($boundary, 'text/html', $html)
            . '--' . $boundary . "--\r\n";

        $entetes = [
            'From' => $this->encoderMot($d->nomAffiche) . ' <' . $adresseFrom . '>',
            'Reply-To' => $d->replyTo,
            'To' => $d->destinataire,
            'Subject' => $this->encoderMot($sujet),
            'Date' => $date,
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $this->domaineEnvoi . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'List-Unsubscribe' => '<' . $d->urlDesinscription . '>, <' . $d->mailtoDesinscription . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Envoi-Jeton' => $d->jetonEnvoi,
        ];
        if ($d->inReplyTo !== null) {
            $entetes['In-Reply-To'] = $d->inReplyTo;
            $entetes['References'] = implode(' ', $d->references !== [] ? $d->references : [$d->inReplyTo]);
        }

        $tete = '';
        foreach ($entetes as $nom => $valeur) {
            $tete .= $nom . ': ' . $valeur . "\r\n";
        }
        if ($this->dkim !== null) {
            $tete = $this->dkim->signer($entetes, $corps) . "\r\n" . $tete;
        }

        return $tete . "\r\n" . $corps;
    }

    /**
     * `{{nom|repli}}` : la valeur fournie si non vide, sinon le repli.
     *
     * @param array<string,string> $variables
     */
    public function rendre(string $gabarit, array $variables, bool $html = false): string
    {
        return (string) preg_replace_callback('/\{\{(.*?)\}\}/s', function (array $m) use ($variables, $html): string {
            [$nom, $repli] = array_pad(explode('|', $m[1], 2), 2, '');
            $nom = trim($nom);
            $repli = trim($repli);
            if ($repli === '') {
                throw new MessageRefuse(["Variable « {{{$nom}}} » sans valeur de repli."]);
            }
            $valeur = trim($variables[$nom] ?? '');
            $valeur = $valeur === '' ? $repli : $valeur;

            return $html ? $this->e($valeur) : $valeur;
        }, $gabarit);
    }

    private function partie(string $boundary, string $type, string $contenu): string
    {
        $contenu = preg_replace('/\r\n|\r|\n/', "\r\n", $contenu) ?? $contenu;

        return '--' . $boundary . "\r\nContent-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($contenu) . "\r\n";
    }

    /** Mot encodé RFC 2047 si non ASCII (nom affiché, sujet). */
    private function encoderMot(string $v): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $v) === 1) {
            return str_contains($v, '"') || preg_match('/[(),:;<>@\[\]\\\\]/', $v) === 1
                ? '"' . addcslashes($v, '"\\') . '"' : $v;
        }
        $mots = [];
        foreach (mb_str_split($v) as $c) {
            $mots[] = $c;
        }
        $lignes = [];
        $cur = '';
        foreach ($mots as $c) {
            if (strlen($cur . $c) > 40) {
                $lignes[] = $cur;
                $cur = '';
            }
            $cur .= $c;
        }
        $lignes[] = $cur;

        return implode("\r\n ", array_map(fn (string $l) => '=?UTF-8?B?' . base64_encode($l) . '?=', $lignes));
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
