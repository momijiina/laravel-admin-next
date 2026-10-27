<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Form;
use Encore\Admin\Traits\ModelTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ModelTreeDeletionNode extends Model
{
    use ModelTree {
        delete as private deleteTree;
    }

    protected $table = 'unused_tree_deletion_nodes';
    protected $primaryKey = 'node_key';
    protected $keyType = 'string';
    protected $guarded = [];
    public $incrementing = false;
    public $timestamps = false;
    public static array $deleteCalls = [];

    public function delete()
    {
        static::$deleteCalls[] = [
            $this->getKey(), $this->getConnectionName(), $this->getTable(), $this->getParentColumn(),
        ];

        return $this->deleteTree();
    }
}

class ModelTreeDeletionSoftNode extends ModelTreeDeletionNode
{
    use SoftDeletes;

    protected static function booted()
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $query->where('tenant_id', 1);
        });
    }
}

class ModelTreeDeletionFormNode extends ModelTreeDeletionSoftNode
{
    protected $parentColumn = 'ancestor_key';
}

class ModelTreeDeletionForceMethodNode extends ModelTreeDeletionNode
{
    public function isForceDeleting()
    {
        throw new \LogicException('This method does not implement SoftDeletes.');
    }
}

class ModelTreeDeletionCycleNode extends ModelTreeDeletionNode
{
    protected static function booted()
    {
        static::addGlobalScope('node_order', fn (Builder $query) => $query->orderBy('node_key'));
    }
}

