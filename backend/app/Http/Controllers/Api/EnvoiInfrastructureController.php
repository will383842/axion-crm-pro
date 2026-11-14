<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Infrastructure\LectureInfrastructure;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

/** R03 : infrastructure d'envoi en LECTURE seule (domaines, listes noires, serveurs, configuration). Un seul espace : le courant. */
class EnvoiInfrastructureController extends ApiController
{
    public function __construct(private readonly LectureInfrastructure $lecture) {}

    public function domaines(): JsonResponse
    {
        return response()->json($this->lecture->domaines($this->ws()));
    }

    public function domaine(string $id): JsonResponse
    {
        return $this->ou404($this->lecture->domaine($this->ws(), $id));
    }

    public function listesNoires(): JsonResponse
    {
        return response()->json(['data' => $this->lecture->listesNoires($this->ws())]);
    }

    public function serveurs(): JsonResponse
    {
        return response()->json($this->lecture->serveursResumes($this->ws()));
    }

    public function santeServeur(string $id): JsonResponse
    {
        return $this->ou404($this->lecture->santeServeur($this->ws(), $id));
    }

    public function configuration(): JsonResponse
    {
        return response()->json($this->lecture->configuration($this->ws()));
    }

    private function ws(): string
    {
        return (string) WorkspaceContext::current();
    }

    /** @param ?array<string, mixed> $donnees */
    private function ou404(?array $donnees): JsonResponse
    {
        return $donnees === null ? response()->json(['error' => 'introuvable', 'message' => 'Ressource introuvable.'], 404) : response()->json($donnees);
    }
}
