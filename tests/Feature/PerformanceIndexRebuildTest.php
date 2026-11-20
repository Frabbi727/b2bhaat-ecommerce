<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 1 review M-7: when a concurrent index build fails, PostgreSQL keeps an invalid index under that name. Running
 * the PERF-1 migration again must rebuild it instead of treating it as done. PostgreSQL only; concurrent builds can't
 * run inside the usual test transaction, so this test migrates a fresh database.
 */
class PerformanceIndexRebuildTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // Decide before the app boots, so no migrations run (or roll back) on SQLite.
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Invalid indexes only exist on PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_an_invalid_index_left_by_a_failed_build_is_rebuilt(): void
    {
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $name = 'perf_users_role_id_index';
        $roleId = Role::where('slug', 'seller')->value('id');
        User::factory()->count(2)->create(['role_id' => $roleId]);

        // Simulate a build that failed half-way: a unique build over repeated role ids fails and leaves the index invalid.
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS \"{$name}\"");
        try {
            DB::statement("CREATE UNIQUE INDEX CONCURRENTLY \"{$name}\" ON \"users\" (\"role_id\")");
        } catch (QueryException) {
        }
        $this->assertFalse($this->index($name)->valid, 'the simulated failed build must leave an invalid index');

        (require database_path('migrations/2026_11_09_044216_add_performance_indexes.php'))->up();

        $index = $this->index($name);
        $this->assertTrue($index->valid);
        $this->assertFalse($index->unique);
    }

    private function index(string $name): object
    {
        return DB::selectOne(
            'select i.indisvalid as valid, i.indisunique as unique from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = ?',
            [$name],
        );
    }
}