class ModelTreeDeletionTest extends TestCase
{
    private array $events = [];

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        foreach (['testing', 'tree_deletion'] as $connection) {
            $app['config']->set('database.connections.'.$connection, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        ModelTreeDeletionNode::$deleteCalls = [];

        foreach (['testing', 'tree_deletion'] as $connection) {
            $schema = $this->app['db']->connection($connection)->getSchemaBuilder();
            $schema->create('tree_deletion_nodes', function (Blueprint $table) {
                $table->string('node_key')->primary();
                $table->string('ancestor_key')->nullable();
                $table->integer('tenant_id')->default(1);
                $table->softDeletes();
            });
            $schema->create('tree_deletion_audit', function (Blueprint $table) {
                $table->increments('id');
                $table->string('node_key');
            });
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ([
                ModelTreeDeletionNode::class, ModelTreeDeletionSoftNode::class,
                ModelTreeDeletionFormNode::class, ModelTreeDeletionForceMethodNode::class,
                ModelTreeDeletionCycleNode::class,
            ] as $class) {
                $class::flushEventListeners();
            }
            parent::tearDown();
        } finally {
            ModelTreeDeletionNode::$deleteCalls = [];
        }
    }

    public function test_direct_deletion_visits_every_descendant_and_preserves_runtime_configuration(): void
    {
        $nodes = $this->seedTree();
        // A previously loaded empty relation must not hide descendants added later.
        $nodes['root']->setRelation('children', $nodes['root']->newCollection());
        $this->recordEvents(ModelTreeDeletionNode::class, ['deleting', 'deleted']);
        $this->app['db']->connection()->table('tree_deletion_nodes')->insert([
            ['node_key' => 'root', 'ancestor_key' => null],
            ['node_key' => 'branch', 'ancestor_key' => 'root'],
        ]);

        $this->assertTrue($nodes['root']->delete());

        $this->assertSame(['unrelated', 'unrelated-leaf'], $this->keys());
        $this->assertSame(['branch', 'root'], $this->keys('testing'));
        $this->assertNodeEvents(['root', 'branch', 'deep', 'leaf', 'sibling'], ['deleting', 'deleted']);
        $this->assertDescendantsFinishFirst([
            ['branch', 'root'], ['deep', 'branch'], ['leaf', 'deep'], ['sibling', 'root'],
        ]);
        $calls = ModelTreeDeletionNode::$deleteCalls;
        $this->assertEqualsCanonicalizing(['root', 'branch', 'deep', 'leaf', 'sibling'], array_column($calls, 0));
        foreach ($calls as $call) {
            $this->assertSame(['tree_deletion', 'tree_deletion_nodes', 'ancestor_key'], array_slice($call, 1));
        }
        $this->assertSame(0, $nodes['root']->getConnection()->transactionLevel());
    }

    public function test_quiet_deletion_still_cascades_without_model_events(): void
    {
        $nodes = $this->seedTree();
        $this->recordEvents(ModelTreeDeletionNode::class, ['deleting', 'deleted']);

        $this->assertTrue($nodes['root']->deleteQuietly());

        $this->assertSame(['unrelated', 'unrelated-leaf'], $this->keys());
        $this->assertSame([], $this->events);
        $this->assertCount(5, ModelTreeDeletionNode::$deleteCalls);
    }

    public static function unsavedKeys(): iterable
    {
        yield 'without a key' => [null];
        yield 'with an assigned key' => ['root'];
    }

    #[DataProvider('unsavedKeys')]
    public function test_deleting_an_unsaved_model_is_a_null_no_op(?string $key): void
    {
        $this->createNode('orphan', $key);
        $model = $this->model();
        $model->setAttribute('node_key', $key);
        $this->recordEvents(ModelTreeDeletionNode::class, ['deleting', 'deleted']);

        $this->assertNull($model->delete());

        $this->assertSame(['orphan'], $this->keys());
        $this->assertSame([], $this->events);
        $this->assertSame(0, $model->getConnection()->transactionLevel());
    }

    public function test_deleting_an_already_deleted_instance_does_not_delete_new_children(): void
    {
        $root = $this->createNode('root', null);
        $this->assertTrue($root->delete());
        $this->createNode('late-child', 'root');
        $this->recordEvents(ModelTreeDeletionNode::class, ['deleting', 'deleted']);

        $this->assertNull($root->delete());

        $this->assertSame(['late-child'], $this->keys());
        $this->assertSame([], $this->events);
    }

    public static function projectedOutcomes(): iterable
    {
        yield 'successful model pipeline' => [false];
        yield 'observer veto' => [true];
    }

    #[DataProvider('projectedOutcomes')]
    public function test_projected_model_without_its_key_preserves_null_parent_rows_and_observer_atomicity(bool $veto): void
    {
        $this->seedTree();
        $before = $this->rows();
        $projected = $this->model()->newQuery()->select('ancestor_key')->where('node_key', 'root')->firstOrFail();
        $projected->setParentColumn('ancestor_key');
        $this->assertTrue($projected->exists);
        $this->assertNull($projected->getKey());
        ModelTreeDeletionNode::deleting(function (Model $node) use ($veto) {
            $node->getConnection()->table('tree_deletion_audit')->insert(['node_key' => 'projected']);

            return $veto ? false : null;
        });

        $this->assertSame(!$veto, $projected->delete());

        $this->assertSame($before, $this->rows());
        $this->assertSame($veto ? 0 : 1, $projected->getConnection()->table('tree_deletion_audit')->count());
        $this->assertSame(0, $projected->getConnection()->transactionLevel());
    }

    public static function vetoNodes(): iterable
    {
        yield 'root veto after descendants' => ['root'];
        yield 'descendant veto after deeper descendants' => ['branch'];
    }

    #[DataProvider('vetoNodes')]
    public function test_veto_rolls_back_the_subtree_and_observer_writes(string $vetoKey): void
    {
        $nodes = $this->seedTree();
        $before = $this->rows();
        $this->recordEvents(ModelTreeDeletionNode::class, ['deleted']);
        ModelTreeDeletionNode::deleted(function (Model $node) {
            $node->getConnection()->table('tree_deletion_audit')->insert(['node_key' => $node->getKey()]);
        });
        ModelTreeDeletionNode::deleting(fn (Model $node) => $node->getKey() === $vetoKey ? false : null);

        $this->assertFalse($nodes['root']->delete());

        $this->assertSame($before, $this->rows());
        $this->assertSame(0, $nodes['root']->getConnection()->table('tree_deletion_audit')->count());
        $this->assertContains(['deleted', 'leaf'], $this->events);
        $this->assertContains(['deleted', 'deep'], $this->events);
        $this->assertSame(0, $nodes['root']->getConnection()->transactionLevel());
    }

    public static function transactionOutcomes(): iterable
    {
        yield 'success' => ['success'];
        yield 'veto' => ['veto'];
        yield 'exception after SQL' => ['exception'];
        yield 'error after SQL' => ['error'];
    }

    #[DataProvider('transactionOutcomes')]
    public function test_model_connection_transaction_preserves_existing_outer_levels(string $outcome): void
    {
        $nodes = $this->seedTree();
        $before = $this->rows();
        $connection = $nodes['root']->getConnection();
        $default = $this->app['db']->connection();
        $failure = $outcome === 'error' ? new \TypeError('descendant observer failed') : new \RuntimeException('descendant observer failed');
        $reject = true;
        $levels = [];
        ModelTreeDeletionNode::deleting(function (Model $node) use ($outcome, &$reject) {
            return $reject && $outcome === 'veto' && $node->getKey() === 'branch' ? false : null;
        });
        ModelTreeDeletionNode::deleted(function (Model $node) use ($outcome, $failure, $default, &$reject, &$levels) {
            $levels[] = [$node->getConnection()->transactionLevel(), $default->transactionLevel()];
            $node->getConnection()->table('tree_deletion_audit')->insert(['node_key' => $node->getKey()]);
            if ($reject && in_array($outcome, ['exception', 'error'], true) && $node->getKey() === 'branch') {
                throw $failure;
            }
        });
        $default->beginTransaction();
        $connection->beginTransaction();
        $connection->beginTransaction();

        try {
            $this->createNode('outer-marker', null);
            $insideOuter = $this->rows();
            $caught = null;
            $result = null;
            try {
                $result = $nodes['root']->delete();
            } catch (\Throwable $exception) {
                $caught = $exception;
            }

            if ($outcome === 'success') {
                $this->assertNull($caught);
                $this->assertTrue($result);
                $this->assertSame(['outer-marker', 'unrelated', 'unrelated-leaf'], $this->keys());
                $this->assertSame(5, $connection->table('tree_deletion_audit')->count());
            } else {
                if ($outcome === 'veto') {
                    $this->assertNull($caught);
                    $this->assertFalse($result);
                } else {
                    $this->assertSame($failure, $caught);
                }
                $this->assertSame($insideOuter, $this->rows());
                $this->assertSame(0, $connection->table('tree_deletion_audit')->count());
            }
            $this->assertSame(2, $connection->transactionLevel());
            $this->assertSame(1, $default->transactionLevel());
            $this->assertNotEmpty($levels);
            foreach ($levels as [$modelLevel, $defaultLevel]) {
                $this->assertGreaterThan(2, $modelLevel);
                $this->assertSame(1, $defaultLevel);
            }

            if ($outcome !== 'success') {
                // A failed operation must release its cycle guard before a retry.
                $reject = false;
                $root = $this->model()->newQuery()->findOrFail('root');
                $root->setParentColumn('ancestor_key');
                $this->assertTrue($root->delete());
                $this->assertSame(2, $connection->transactionLevel());
            }
        } finally {
            $connection->rollBack(0);
            $default->rollBack(0);
        }

        $this->assertSame($before, $this->rows());
        $this->assertSame(0, $connection->table('tree_deletion_audit')->count());
    }

    public function test_soft_delete_follows_active_scoped_branches_and_keeps_archived_branches(): void
    {
        $nodes = $this->seedSoftTree();
        $before = $this->rows();
        $this->recordEvents(ModelTreeDeletionSoftNode::class, ['deleting', 'trashed', 'deleted', 'forceDeleting', 'forceDeleted']);

        $this->assertTrue($nodes['root']->delete());

        $rows = $this->rows();
        $this->assertSame(array_keys($before), array_keys($rows));
        foreach (['root', 'branch', 'deep', 'leaf', 'sibling'] as $key) {
            $this->assertNotNull($rows[$key]['deleted_at']);
        }
        foreach (['archived', 'archived-leaf', 'hidden', 'hidden-leaf', 'unrelated', 'unrelated-leaf'] as $key) {
            $this->assertEquals($before[$key], $rows[$key]);
        }
        $this->assertNodeEvents(['root', 'branch', 'deep', 'leaf', 'sibling'], ['deleting', 'trashed', 'deleted']);
        $this->assertDescendantsFinishFirst([
            ['branch', 'root'], ['deep', 'branch'], ['leaf', 'deep'], ['sibling', 'root'],
        ]);
    }

    public function test_force_delete_includes_archived_descendants_but_retains_other_global_scopes(): void
    {
        $nodes = $this->seedSoftTree();
        $before = $this->rows();
        $this->recordEvents(ModelTreeDeletionSoftNode::class, ['forceDeleting', 'deleting', 'deleted', 'forceDeleted', 'trashed']);

        $this->assertTrue($nodes['root']->forceDelete());

        $this->assertSame(['hidden', 'hidden-leaf', 'unrelated', 'unrelated-leaf'], $this->keys());
        foreach ($this->rows() as $key => $row) {
            $this->assertEquals($before[$key], $row);
        }
        $this->assertNodeEvents(
            ['root', 'branch', 'deep', 'leaf', 'sibling', 'archived', 'archived-leaf'],
            ['forceDeleting', 'deleting', 'deleted', 'forceDeleted']
        );
        $this->assertDescendantsFinishFirst([
            ['branch', 'root'], ['deep', 'branch'], ['leaf', 'deep'], ['sibling', 'root'],
            ['archived', 'root'], ['archived-leaf', 'archived'],
        ]);
    }

    public function test_descendant_force_delete_veto_restores_active_and_archived_rows(): void
    {
        $nodes = $this->seedSoftTree();
        $before = $this->rows();
        $completed = 0;
        ModelTreeDeletionSoftNode::forceDeleted(function (Model $node) use (&$completed) {
            $completed++;
            $node->getConnection()->table('tree_deletion_audit')->insert(['node_key' => $node->getKey()]);
        });
        ModelTreeDeletionSoftNode::forceDeleting(function () use (&$completed) {
            return $completed > 0 ? false : null;
        });

        $this->assertFalse($nodes['root']->forceDelete());

        $this->assertGreaterThan(0, $completed);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, $nodes['root']->getConnection()->table('tree_deletion_audit')->count());
        $this->assertSame(0, $nodes['root']->getConnection()->transactionLevel());
    }

