<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PostgresAdapter;

require_once __DIR__ . '/../../../migrations/20261015000001_flow_rotation.php';

test('rotation migration backfills guards downgrade and preserves legacy rows', function (): void {
    $pdo = pdo();
    $identity = $pdo->query('SELECT current_database() AS db, inet_server_addr()::text AS host')->fetch();
    if ($identity['db'] !== 'slimtds_test' || !str_contains($_ENV['DB_DSN'] ?? '', 'host=db-test;')) {
        throw new RuntimeException('Migration test requires explicit disposable db-test/slimtds_test');
    }
    $adapter = new PostgresAdapter(['connection' => $pdo]);
    $migration = new FlowRotation('test', 20261015000001);
    $migration->setAdapter($adapter);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM core.campaigns');
        $migration->down();
        $id = $pdo->query("INSERT INTO core.campaigns (slug,name) VALUES ('legacy','Legacy') RETURNING id")->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO core.flows (campaign_id,name,weight,target_type,schema_id) VALUES (?, 'Legacy', -9, 'none', 2) RETURNING id");
        $stmt->execute([$id]);
        $flowId = $stmt->fetchColumn();
        $before = $pdo->query('SELECT * FROM core.campaigns')->fetch();
        $flowBefore = $pdo->query('SELECT * FROM core.flows')->fetch();
        $migration->up();
        $after = $pdo->query('SELECT * FROM core.campaigns')->fetch();
        expect($after['flow_rotation'])->toBe('position');
        unset($after['flow_rotation']);
        expect($after)->toBe($before);
        $flowAfter = $pdo->query('SELECT * FROM core.flows')->fetch();
        expect($flowAfter['flow_type'])->toBe('regular');
        unset($flowAfter['flow_type']);
        expect($flowAfter)->toBe($flowBefore);
        foreach ([['core.campaigns', 'flow_rotation', 'weight'], ['core.flows', 'flow_type', 'forced'], ['core.flows', 'flow_type', 'default']] as [$table, $column, $value]) {
            $pdo->exec("UPDATE {$table} SET {$column} = '{$value}'");
            $pdo->exec('SAVEPOINT guarded_down');
            try {
                $migration->down();
                throw new RuntimeException('Nondefault downgrade was allowed');
            } catch (PDOException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT guarded_down');
                expect($e->getCode())->toBe('P0001');
            }
            expect($pdo->query("SELECT {$column} FROM {$table}")->fetchColumn())->toBe($value);
            $pdo->exec("UPDATE core.campaigns SET flow_rotation = 'position'");
            $pdo->exec("UPDATE core.flows SET flow_type = 'regular'");
        }
        foreach ([['core.campaigns', 'flow_rotation'], ['core.flows', 'flow_type']] as [$table, $column]) {
            foreach (["'invalid'", 'NULL'] as $invalid) {
                $pdo->exec('SAVEPOINT invalid_enum');
                try {
                    $pdo->exec("UPDATE {$table} SET {$column} = {$invalid}");
                    throw new RuntimeException('Invalid enum was accepted');
                } catch (PDOException $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT invalid_enum');
                    expect($e->getCode())->toBe($invalid === 'NULL' ? '23502' : '23514');
                }
            }
        }
        $migration->down();
        expect($pdo->query('SELECT id FROM core.campaigns')->fetchColumn())->toBe($id);
        expect($pdo->query('SELECT id FROM core.flows')->fetchColumn())->toBe($flowId);
        $migration->up();
        expect($pdo->query('SELECT weight FROM core.flows')->fetchColumn())->toBe(-9);
    } finally {
        $pdo->rollBack();
    }
});
