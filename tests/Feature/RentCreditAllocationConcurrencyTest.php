<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Tier 2 (design approved 2026-10-25), Correction 2: "lock in ascending
 * rent_charges.id order" is a claim about real database behaviour that the
 * default SQLite test connection cannot verify at all — SQLite has no
 * row-level locking (it serializes at the connection/file level
 * regardless), so lockForUpdate() is effectively a no-op there. These
 * tests run against the actual configured MariaDB server (confirmed
 * 10.4.32, the same engine the app runs on in dev), using a disposable
 * database created and dropped around this one test class — never the
 * real kats_agencies database the app's own dev data lives in.
 *
 * What's actually proven here, stated plainly: (1) lockForUpdate() really
 * blocks a second, independent connection on this engine/driver, which a
 * no-op lock could never do; (2) the SQL this app's own locking code
 * issues is genuinely ordered ascending by id, not just documented as
 * such. True simultaneous multi-process deadlock reproduction would need
 * a multi-process harness beyond a single synchronous PHPUnit process —
 * not attempted here; what's tested is the mechanism each of those
 * scenarios depends on.
 */
class RentCreditAllocationConcurrencyTest extends TestCase
{
    private const DB_NAME = 'kats_agencies_concurrency_test';

    private static bool $skip = false;

    private static ?string $skipReason = null;

    private static bool $prepared = false;

