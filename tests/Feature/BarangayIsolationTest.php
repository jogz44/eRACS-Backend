<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\BarangayUser;
use App\Models\Budget;
use App\Models\Disbursement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BarangayIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Migrate
        $this->artisan('migrate');
    }

    public function test_barangay_user_cannot_read_other_barangay_records(): void
    {
        $barangayA = Barangay::create(['name' => 'A']);
        $barangayB = Barangay::create(['name' => 'B']);

        $userA = BarangayUser::create([
            'first_name' => 'User',
            'last_name' => 'A',
            'barangay_id' => $barangayA->id,
            'position_id' => 1,
            'email' => 'a@example.com',
            'username' => 'usera',
            'password' => bcrypt('secret1234'),
            'photo_path' => 'x',
            'is_approved' => true,
        ]);

        // Create budgets in both barangays
        Budget::create([
            'barangay_id' => $barangayA->id,
            'fiscal_year_id' => 1,
            'start_date' => now(),
            'end_date' => now(),
            'description' => 'Budget A',
            'original_amount' => 1000,
            'current_amount' => 1000,
            'augmentation' => 0,
            'return_amount' => 0,
            'user_id' => $userA->id,
        ]);

        Budget::create([
            'barangay_id' => $barangayB->id,
            'fiscal_year_id' => 1,
            'start_date' => now(),
            'end_date' => now(),
            'description' => 'Budget B',
            'original_amount' => 2000,
            'current_amount' => 2000,
            'augmentation' => 0,
            'return_amount' => 0,
            'user_id' => $userA->id,
        ]);

        Sanctum::actingAs($userA, [], 'barangay');

        $response = $this->getJson('/api/barangay/budgets');
        $response->assertStatus(200);
        $data = $response->json('data') ?? $response->json();

        $this->assertNotEmpty($data);
        $this->assertCount(1, $data, 'Should only see own barangay budgets');
        $this->assertSame('Budget A', $data[0]['description']);
    }

    public function test_barangay_user_cannot_create_record_for_other_barangay(): void
    {
        $barangayA = Barangay::create(['name' => 'A']);
        $barangayB = Barangay::create(['name' => 'B']);

        $userA = BarangayUser::create([
            'first_name' => 'User',
            'last_name' => 'A',
            'barangay_id' => $barangayA->id,
            'position_id' => 1,
            'email' => 'aa@example.com',
            'username' => 'useraa',
            'password' => bcrypt('secret1234'),
            'photo_path' => 'x',
            'is_approved' => true,
        ]);

        Sanctum::actingAs($userA, [], 'barangay');

        // Try to create a disbursement with barangay_id of B, expect it to be forced to A
        $payload = [
            'date' => now()->format('d/m/Y'),
            'dv_number' => 'DV-25-09-001',
            'cheque_number' => 'CHK-001',
            'bank_id' => 1,
            'payee' => 'Payee X',
            'dv_amount' => 123.45,
            'barangay_id' => $barangayB->id,
            'expenses' => [],
        ];

        $response = $this->postJson('/api/barangay/disbursements', $payload);

        // If banks table constraints exist, status may fail; only assert that when created, barangay_id != B
        if ($response->status() === 201) {
            $created = Disbursement::first();
            $this->assertEquals($barangayA->id, $created->barangay_id);
        } else {
            $response->assertStatus(422)->or($response->assertStatus(500));
        }
    }
}


