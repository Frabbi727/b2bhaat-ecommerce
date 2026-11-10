<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * AUD-6: an entry written by the scheduler, a queued job or a console command says so (actor type "system" and
 * what ran it), instead of looking like an anonymous visitor.
 */
class AuditSystemActorTest extends TestCase
{
    use RefreshDatabase;

    private function record(): AuditLog
    {
        return app(AuditService::class)->record([
            'module' => 'finance',
            'action' => 'escrow_released',
            'description' => 'Escrow released',
            'status' => 'success',
        ]);
    }

    public function test_an_entry_with_no_user_outside_a_web_request_is_marked_as_system(): void
    {
        $log = $this->record();

        $this->assertSame('system', $log->actor_type);
        $this->assertSame('System', $log->actor_name);
        $this->assertNull($log->actor_id);
    }

    public function test_the_command_that_wrote_the_entry_is_recorded(): void
    {
        Event::dispatch(new CommandStarting('escrow:release-matured', new ArrayInput([]), new NullOutput));

        $log = $this->record();

        $this->assertSame('system', $log->actor_type);
        $this->assertSame('command: escrow:release-matured', $log->metadata['source']);
    }

    public function test_a_signed_in_user_is_still_the_actor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $log = $this->record();

        $this->assertSame('user', $log->actor_type);
        $this->assertSame($user->id, $log->actor_id);
    }

    public function test_an_anonymous_web_visitor_is_a_guest_not_the_system(): void
    {
        $context = app(AuditContext::class);
        $context->routeName = 'order.track.token';

        $log = $this->record();

        $this->assertSame('guest', $log->actor_type);
        $this->assertNull($log->actor_name);
    }
}