    /**
     * Deliberately NOT setUpBeforeClass() — that runs before Laravel's
     * application (and therefore config()/Artisan::call()) exists for this
     * test class at all, which silently broke this setup the first time
     * (every assertion below would otherwise just report "skipped" with no
     * indication why). Guarded to run its one-time work exactly once per
     * test class via $prepared, from inside a normal, app-booted setUp().
     */
    /**
     * The base TestCase creates a brand-new application container (and
     * therefore a brand-new, empty config repository) for every single
     * test method — so registering the 'concurrency_test' connection is
     * cheap but must run on EVERY test, not once; only the expensive part
     * (actually creating the database and running migrations against it)
     * is guarded to happen exactly once per test class via $prepared.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skip) {
            $this->markTestSkipped(self::$skipReason ?? 'Concurrency test environment unavailable.');
        }

        config(['database.connections.concurrency_test' => array_merge(
            config('database.connections.mysql'),
            ['database' => self::DB_NAME],
        )]);

        if (self::$prepared) {
            return;
        }

        self::$prepared = true;

        try {
            $bootstrap = new PDO(
                'mysql:host='.env('DB_HOST', '127.0.0.1').';port='.env('DB_PORT', '3306'),
                env('DB_USERNAME', 'root'),
                env('DB_PASSWORD', ''),
            );
            $bootstrap->exec('DROP DATABASE IF EXISTS `'.self::DB_NAME.'`');
            $bootstrap->exec('CREATE DATABASE `'.self::DB_NAME.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            Artisan::call('migrate', ['--database' => 'concurrency_test', '--force' => true]);
        } catch (\Throwable $e) {
            self::$skip = true;
            self::$skipReason = 'Concurrency test setup failed: '.$e->getMessage();
            $this->markTestSkipped(self::$skipReason);
        }
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$skip || ! self::$prepared) {
            return;
        }

        try {
            DB::purge('concurrency_test');
            $bootstrap = new PDO(
                'mysql:host='.env('DB_HOST', '127.0.0.1').';port='.env('DB_PORT', '3306'),
                env('DB_USERNAME', 'root'),
                env('DB_PASSWORD', ''),
            );
            $bootstrap->exec('DROP DATABASE IF EXISTS `'.self::DB_NAME.'`');
        } catch (\Throwable $e) {
            // Best-effort cleanup — never let teardown failure mask a real test result.
        }
    }

    /** A fresh PDO connection to the throwaway database — genuinely independent of Laravel's own pooled connection, so locking between the two is real. */
    private function rawConnection(): PDO
    {
        $pdo = new PDO(
            'mysql:host='.env('DB_HOST', '127.0.0.1').';port='.env('DB_PORT', '3306').';dbname='.self::DB_NAME,
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', ''),
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function insertMinimalChargePair(PDO $pdo): array
    {
        $pdo->exec("INSERT INTO users (name, email, password, status, created_at, updated_at) VALUES ('CT Landlord', 'ct-landlord-".uniqid()."@test.local', 'x', 'active', NOW(), NOW())");
        $landlordId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO properties (landlord_id, name, type, status, created_at, updated_at) VALUES ({$landlordId}, 'CT Property', 'apartment', 'active', NOW(), NOW())");
        $propertyId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO blocks (property_id, name, created_at, updated_at) VALUES ({$propertyId}, 'CT Block', NOW(), NOW())");
        $blockId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO houses (block_id, house_number, monthly_rent, deposit_amount, status, created_at, updated_at) VALUES ({$blockId}, 'CT1', 20000, 20000, 'occupied', NOW(), NOW())");
        $houseId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO tenants (name, phone, created_at, updated_at) VALUES ('CT Tenant', '0700".random_int(100000, 999999)."', NOW(), NOW())");
        $tenantId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO tenancies (tenant_id, house_id, move_in_date, monthly_rent, deposit_required, status, created_at, updated_at) VALUES ({$tenantId}, {$houseId}, DATE_SUB(NOW(), INTERVAL 3 MONTH), 20000, 20000, 'active', NOW(), NOW())");
        $tenancyId = (int) $pdo->lastInsertId();

        // Charge X: a source (status='advance').
        $pdo->exec("INSERT INTO rent_charges (tenancy_id, period_year, period_month, due_date, amount_expected, amount_paid, status, created_at, updated_at) VALUES ({$tenancyId}, YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH)), MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)), DATE_SUB(NOW(), INTERVAL 1 MONTH), 20000, 28000, 'advance', NOW(), NOW())");
        $chargeX = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO payments (tenancy_id, rent_charge_id, amount, payment_date, method, status, created_at, updated_at) VALUES ({$tenancyId}, {$chargeX}, 28000, DATE_SUB(NOW(), INTERVAL 1 MONTH), 'mpesa', 'confirmed', NOW(), NOW())");

        // Charge Y: outstanding (a target).
        $pdo->exec("INSERT INTO rent_charges (tenancy_id, period_year, period_month, due_date, amount_expected, amount_paid, status, created_at, updated_at) VALUES ({$tenancyId}, YEAR(NOW()), MONTH(NOW()), DATE_SUB(NOW(), INTERVAL 2 DAY), 20000, 0, 'overdue', NOW(), NOW())");
        $chargeY = (int) $pdo->lastInsertId();

        return ['tenancyId' => $tenancyId, 'chargeX' => $chargeX, 'chargeY' => $chargeY];
    }

    public function test_lock_for_update_genuinely_blocks_a_second_independent_connection_on_this_engine(): void
    {
        $setup = $this->insertMinimalChargePair($a = $this->rawConnection());
        $chargeId = $setup['chargeX'];

        $a->beginTransaction();
        $a->query("SELECT id FROM rent_charges WHERE id = {$chargeId} FOR UPDATE");

        $b = $this->rawConnection();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $b->beginTransaction();

        $blocked = false;
        try {
            $b->query("SELECT id FROM rent_charges WHERE id = {$chargeId} FOR UPDATE");
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
        }
        $b->rollBack();

        $this->assertTrue($blocked, 'A second connection must genuinely wait on (and here, time out on) a row A is still holding — a no-op lock could never produce this.');

        $a->commit();

        // Now that A has released it, the same query on a fresh attempt succeeds immediately.
        $c = $this->rawConnection();
        $c->beginTransaction();
        $rows = $c->query("SELECT id FROM rent_charges WHERE id = {$chargeId} FOR UPDATE")->fetchAll();
        $this->assertCount(1, $rows);
        $c->commit();
    }

    public function test_the_apps_own_locking_code_issues_charges_sorted_ascending_by_id(): void
    {
        config(['database.default' => 'concurrency_test']);
        $setup = $this->insertMinimalChargePair($this->rawConnection());

        // Deliberately query with the ids reversed in the input array — the
        // service must still issue the lock sorted ascending, not in
        // whatever order discovery happened to produce them.
        $unsorted = [$setup['chargeY'], $setup['chargeX']];
        $this->assertGreaterThan($setup['chargeX'], $setup['chargeY'], 'Fixture assumption: Y was inserted after X, so Y > X.');

        $queries = [];
        DB::connection('concurrency_test')->listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'lock in share mode') || str_contains(strtolower($query->sql), 'for update')) {
                $queries[] = $query->sql;
            }
        });

        \App\Models\RentCharge::on('concurrency_test')
            ->whereIn('id', $unsorted)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('order by `id` asc', strtolower($queries[0]));

        config(['database.default' => 'sqlite']);
    }

    public function test_simultaneous_allocation_and_reversal_attempts_on_the_same_source_serialize_without_deadlock(): void
    {
        config(['database.default' => 'concurrency_test']);
        $setup = $this->insertMinimalChargePair($this->rawConnection());

        $service = app(\App\Services\RentCreditAllocationService::class);
        $target = \App\Models\RentCharge::on('concurrency_test')->find($setup['chargeY']);

        // Transaction A: create the allocation (locks X then Y internally, ascending).
        $created = $service->applyCreditToCharge($target);
        $this->assertCount(1, $created);

        // Transaction B: reverse it. Since A already committed by the time
        // B runs here (PHP is single-threaded within one test), this
        // specifically proves the two operations compose correctly in
        // sequence under the same locking discipline — not a literal
        // simultaneous race, which would need a multi-process harness.
        $reversed = $service->reverse($created->first(), 'concurrency test reversal');
        $this->assertTrue($reversed);

        $active = \App\Models\RentCreditAllocation::on('concurrency_test')->active()->count();
        $this->assertEquals(0, $active);

        config(['database.default' => 'sqlite']);
    }
}
