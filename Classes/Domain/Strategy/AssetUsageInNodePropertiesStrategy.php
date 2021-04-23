<?php
declare(strict_types=1);

namespace Flowpack\Neos\AssetUsage\Domain\Strategy;

/*
 * This file is part of the Flowpack.Neos.AssetUsage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\EntityUsage\Service\EntityUsageService;
use Neos\ContentRepository\Domain\Service\NodeService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Strategy\AssetUsageStrategyInterface;
use Neos\Neos\Domain\Model\Dto\AssetUsageInNodeProperties;
use Neos\Utility\Exception\PropertyNotAccessibleException;

/**
 * @Flow\Scope("singleton")
 */
class AssetUsageInNodePropertiesStrategy implements AssetUsageStrategyInterface
{

    /**
     * @Flow\Inject(name="Flowpack.Neos.AssetUsage:AssetUsageService")
     * @var EntityUsageService
     */
    protected $assetUsageService;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    public function getLabel(): string
    {
        return 'Neos documents and content (new awesome instant strategy)';
    }

    /**
     * @param AssetInterface $asset
     * @return AssetUsageInNodeProperties[]
     * @throws PropertyNotAccessibleException
     */
    public function getUsageReferences(AssetInterface $asset): array
    {
        $entityId = $this->persistenceManager->getIdentifierByObject($asset);
        $usages = $this->assetUsageService->getUsages($entityId);
        $result = [];
        $fallbackMetaData = ['nodeIdentifier' => null, 'workspace' => null, 'dimensions' => [], 'nodeType' => null];
        foreach ($usages as $usage) {
            [
                'nodeIdentifier' => $nodeIdentifier,
                'dimensions' => $dimensions,
                'workspace' => $workspace,
                'nodeType' => $nodeType,
            ] = array_merge($fallbackMetaData, $usage->getMetadata());

            $result[] = new AssetUsageInNodeProperties(
                $asset,
                $nodeIdentifier,
                $workspace,
                json_decode($dimensions, true),
                $nodeType
            );
        }
        return $result;
    }

    /**
     * @param AssetInterface $asset
     * @return bool
     * @throws PropertyNotAccessibleException
     */
    public function isInUse(AssetInterface $asset): bool
    {
        $entityId = $this->persistenceManager->getIdentifierByObject($asset);
        return $this->assetUsageService->isInUse($entityId);
    }

    /**
     * @param AssetInterface $asset
     * @return int
     * @throws PropertyNotAccessibleException
     */
    public function getUsageCount(AssetInterface $asset): int
    {
        $entityId = $this->persistenceManager->getIdentifierByObject($asset);
        return count($this->assetUsageService->getUsages($entityId));
    }
}
