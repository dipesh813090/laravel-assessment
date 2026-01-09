<?php

namespace Tests\Unit;

use App\Jobs\ProcessOrganizationOnboarding;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessOrganizationOnboardingJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that job is idempotent - can be run multiple times without side effects.
     */
    public function test_job_is_idempotent_when_already_completed(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => 'test.com',
            'contact_email' => 'test@test.com',
            'status' => 'completed',
            'batch_id' => 'test-batch-id',
            'processed_at' => now(),
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);
        $job->handle();

        $organization->refresh();
        $this->assertEquals('completed', $organization->status);
    }

    /**
     * Test that job is idempotent - skips if currently processing.
     */
    public function test_job_is_idempotent_when_already_processing(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => 'test.com',
            'contact_email' => 'test@test.com',
            'status' => 'processing',
            'batch_id' => 'test-batch-id',
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);
        $job->handle();

        $organization->refresh();
        $this->assertEquals('processing', $organization->status);
    }

    /**
     * Test that job processes pending organization successfully.
     */
    public function test_job_processes_pending_organization_successfully(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => 'test.com',
            'contact_email' => 'test@test.com',
            'status' => 'pending',
            'batch_id' => 'test-batch-id',
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);
        $job->handle();

        $organization->refresh();
        $this->assertEquals('completed', $organization->status);
        $this->assertNotNull($organization->processed_at);
    }

    /**
     * Test that job handles missing organization gracefully.
     */
    public function test_job_handles_missing_organization_gracefully(): void
    {
        $nonExistentId = 99999;
        $job = new ProcessOrganizationOnboarding($nonExistentId);
        
        $job->handle();
        
        $this->assertTrue(true);
    }

    /**
     * Test that job marks organization as failed on exception.
     */
    public function test_job_marks_organization_as_failed_on_exception(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => '',
            'contact_email' => 'test@test.com',
            'status' => 'pending',
            'batch_id' => 'test-batch-id',
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);

        try {
            $job->handle();
        } catch (\Exception $e) {
        }

        $organization->refresh();
        $this->assertEquals('failed', $organization->status);
        $this->assertNotNull($organization->failed_reason);
    }

    /**
     * Test that job has proper retry configuration.
     */
    public function test_job_has_proper_retry_configuration(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => 'test.com',
            'status' => 'pending',
            'batch_id' => 'test-batch-id',
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(10, $job->backoff);
    }

    /**
     * Test that job handles failed callback correctly.
     */
    public function test_job_handles_failed_callback_correctly(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'domain' => 'test.com',
            'status' => 'pending',
            'batch_id' => 'test-batch-id',
        ]);

        $job = new ProcessOrganizationOnboarding($organization->id);
        $exception = new \Exception('Test failure');

        $job->failed($exception);

        $organization->refresh();
        $this->assertEquals('failed', $organization->status);
        $this->assertEquals('Test failure', $organization->failed_reason);
    }
}

