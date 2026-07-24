<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FhirMapper;
use App\Services\TerminologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F2: FHIR terminology operations — CodeSystem/$lookup and $validate-code.
 * Public by design: code systems carry no PHI, and terminology resolution must
 * never depend on a vault context. Throttled like the other public surfaces.
 */
final class TerminologyController
{
    public function __construct(
        private readonly TerminologyService $terminology,
        private readonly FhirMapper $fhir,
    ) {
    }

    public function lookup(Request $request): JsonResponse
    {
        [$system, $code, $error] = $this->params($request);
        if ($error !== null) {
            return $error;
        }

        $display = $this->terminology->display($system, $code);
        if ($display === null) {
            return response()->json($this->fhir->operationOutcome(
                'not-found',
                "Code '{$code}' not found in {$system}. If this system was never imported, run its term:import-* command.",
            ), 404);
        }

        return response()->json([
            'resourceType' => 'Parameters',
            'parameter' => [
                ['name' => 'name', 'valueString' => $system],
                ['name' => 'display', 'valueString' => $display],
            ],
        ]);
    }

    public function validateCode(Request $request): JsonResponse
    {
        [$system, $code, $error] = $this->params($request);
        if ($error !== null) {
            return $error;
        }

        $display = $this->terminology->display($system, $code);

        $parameters = [['name' => 'result', 'valueBoolean' => $display !== null]];
        if ($display !== null) {
            $parameters[] = ['name' => 'display', 'valueString' => $display];
        }

        return response()->json(['resourceType' => 'Parameters', 'parameter' => $parameters]);
    }

    /** @return array{0: string, 1: string, 2: JsonResponse|null} */
    private function params(Request $request): array
    {
        $system = (string) $request->query('system', '');
        $code = (string) $request->query('code', '');

        if ($system === '' || $code === '') {
            return ['', '', response()->json($this->fhir->operationOutcome(
                'invalid',
                'Both system and code query parameters are required.',
            ), 400)];
        }

        if (! $this->terminology->isKnownSystem($system)) {
            return ['', '', response()->json($this->fhir->operationOutcome(
                'not-supported',
                "Code system '{$system}' is not supported. Supported: ".implode(', ', TerminologyService::SYSTEMS),
            ), 400)];
        }

        return [$system, $code, null];
    }
}