    public function test_form_reports_force_delete_veto_without_calling_the_deleted_callback(): void
    {
        $root = $this->createNode('root', null, ['deleted_at' => '2020-01-02 03:04:05'], ModelTreeDeletionFormNode::class);
        $this->createNode('child', 'root', [], ModelTreeDeletionFormNode::class);
        $before = $this->rows();
        ModelTreeDeletionFormNode::forceDeleting(fn () => false);
        $form = new Form($root);
        $deletedCalls = 0;
        $form->deleted(function () use (&$deletedCalls) {
            $deletedCalls++;
        });

        $response = $form->destroy('root');

        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame(0, $deletedCalls);
        $this->assertSame($before, $this->rows());
    }

    public function test_unrelated_force_deleting_method_does_not_enable_soft_delete_behavior(): void
    {
        $nodes = $this->seedTree(ModelTreeDeletionForceMethodNode::class);

        $this->assertTrue($nodes['root']->delete());

        $this->assertSame(['unrelated', 'unrelated-leaf'], $this->keys());
    }

    public static function cycles(): iterable
    {
        yield 'self cycle' => [['root' => 'root']];
        yield 'two node cycle' => [['root' => 'other', 'other' => 'root']];
    }

    #[DataProvider('cycles')]
    public function test_cycles_fail_clearly_and_rollback_before_a_later_valid_deletion(array $parents): void
    {
        $nodes = [];
        foreach ($parents as $key => $parent) {
            $nodes[$key] = $this->createNode($key, $parent, [], ModelTreeDeletionCycleNode::class);
        }
        // The fixture's explicit ordering processes this sibling before the cycle.
        $this->createNode('a-leaf', 'root', [], ModelTreeDeletionCycleNode::class);
        $this->recordEvents(ModelTreeDeletionCycleNode::class, ['deleted']);
        ModelTreeDeletionCycleNode::deleted(function (Model $node) {
            $node->getConnection()->table('tree_deletion_audit')->insert(['node_key' => $node->getKey()]);
        });
        $before = $this->rows();
        $caught = null;
        try {
            $nodes['root']->delete();
        } catch (\LogicException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(\LogicException::class, $caught);
        $this->assertMatchesRegularExpression('/cycl/i', $caught->getMessage());
        $this->assertContains(['deleted', 'a-leaf'], $this->events);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, $nodes['root']->getConnection()->table('tree_deletion_audit')->count());
        $this->assertSame(0, $nodes['root']->getConnection()->transactionLevel());

        $nodes['root']->getConnection()->table('tree_deletion_nodes')->where('node_key', 'root')->update(['ancestor_key' => null]);
        $this->assertTrue($nodes['root']->delete());
        $this->assertSame([], $this->keys());
    }

