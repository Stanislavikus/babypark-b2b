<?php

namespace Tests\Unit\Connectors;

use App\Services\Connectors\ConnectorAccountConstraintViolationClassifier;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorAccountConstraintViolationClassifierTest extends TestCase
{
    private ConnectorAccountConstraintViolationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ConnectorAccountConstraintViolationClassifier;
    }

    #[Test]
    public function mysql_duplicate_key_on_active_name_index_is_recognized(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into connector_accounts ...',
            [],
            new \PDOException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ws-def-name' for key 'connector_accounts.ca_ws_def_name_unique'",
                23000,
            ),
        );
        $exception->errorInfo = ['23000', 1062, "Duplicate entry for key 'ca_ws_def_name_unique'"];

        $this->assertTrue($this->classifier->isActiveNameUniquenessConflict($exception));
    }

    #[Test]
    public function sqlite_unique_violation_on_active_name_columns_is_recognized(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into connector_accounts ...',
            [],
            new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: connector_accounts.workspace_id, connector_accounts.connector_definition_id, connector_accounts.active_name_uniqueness_key',
                23000,
            ),
        );

        $this->assertTrue($this->classifier->isActiveNameUniquenessConflict($exception));
    }

    #[Test]
    public function mysql_foreign_key_violation_is_not_recognized_as_name_conflict(): void
    {
        $exception = new QueryException(
            'mysql',
            'insert into connector_accounts ...',
            [],
            new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`db`.`connector_accounts`, CONSTRAINT `connector_accounts_connector_definition_id_foreign` FOREIGN KEY (`connector_definition_id`) REFERENCES `connector_definitions` (`id`))',
                23000,
            ),
        );

        $this->assertFalse($this->classifier->isActiveNameUniquenessConflict($exception));
    }

    #[Test]
    public function sqlite_foreign_key_violation_is_not_recognized_as_name_conflict(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into connector_accounts ...',
            [],
            new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed',
                23000,
            ),
        );

        $this->assertFalse($this->classifier->isActiveNameUniquenessConflict($exception));
    }

    #[Test]
    public function unrelated_unique_constraint_is_not_recognized_as_name_conflict(): void
    {
        $exception = new QueryException(
            'sqlite',
            'insert into users ...',
            [],
            new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email',
                23000,
            ),
        );

        $this->assertFalse($this->classifier->isActiveNameUniquenessConflict($exception));
    }
}
