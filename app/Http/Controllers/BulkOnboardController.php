<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrganizationOnboarding;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BulkOnboardController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organizations' => 'required|array',
            'organizations.*.name' => 'required|string|max:255',
            'organizations.*.domain' => 'required|string|max:255',
            'organizations.*.contact_email' => 'nullable|email|max:255',
        ]);

        $batchId = Str::uuid()->toString();

        Log::info('Bulk onboard request received', [
            'batch_id' => $batchId,
            'organization_count' => count($validated['organizations']),
        ]);

        try {
            $chunkSize = 500;
            $chunks = array_chunk($validated['organizations'], $chunkSize);

            foreach ($chunks as $chunk) {
                $this->insertOrganizationsChunk($chunk, $batchId);
            }

            $organizations = Organization::forBatch($batchId)->get();
            foreach ($organizations as $organization) {
                ProcessOrganizationOnboarding::dispatch($organization->id)->onQueue('onboarding');
            }

            Log::info('Bulk onboard request processed', [
                'batch_id' => $batchId,
                'organizations_created' => $organizations->count(),
                'jobs_dispatched' => $organizations->count(),
            ]);

            return response()->json([
                'batch_id' => $batchId,
                'message' => 'Bulk onboarding initiated successfully',
                'organizations_count' => $organizations->count(),
            ], 202);

        } catch (\Exception $e) {
            Log::error('Bulk onboard request failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process bulk onboarding request',
                'batch_id' => $batchId,
            ], 500);
        }
    }

    private function insertOrganizationsChunk(array $organizations, string $batchId): void
    {
        $now = now();

        $data = array_map(function ($org) use ($batchId, $now) {
            return [
                'name' => $org['name'],
                'domain' => $org['domain'],
                'contact_email' => $org['contact_email'] ?? null,
                'status' => 'pending',
                'batch_id' => $batchId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $organizations);

        DB::table('organizations')->upsert(
            $data,
            ['domain'],
            ['name', 'contact_email', 'batch_id', 'status', 'updated_at']
        );
    }
}