    private function model(string $class = ModelTreeDeletionNode::class): ModelTreeDeletionNode
    {
        $model = new $class();
        $model->setConnection('tree_deletion');
        $model->setTable('tree_deletion_nodes');
        $model->setParentColumn('ancestor_key');

        return $model;
    }

    private function createNode(string $key, ?string $parent, array $attributes = [], string $class = ModelTreeDeletionNode::class): ModelTreeDeletionNode
    {
        $node = $this->model($class);
        $node->fill(array_merge(['node_key' => $key, 'ancestor_key' => $parent], $attributes));
        $node->save();

        return $node;
    }

    private function seedTree(string $class = ModelTreeDeletionNode::class): array
    {
        $nodes = [];
        foreach ([
            'root' => null, 'branch' => 'root', 'deep' => 'branch', 'leaf' => 'deep',
            'sibling' => 'root', 'unrelated' => null, 'unrelated-leaf' => 'unrelated',
        ] as $key => $parent) {
            $nodes[$key] = $this->createNode($key, $parent, [], $class);
        }

        return $nodes;
    }

    private function seedSoftTree(): array
    {
        $nodes = $this->seedTree(ModelTreeDeletionSoftNode::class);
        $this->createNode('archived', 'root', ['deleted_at' => '2020-01-02 03:04:05'], ModelTreeDeletionSoftNode::class);
        $this->createNode('archived-leaf', 'archived', [], ModelTreeDeletionSoftNode::class);
        $this->createNode('hidden', 'root', ['tenant_id' => 2], ModelTreeDeletionSoftNode::class);
        $this->createNode('hidden-leaf', 'hidden', [], ModelTreeDeletionSoftNode::class);

        return $nodes;
    }

