<?php

namespace Tests\Unit;

use App\Enums\PriceListStatus;
use App\Exceptions\Pricing\InvalidCustomerBatchException;
use App\Exceptions\Pricing\InvalidPriceListAssignmentException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Workspace;
use App\Services\Pricing\CustomerPriceListAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPricingFixtures;
use Tests\TestCase;

class CustomerPriceListAssignmentServiceTest extends TestCase
{
    use CreatesPricingFixtures;
    use RefreshDatabase;

    private CustomerPriceListAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CustomerPriceListAssignmentService::class);
    }

    public function test_validate_target_rejects_missing_inactive_and_workspace_default(): void
    {
        $workspace = $this->defaultWorkspace();
        $defaultList = PriceList::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->firstOrFail();
        $inactive = $this->createPriceList($workspace, status: PriceListStatus::Inactive);

        try {
            $this->service->validateTarget($workspace->id, (string) Str::uuid());
            $this->fail('Expected not found rejection.');
        } catch (InvalidPriceListAssignmentException $exception) {
            $this->assertSame(InvalidPriceListAssignmentException::REASON_NOT_FOUND, $exception->reason);
        }

        try {
            $this->service->validateTarget($workspace->id, $inactive->id);
            $this->fail('Expected inactive rejection.');
        } catch (InvalidPriceListAssignmentException $exception) {
            $this->assertSame(InvalidPriceListAssignmentException::REASON_INACTIVE, $exception->reason);
        }

        try {
            $this->service->validateTarget($workspace->id, $defaultList->id);
            $this->fail('Expected workspace default rejection.');
        } catch (InvalidPriceListAssignmentException $exception) {
            $this->assertSame(InvalidPriceListAssignmentException::REASON_WORKSPACE_DEFAULT, $exception->reason);
        }
    }

    public function test_validate_target_rejects_cross_workspace_assignment(): void
    {
        $workspace = $this->defaultWorkspace();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other Workspace',
            'is_default' => false,
        ]);
        $foreignList = PriceList::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Foreign List',
            'currency' => 'UAH',
            'is_default' => true,
            'priority' => 0,
            'status' => PriceListStatus::Active,
        ]);

        try {
            $this->service->validateTarget($workspace->id, $foreignList->id);
            $this->fail('Expected cross-workspace rejection.');
        } catch (InvalidPriceListAssignmentException $exception) {
            $this->assertSame(InvalidPriceListAssignmentException::REASON_CROSS_WORKSPACE, $exception->reason);
        }
    }

    public function test_preview_count_semantics_for_mixed_scenario(): void
    {
        $workspace = $this->defaultWorkspace();
        $target = $this->createPriceList($workspace, isDefault: false);
        $other = $this->createPriceList($workspace, isDefault: false);

        $unchanged = $this->createCustomer($workspace);
        $unchanged->update(['default_price_list_id' => $target->id]);

        $replace = $this->createCustomer($workspace);
        $replace->update(['default_price_list_id' => $other->id]);

        $clear = $this->createCustomer($workspace);
        $clear->update(['default_price_list_id' => $other->id]);

        $alreadyDefault = $this->createCustomer($workspace);
        $alreadyDefault->update(['default_price_list_id' => null]);

        $preview = $this->service->preview(
            $workspace->id,
            [$unchanged->id, $replace->id, $clear->id, $alreadyDefault->id],
            null,
        );

        $this->assertSame(4, $preview->selectedCount);
        $this->assertSame(1, $preview->unchangedCount);
        $this->assertSame(3, $preview->changedCount);
        $this->assertSame(0, $preview->replacedCount);
        $this->assertSame(3, $preview->clearedCount);

        $assignPreview = $this->service->preview(
            $workspace->id,
            [$unchanged->id, $replace->id, $clear->id, $alreadyDefault->id],
            $target->id,
        );

        $this->assertSame(4, $assignPreview->selectedCount);
        $this->assertSame(1, $assignPreview->unchangedCount);
        $this->assertSame(3, $assignPreview->changedCount);
        $this->assertSame(2, $assignPreview->replacedCount);
        $this->assertSame(0, $assignPreview->clearedCount);
    }

    public function test_preview_throws_for_missing_or_cross_workspace_customer(): void
    {
        $workspace = $this->defaultWorkspace();
        $target = $this->createPriceList($workspace, isDefault: false);
        $customer = $this->createCustomer($workspace);

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Foreign',
            'is_default' => false,
        ]);
        $foreignCustomer = Customer::withoutWorkspaceScope()->create([
            'workspace_id' => $otherWorkspace->id,
            'onec_guid' => (string) Str::uuid(),
            'name' => 'Foreign Customer',
            'login' => 'foreign-'.Str::random(6),
            'password' => 'password',
            'is_active' => true,
        ]);

        try {
            $this->service->preview($workspace->id, [999999], $target->id);
            $this->fail('Expected missing customer exception.');
        } catch (InvalidCustomerBatchException $exception) {
            $this->assertSame(InvalidCustomerBatchException::REASON_NOT_FOUND, $exception->reason);
        }

        try {
            $this->service->preview($workspace->id, [$foreignCustomer->id], $target->id);
            $this->fail('Expected cross-workspace customer exception.');
        } catch (InvalidCustomerBatchException $exception) {
            $this->assertSame(InvalidCustomerBatchException::REASON_CROSS_WORKSPACE, $exception->reason);
        }

        $this->assertSame($customer->id, $customer->fresh()->id);
    }

    public function test_apply_updates_only_changed_records_in_transaction(): void
    {
        $workspace = $this->defaultWorkspace();
        $target = $this->createPriceList($workspace, isDefault: false);
        $other = $this->createPriceList($workspace, isDefault: false);

        $unchanged = $this->createCustomer($workspace);
        $unchanged->update(['default_price_list_id' => $target->id]);

        $replace = $this->createCustomer($workspace);
        $replace->update(['default_price_list_id' => $other->id]);

        $result = $this->service->apply(
            $workspace->id,
            [$unchanged->id, $replace->id],
            $target->id,
        );

        $this->assertSame(2, $result->selectedCount);
        $this->assertSame(1, $result->updatedCount);
        $this->assertSame(1, $result->unchangedCount);
        $this->assertSame(1, $result->replacedCount);
        $this->assertSame($target->id, $replace->fresh()->default_price_list_id);
        $this->assertSame($target->id, $unchanged->fresh()->default_price_list_id);
    }
}
