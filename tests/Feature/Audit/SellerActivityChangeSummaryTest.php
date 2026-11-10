<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Filament\Seller\Resources\Activities\SellerActivityResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Audit\AuditChangeSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUD-8: a seller reading their activity log sees what changed in plain words ("Price: ৳100.00 → ৳90.00"), not a
 * raw before/after dump of database columns.
 */
class SellerActivityChangeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $old, array $new, int $sellerId = 0): AuditLog
    {
        return AuditLog::create([
            'seller_id' => $sellerId,
            'module' => 'products',
            'action' => 'updated',
            'description' => 'Product #5 updated',
            'old_values' => $old,
            'new_values' => $new,
            'changed_fields' => array_keys($new),
            'status' => 'success',
            'created_at' => now(),
        ]);
    }

    public function test_changes_are_summarised_in_plain_words(): void
    {
        $lines = app(AuditChangeSummary::class)->lines($this->entry(
            ['title' => 'Blue Shirt', 'price' => '100.00', 'is_active' => true, 'category_id' => 4, 'updated_at' => '2026-11-01 10:00:00', 'tags' => null, 'blocked_reason' => '[REDACTED]'],
            ['title' => 'Navy Shirt', 'price' => '90.00', 'is_active' => false, 'category_id' => 7, 'updated_at' => '2026-11-02 10:00:00', 'tags' => ['summer', 'cotton'], 'blocked_reason' => '[REDACTED]'],
        ));

        $this->assertContains('Title: Blue Shirt → Navy Shirt', $lines);
        $this->assertContains('Price: ৳100.00 → ৳90.00', $lines);
        $this->assertContains('Active: Yes → No', $lines);
        $this->assertContains('Category: changed', $lines);
        $this->assertContains('Tags: — → summer, cotton', $lines);
        $this->assertContains('Blocked reason: changed (hidden)', $lines);
        $this->assertNotContains('Updated at: 2026-11-01 10:00:00 → 2026-11-02 10:00:00', $lines);
        $this->assertCount(6, $lines);
    }

    public function test_a_new_record_lists_what_was_set(): void
    {
        $log = AuditLog::create([
            'seller_id' => 0,
            'module' => 'products',
            'action' => 'created',
            'description' => 'Product #5 created',
            'new_values' => ['title' => 'Blue Shirt', 'stock' => 50, 'id' => 5],
            'status' => 'success',
            'created_at' => now(),
        ]);

        $this->assertSame(['Title: Blue Shirt', 'Stock: 50'], app(AuditChangeSummary::class)->lines($log));
    }

    public function test_the_seller_detail_page_shows_the_summary_instead_of_raw_values(): void
    {
        $role = Role::firstOrCreate(['slug' => 'seller'], ['name' => 'Seller']);
        $seller = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        SellerProfile::create(['user_id' => $seller->id, 'store_name' => 'Summary Store', 'store_slug' => 'summary-store', 'is_approved' => true]);

        $log = $this->entry(['price' => '100.00', 'category_id' => 4], ['price' => '90.00', 'category_id' => 7], $seller->id);

        $this->actingAs($seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        $this->get(SellerActivityResource::getUrl('view', ['record' => $log]))
            ->assertSuccessful()
            ->assertSee('What changed')
            ->assertSee('Price: ৳100.00 → ৳90.00')
            ->assertDontSee('Before (Old Values)')
            ->assertDontSee('category_id');
    }
}