    private function rows(string $connection = 'tree_deletion'): array
    {
        return $this->app['db']->connection($connection)->table('tree_deletion_nodes')
            ->orderBy('node_key')->get()->mapWithKeys(fn ($row) => [$row->node_key => (array) $row])->all();
    }

    private function keys(string $connection = 'tree_deletion'): array
    {
        return array_keys($this->rows($connection));
    }

    private function recordEvents(string $class, array $events): void
    {
        foreach ($events as $event) {
            $this->app['events']->listen('eloquent.'.$event.': '.$class, function (Model $node) use ($event) {
                $this->events[] = [$event, $node->getKey()];
            });
        }
    }

    private function assertNodeEvents(array $keys, array $events): void
    {
        $this->assertCount(count($keys) * count($events), $this->events);
        foreach ($keys as $key) {
            $actual = array_values(array_filter($this->events, fn (array $event) => $event[1] === $key));
            $this->assertSame(array_map(fn (string $event) => [$event, $key], $events), $actual);
        }
    }

    private function assertDescendantsFinishFirst(array $edges): void
    {
        foreach ($edges as [$child, $parent]) {
            $childPosition = array_search(['deleted', $child], $this->events, true);
            $parentPosition = array_search(['deleting', $parent], $this->events, true);
            $this->assertIsInt($childPosition);
            $this->assertIsInt($parentPosition);
            $this->assertLessThan($parentPosition, $childPosition);
        }
    }
}
