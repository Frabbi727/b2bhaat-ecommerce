<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Jobs\LogAuditJob;
use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/**
 * AUD-2: audit records can't be edited or deleted. AUD-3: an audit write that fails is reported and never breaks the
 * business action around it, and queued entries are only sent once that action has committed.
 */
class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $module = 'finance'): AuditLog
    {
        return AuditLog::create([
            'module' => $module,
            'action' => 'payout_released',
            'description' => 'Released ৳500 to a seller',
            'created_at' => now(),
        ]);
    }

    public function test_an_audit_record_cannot_be_edited(): void
    {
        $log = $this->entry();

        try {
            $log->update(['description' => 'Released ৳5 to a seller']);
            $this->fail('Editing an audit record should be refused.');
        } catch (LogicException $e) {
            $this->assertSame(AuditLog::IMMUTABLE_MESSAGE, $e->getMessage());
        }

        $this->assertSame('Released ৳500 to a seller', $log->fresh()->description);
    }

    public function test_an_audit_record_cannot_be_deleted(): void
    {
        $log = $this->entry();

        $this->expectException(LogicException::class);

        try {
            $log->delete();
        } finally {
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }

    public function test_routine_pruning_still_works(): void
    {
        $old = $this->entry('products');
        DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(400)]);

        $this->artisan('audit:prune', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
    }

    public function test_a_failed_audit_write_is_reported_and_the_business_action_still_commits(): void
    {
        Exceptions::fake();

        DB::transaction(function (): void {
            // No description: the NOT NULL column makes the insert fail.
            app(AuditService::class)->record(['module' => 'finance', 'action' => 'payout_released']);

            DB::table('audit_logs')->insert([
                'module' => 'finance',
                'action' => 'business_write_after_failed_audit',
                'description' => 'The surrounding action carried on',
                'created_at' => now(),
            ]);
        });

        $this->assertDatabaseHas('audit_logs', ['action' => 'business_write_after_failed_audit']);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_queued_audit_entries_are_not_sent_for_a_rolled_back_action(): void
    {
        Queue::fake();

        try {
            DB::transaction(function (): void {
                app(AuditService::class)->record([
                    'module' => 'products',
                    'action' => 'stock_updated',
                    'description' => 'Stock changed',
                ]);

                throw new \RuntimeException('The action failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Queue::assertNotPushed(LogAuditJob::class);
    }

    public function test_queued_audit_entries_are_sent_once_the_action_commits(): void
    {
        Queue::fake();

        DB::transaction(function (): void {
            app(AuditService::class)->record([
                'module' => 'products',
                'action' => 'stock_updated',
                'description' => 'Stock changed',
            ]);
        });

        Queue::assertPushed(LogAuditJob::class, 1);
    }
}
