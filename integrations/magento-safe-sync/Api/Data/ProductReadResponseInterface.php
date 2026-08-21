<?php

declare(strict_types=1);

namespace B2BPlatform\MagentoSafeSync\Api\Data;

interface ProductReadResponseInterface
{
    public const LOGICAL_ENTITY_ID = 'logical_entity_id';
    public const SKU = 'sku';
    public const TYPE_ID = 'type_id';
    public const NAME = 'name';

    public function getLogicalEntityId(): int;

    public function setLogicalEntityId(int $logicalEntityId): self;

    public function getSku(): string;

    public function setSku(string $sku): self;

    public function getTypeId(): string;

    public function setTypeId(string $typeId): self;

    public function getName(): string;

    public function setName(string $name): self;
}
