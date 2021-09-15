<?php
declare(strict_types=1);

namespace Flowpack\Neos\AssetUsage\Service;

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
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Controller\CreateContentContextTrait;

/**
 * Monitors changes to assets which are used in Neos CR nodes
 *
 * @Flow\Scope("singleton")
 */
final class AssetIntegrationService
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject(name="Flowpack.Neos.AssetUsage:AssetUsageService")
     * @var EntityUsageService
     */
    protected $assetUsageService;

    private $assetPropertiesByNodeType = [];

    public function assetRemoved(AssetInterface $asset): void
    {
        $this->unregisterUsageForAsset($asset);
    }

    private function unregisterUsageForAsset(AssetInterface $asset): void
    {
        $assetId = $this->resolveOriginalAssetIdentifier($asset);
        $this->assetUsageService->unregisterUsagesByEntity($assetId);
    }

    /**
     * Returns the assets identifier or in case of variants the original assets identifier
     *
     * @param AssetInterface|ImageInterface $asset
     * @return string|null
     */
    private function resolveOriginalAssetIdentifier($asset): ?string
    {
        if ($asset instanceof AssetVariantInterface) {
            $asset = $asset->getOriginalAsset();
        }
        return $this->persistenceManager->getIdentifierByObject($asset);
    }

    public function afterNodePublishing(NodeInterface $node): void
    {
        // Skip usage in removed nodes
        if ($node->isRemoved()) {
            return;
        }
        foreach ($this->getAssetPropertyNamesForNodeType($node->getNodeType()) as $propertyName) {
            if (!$node->hasProperty($propertyName)) {
                return;
            }
            $propertyValue = $node->getProperty($propertyName);
            if (!$propertyValue) {
                return;
            }
            $this->registerUsageInNode($node, $propertyValue);
        }
    }

    public function getAssetPropertyNamesForNodeType(NodeType $nodeType): array
    {
        if (array_key_exists($nodeType->getName(), $this->assetPropertiesByNodeType)) {
            return $this->assetPropertiesByNodeType[$nodeType->getName()];
        }

        $propertyNames = array_reduce(array_keys($nodeType->getProperties()),
            function ($carry, $propertyName) use ($nodeType) {
                if ($this->propertyTypeCanBeRegistered($nodeType->getPropertyType($propertyName))) {
                    $carry[] = $propertyName;
                }
                return $carry;
            }, []);

        $this->assetPropertiesByNodeType[$nodeType->getName()] = $propertyNames;

        return $propertyNames;
    }

    private function propertyTypeCanBeRegistered(string $propertyType): bool
    {
        return $propertyType === Asset::class
            || $propertyType === AssetInterface::class
            || $propertyType === ImageInterface::class
            || $propertyType === 'array<' . Asset::class . '>';
    }

    /**
     * @param NodeInterface $node
     * @param AssetInterface|AssetInterface[] $propertyValue
     */
    private function registerUsageInNode(
        NodeInterface $node,
        $propertyValue
    ): void {
        $assets = is_array($propertyValue) ? $propertyValue : [$propertyValue];

        foreach ($assets as $asset) {
            $this->registerUsage(
                $node->getIdentifier(),
                $node->getNodeType()->getName(),
                $node->getDimensions(),
                $node->getWorkspace()->getName(),
                $asset
            );
        }
    }

    /**
     * @param string $nodeIdentifier
     * @param string $nodeTypeName
     * @param array $dimensionValues
     * @param string $workspaceName
     * @param $asset
     * @return AssetInterface|string|null
     */
    public function registerUsage(
        string $nodeIdentifier,
        string $nodeTypeName,
        array $dimensionValues,
        string $workspaceName,
        $asset
    ): ?string {
        if ($asset instanceof AssetInterface || $asset instanceof ImageInterface) {
            $assetId = $this->resolveOriginalAssetIdentifier($asset);
        } else {
            $assetId = $asset;
        }
        $usageId = $this->getUsageId($nodeIdentifier, $dimensionValues, $workspaceName);
        $this->assetUsageService->registerUsage(
            $usageId,
            $assetId,
            [
                'nodeIdentifier' => $nodeIdentifier,
                'workspace' => $workspaceName,
                'dimensions' => json_encode($dimensionValues),
                'nodeType' => $nodeTypeName,
            ]
        );
        return $usageId;
    }

    public function getUsageId(string $identifier, array $dimensionValues, string $workspaceName): string
    {
        return md5($identifier . '|' . json_encode($dimensionValues) . '|' . $workspaceName);
    }

    public function beforeNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        // Check if there is a node that will be overwritten during the publish
        $contentContext = $this->createContentContext($targetWorkspace->getName(), $node->getDimensions());
        $targetNode = $contentContext->getNodeByIdentifier($node->getIdentifier());

        foreach ($this->getAssetPropertyNamesForNodeType($node->getNodeType()) as $propertyName) {
            if (!$node->hasProperty($propertyName)) {
                return;
            }
            $propertyValue = $node->getProperty($propertyName);
            if (!$propertyValue) {
                return;
            }
            // Unregister the asset stored in the target node, the assets will be registered again after publishing
            if ($targetNode && $targetNode->hasProperty($propertyName)) {
                $targetPropertyValue = $targetNode->getProperty($propertyName);
                $this->unregisterUsageInNode($targetNode, $targetPropertyValue, false);
            }
            $this->unregisterUsageInNode($node, $propertyValue, false);
        }
    }

    public function unregisterUsageInNode(
        NodeInterface $node,
        $propertyValue,
        $checkAllReferences = true
    ): void {
        $assets = is_array($propertyValue) ? $propertyValue : [$propertyValue];
        $assetProperties = $this->getAssetPropertyNamesForNodeType($node->getNodeType());

        // Check whether we can remove the usage. An asset might be referenced twice by the same node
        $references = null;
        if ($checkAllReferences && count($assetProperties) > 1) {
            $references = array_reduce($assetProperties, function (array $carry, string $propertyName) use ($node) {
                if (!$node->hasProperty($propertyName)) {
                    return $carry;
                }
                $referencedAssets = $node->getProperty($propertyName);
                if (!$referencedAssets) {
                    return $carry;
                }
                // Handle single and multiple assets in one property
                if (!is_array($referencedAssets)) {
                    $referencedAssets = [$referencedAssets];
                }
                foreach ($referencedAssets as $referencedAsset) {
                    $referenceId = $this->resolveOriginalAssetIdentifier($referencedAsset);
                    $carry[$referenceId] = ($carry[$referenceId] ?? 0) + 1;
                }
                return $carry;
            }, []);
        }

        foreach ($assets as $asset) {
            $assetId = $this->resolveOriginalAssetIdentifier($asset);
            // Remove the usage only if it will not be used anymore
            if (!$checkAllReferences ||
                !$references ||
                !array_key_exists($assetId, $references) ||
                $references[$assetId] === 1
            ) {
                $this->assetUsageService->unregisterUsage(
                    $this->getUsageId($node->getIdentifier(), $node->getDimensions(),
                        $node->getWorkspace()->getName()
                    ), $assetId);
            }
        }
    }

    public function nodeDiscarded(NodeInterface $node): void
    {
        $this->nodeRemoved($node);
    }

    public function nodeRemoved(NodeInterface $node): void
    {
        foreach ($this->getAssetPropertyNamesForNodeType($node->getNodeType()) as $propertyName) {
            if (!$node->hasProperty($propertyName)) {
                return;
            }
            $propertyValue = $node->getProperty($propertyName);
            if ($propertyValue) {
                $this->unregisterUsageInNode($node, $propertyValue, false);
            }
        }
    }

    public function nodeAdded(NodeInterface $node): void
    {
        foreach ($this->getAssetPropertyNamesForNodeType($node->getNodeType()) as $propertyName) {
            if (!$node->hasProperty($propertyName)) {
                return;
            }
            $propertyValue = $node->getProperty($propertyName);
            if ($propertyValue) {
                $this->registerUsageInNode($node, $propertyValue);
            }
        }
    }

    /**
     * @param NodeInterface $node
     * @param string $propertyName
     * @param AssetInterface|AssetInterface[] $oldValue
     * @param AssetInterface|AssetInterface[] $newValue
     */
    public function nodePropertyChanged(NodeInterface $node, string $propertyName, $oldValue, $newValue): void
    {
        if ($oldValue === $newValue || !$this->propertyTypeCanBeRegistered($node->getNodeType()->getPropertyType($propertyName))) {
            return;
        }

        if ($oldValue) {
            $this->unregisterUsageInNode($node, $oldValue);
        }

        if ($newValue) {
            $this->registerUsageInNode($node, $newValue);
        }
    }
}
