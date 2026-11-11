<?php

namespace App\Envoi\Imap;

use DateTimeImmutable;
use RuntimeException;

/** Lecteur de test : aucune connexion réseau. */
final class LecteurBoiteMemoire implements LecteurBoite
{
    /** @var array<string, int> */
    private array $validites = [];

    /** @var array<string, list<MessageBrut>> */
    private array $messages = [];

    /** @var array<string, true> */
    private array $enPanne = [];

    /** Simule une panne de connexion de cette boîte (exception technique, comme le lecteur réel). */
    public function mettreEnPanne(string $boite): void
    {
        $this->enPanne[$boite] = true;
    }

    public function retablir(string $boite): void
    {
        unset($this->enPanne[$boite]);
    }

    private function verifierConnexion(string $boite): void
    {
        if (isset($this->enPanne[$boite])) {
            throw new RuntimeException('Connexion impossible à imap.exemple.invalid.');
        }
    }

    public function definirUidValidity(string $boite, int $valeur): void
    {
        $this->validites[$boite] = $valeur;
    }

    public function deposer(string $boite, MessageBrut $message): void
    {
        $this->messages[$boite][] = $message;
    }

    /** Simule une renumérotation : nouvelle UIDVALIDITY, UID repartis de 1. */
    public function renumeroter(string $boite, int $nouvelleValidite): void
    {
        $this->validites[$boite] = $nouvelleValidite;
        $uid = 0;
        $refaits = [];
        foreach ($this->messages[$boite] ?? [] as $m) {
            $uid++;
            $refaits[] = new MessageBrut(
                $uid,
                $m->messageId,
                $m->inReplyTo,
                $m->references,
                $m->expediteur,
                $m->recuLe,
                $m->sujet,
                $m->corps,
                $m->enTetes,
                $m->statutDsn,
                $m->messageIdDsn,
                $m->destinataireDsn,
                $m->ecarte,
                $m->empreinteContenu,
                $m->analyseEchouee,
                $m->typeArf,
                $m->destinataireArf,
                $m->adressesLocales,
            );
        }
        $this->messages[$boite] = $refaits;
    }

    public function uidValidity(string $boite): int
    {
        $this->verifierConnexion($boite);

        return $this->validites[$boite] ?? 1;
    }

    public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = ReleveImap::LIMITE_LOT): LotBoite
    {
        $this->verifierConnexion($boite);
        // Comme le lecteur réel : on pagine d'abord sur les UID BRUTS, on filtre par la date ENSUITE.
        $bruts = array_values(array_filter(
            $this->messages[$boite] ?? [],
            static fn (MessageBrut $m): bool => $m->uid > $uidApres,
        ));
        usort($bruts, static fn (MessageBrut $a, MessageBrut $b): int => $a->uid <=> $b->uid);
        $page = array_slice($bruts, 0, $limite);
        $gardes = array_values(array_filter(
            $page,
            static fn (MessageBrut $m): bool => $depuis === null || $m->recuLe >= $depuis,
        ));

        return new LotBoite($gardes, $page === [] ? $uidApres : $page[count($page) - 1]->uid, count($bruts) > $limite);
    }
}
