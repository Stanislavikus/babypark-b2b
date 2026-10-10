<?php

namespace Tests\Unit\Connectors\Integrations;

use App\Support\Connectors\Integrations\ConnectorAccountDefaultNameGenerator;
use Database\Seeders\ConnectorFoundationSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesConnectorAccountFixtures;
use Tests\TestCase;

class ConnectorAccountDefaultNameGeneratorTest extends TestCase
{
    use CreatesConnectorAccountFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ConnectorFoundationSeeder::class);
    }

    #[Test]
    public function first_account_uses_platform_display_name(): void
    {
        $name = app(ConnectorAccountDefaultNameGenerator::class)->generate(
            $this->defaultWorkspace(),
            $this->adobeConnectorDefinition()->id,
            'Adobe Commerce',
        );

        $this->assertSame('Adobe Commerce', $name);
    }

    #[Test]
    public function subsequent_names_use_first_available_suffix_not_naive_count(): void
    {
        $definitionId = $this->adobeConnectorDefinition()->id;
        $workspace = $this->defaultWorkspace();

        $this->createConnectorAccount($workspace, [
            'connector_definition_id' => $definitionId,
            'name' => 'Adobe Commerce',
        ]);
        $this->createConnectorAccount($workspace, [
            'connector_definition_id' => $definitionId,
            'name' => 'Adobe Commerce — 3',
        ]);

        $name = app(ConnectorAccountDefaultNameGenerator::class)->generate(
            $workspace,
            $definitionId,
            'Adobe Commerce',
        );

        $this->assertSame('Adobe Commerce — 2', $name);
    }

    #[Test]
    public function soft_deleted_names_do_not_block_reuse(): void
    {
        $definitionId = $this->adobeConnectorDefinition()->id;
        $workspace = $this->defaultWorkspace();

        $account = $this->createConnectorAccount($workspace, [
            'connector_definition_id' => $definitionId,
            'name' => 'Adobe Commerce',
        ]);
        $account->delete();

        $name = app(ConnectorAccountDefaultNameGenerator::class)->generate(
            $workspace,
            $definitionId,
            'Adobe Commerce',
        );

        $this->assertSame('Adobe Commerce', $name);
    }
}
