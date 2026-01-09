<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkOnboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that accepts valid request and returns batch_id.
     */
    public function test_bulk_onboard_accepts_valid_request_and_returns_batch_id(): void
    {
        Queue::fake();

        $organizations = [
            [
                'name' => 'Organization 1',
                'domain' => 'organization1.com',
                'contact_email' => 'contact1@organization1.com',
            ],
            [
                'name' => 'Organization 2',
                'domain' => 'organization2.com',
                'contact_email' => 'contact2@organization2.com',
            ],
        ];

        $response = $this->postJson('/api/bulk-onboard', [
            'organizations' => $organizations,
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'batch_id',
                'message',
                'organizations_count',
            ]);

        $data = $response->json();
        $batchId = $data['batch_id'];
        $this->assertNotEmpty($batchId);
        $this->assertEquals(2, $data['organizations_count']);

        $this->assertDatabaseCount('organizations', 2);
        
        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 1',
            'domain' => 'organization1.com',
            'contact_email' => 'contact1@organization1.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);
        
        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 2',
            'domain' => 'organization2.com',
            'contact_email' => 'contact2@organization2.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Test that validates required fields.
     */
    public function test_bulk_onboard_validates_required_fields(): void
    {
        $response = $this->postJson('/api/bulk-onboard', [
            'organizations' => [
                [
                    'name' => 'Organization 3',
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organizations.0.domain']);

        $this->assertDatabaseCount('organizations', 0);
    }

    /**
     * Test that handles duplicate domains gracefully.
     */
    public function test_bulk_onboard_handles_duplicate_domains(): void
    {
        Queue::fake();

        $existingOrg = Organization::create([
            'name' => 'Organization 4',
            'domain' => 'organization4.com',
            'contact_email' => 'existing@existing.com',
            'status' => 'pending',
            'batch_id' => 'existing-batch-id',
        ]);

        $existingOrgId = $existingOrg->id;

        $organizations = [
            [
                'name' => 'Organization 4',
                'domain' => 'organization4.com',
                'contact_email' => 'contact4@organization4.com',
            ],
            [
                'name' => 'Organization 5',
                'domain' => 'organization5.com',
                'contact_email' => 'contact5@organization5.com',
            ],
        ];

        $response = $this->postJson('/api/bulk-onboard', [
            'organizations' => $organizations,
        ]);

        $response->assertStatus(202);
        $data = $response->json();
        $batchId = $data['batch_id'];

        $this->assertDatabaseCount('organizations', 2);
        
        $updatedOrg = Organization::where('domain', 'organization4.com')->first();
        $this->assertNotNull($updatedOrg);
        $this->assertEquals($existingOrgId, $updatedOrg->id);
        $this->assertEquals('Organization 4', $updatedOrg->name);
        $this->assertEquals('contact4@organization4.com', $updatedOrg->contact_email);
        $this->assertEquals($batchId, $updatedOrg->batch_id);
        
        $this->assertDatabaseHas('organizations', [
            'domain' => 'organization5.com',
            'name' => 'Organization 5',
            'contact_email' => 'contact5@organization5.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);

        $this->assertDatabaseMissing('organizations', [
            'domain' => 'organization4.com',
            'batch_id' => 'existing-batch-id',
        ]);
    }

    /**
     * Test that bulk onboard endpoint processes organizations in chunks.
     */
    public function test_bulk_onboard_processes_organizations_in_chunks(): void
    {
        Queue::fake();

        $organizations = [];
        for ($i = 0; $i < 1500; $i++) {
            $organizations[] = [
                'name' => "Organization {$i}",
                'domain' => "organization{$i}.com",
            ];
        }

        $response = $this->postJson('/api/bulk-onboard', [
            'organizations' => $organizations,
        ]);

        $response->assertStatus(202);
        $data = $response->json();
        $batchId = $data['batch_id'];

        $this->assertDatabaseCount('organizations', 1500);
        $this->assertEquals(1500, $data['organizations_count']);
        $this->assertEquals(1500, Organization::forBatch($batchId)->count());

        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 0',
            'domain' => 'organization0.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 500',
            'domain' => 'organization500.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 1499',
            'domain' => 'organization1499.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Test that bulk onboard endpoint handles optional contact_email field.
     */
    public function test_bulk_onboard_handles_optional_contact_email(): void
    {
        Queue::fake();

        $organizations = [
            [
                'name' => 'Organization 6',
                'domain' => 'organization6.com',
            ],
            [
                'name' => 'Organization 7',
                'domain' => 'organization7.com',
                'contact_email' => 'contact7@organization7.com',
            ],
        ];

        $response = $this->postJson('/api/bulk-onboard', [
            'organizations' => $organizations,
        ]);

        $response->assertStatus(202);
        $data = $response->json();
        $batchId = $data['batch_id'];

        $this->assertDatabaseCount('organizations', 2);
        
        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 6',
            'domain' => 'organization6.com',
            'contact_email' => null,
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Organization 7',
            'domain' => 'organization7.com',
            'contact_email' => 'contact7@organization7.com',
            'status' => 'pending',
            'batch_id' => $batchId,
        ]);
    }
}

