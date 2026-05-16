<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        return $this->ok(['companies' => [], 'contacts' => [], 'tags' => []]);
    }
}
