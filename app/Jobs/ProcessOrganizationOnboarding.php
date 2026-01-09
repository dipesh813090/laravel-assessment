<?php

namespace App\Jobs;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOrganizationOnboarding implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $organizationId) {
        $this->onQueue('onboarding');
    }

    public function handle(): void
    {
        $organization = Organization::find($this->organizationId);

        if (!$organization) {
            Log::warning('Organization not found for processing', [
                'organization_id' => $this->organizationId,
            ]);
            return;
        }

        if ($organization->status === 'completed') {
            Log::info('Organization already processed, skipping', [
                'batch_id' => $organization->batch_id,
                'organization_id' => $organization->id,
                'domain' => $organization->domain,
            ]);
            return;
        }

        if ($organization->status === 'processing') {
            Log::info('Organization already being processed, skipping', [
                'batch_id' => $organization->batch_id,
                'organization_id' => $organization->id,
                'domain' => $organization->domain,
            ]);
            return;
        }

        $organization->update([
            'status' => 'processing',
        ]);

        Log::info('Processing organization onboarding', [
            'batch_id' => $organization->batch_id,
            'organization_id' => $organization->id,
            'domain' => $organization->domain,
            'status' => 'processing',
        ]);

        try {
            $this->performOnboarding($organization);

            $organization->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            Log::info('Organization onboarding completed', [
                'batch_id' => $organization->batch_id,
                'organization_id' => $organization->id,
                'domain' => $organization->domain,
                'status' => 'completed',
            ]);

        } catch (Throwable $e) {
            $organization->update([
                'status' => 'failed',
                'failed_reason' => $e->getMessage(),
            ]);

            Log::error('Organization onboarding failed', [
                'batch_id' => $organization->batch_id,
                'organization_id' => $organization->id,
                'domain' => $organization->domain,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function performOnboarding(Organization $organization): void
    {
        usleep(100000); // 0.1 seconds

        if (empty($organization->domain)) {
            throw new \Exception('Domain is required for onboarding');
        }

        if ($organization->contact_email && ! filter_var($organization->contact_email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Invalid email format');
        }
    }

    public function failed(Throwable $exception): void
    {
        $organization = Organization::find($this->organizationId);

        if ($organization) {
            $organization->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            Log::error('Organization onboarding job failed permanently', [
                'batch_id' => $organization->batch_id,
                'organization_id' => $organization->id,
                'domain' => $organization->domain,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts(),
            ]);
        }
    }
}

