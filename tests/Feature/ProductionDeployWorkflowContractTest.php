<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionDeployWorkflowContractTest extends TestCase
{
    #[Test]
    public function production_deploy_workflow_is_manual_only_and_preserves_ssh_deploy_script(): void
    {
        $workflowPath = base_path('.github/workflows/deploy.yml');
        $content = File::get($workflowPath);

        $this->assertStringContainsString('workflow_dispatch', $content);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*push\s*:\s*$/m',
            $content,
            'Production deploy must not declare an automatic push trigger.',
        );
        $this->assertStringContainsString('appleboy/ssh-action@v1.0.3', $content);
        $this->assertStringContainsString('./deploy.sh', $content);
    }
}
