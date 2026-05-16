<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompaniesController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => [], 'meta' => ['total' => 0]]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('5'); }
    public function show(Company $company): JsonResponse { return $this->ok($company); }
    public function update(Request $r, Company $company): JsonResponse { return $this->notImplemented('5'); }
    public function destroy(Company $company): JsonResponse { return $this->notImplemented('5'); }
    public function enrich(Company $company): JsonResponse { return $this->notImplemented('5'); }
    public function bulkEnrich(Request $r): JsonResponse { return $this->notImplemented('5'); }
    public function recomputeScore(Company $company): JsonResponse { return $this->notImplemented('10'); }
}
