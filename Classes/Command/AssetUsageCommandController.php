<?php
declare(strict_types=1);

namespace Flowpack\Neos\AssetUsage\Command;

/*
 * This file is part of the Flowpack.Neos.AssetUsage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\EntityUsage\EntityUsageInterface;
use Flowpack\EntityUsage\Service\EntityUsageService;
use Flowpack\Neos\AssetUsage\Service\AssetIntegrationService;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class AssetUsageCommandController extends CommandController
{
    /**
     * @Flow\Inject(name="Flowpack.Neos.AssetUsage:AssetUsageService")
     * @var EntityUsageService
     */
    protected $assetUsageService;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var AssetIntegrationService
     */
    protected $assetIntegrationService;

    /**
     * @Flow\Inject(name="Flowpack.Neos.AssetUsage:Logger")
     * @var LoggerInterface
     */
    protected $assetUsageLogger;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    public function unregisterUsageCommand(string $usageId, string $entityId): void
    {
        if ($usageId && $entityId) {
            $this->assetUsageService->unregisterUsage($usageId, $entityId);
        }
    }

    public function findAllCommand(): void
    {
        $rows = [];
        foreach ($this->assetUsageService->getAllUsages() as $entityUsage) {
            /** @var AssetInterface $asset */
            $asset = $this->assetRepository->findByIdentifier($entityUsage->getEntityId());
            $rows[] = [
                $asset->getResource()->getFilename(),
                $entityUsage->getMetadata()['nodeIdentifier'] ?? 'n/a',
                $entityUsage->getMetadata()['workspace'] ?? 'n/a',
                json_encode($entityUsage->getMetadata()['dimensions']) ?? 'n/a',
                $entityUsage->getMetadata()['nodeType'] ?? 'n/a',
            ];
        }
        $this->output->outputTable($rows, ['Filename', 'Node', 'Workspace', 'Dimensions', 'NodeType']);
    }

    public function updateCommand(): void
    {
        $this->outputLine('--------------------------');
        $this->outputLine('Updating asset usage index');
        $this->outputLine('--------------------------');
        $this->outputLine('');

        $this->assetUsageLogger->info('Starting updating asset usage index');

        $allUsages = $this->assetUsageService->getAllUsages()->toArray();
        $knownUsages = array_reduce($allUsages,
            static function ($carry, EntityUsageInterface $assetUsage) {
                $carry[$assetUsage->getUsageId()][$assetUsage->getEntityId()] = $assetUsage;
                return $carry;
            }, []);
        $unconfirmedUsages = $knownUsages;

        $nodeIterator = $this->nodeDataRepository->findAllIterator();

        $rowsAdded = [];

        $errors = [];

        $this->output->progressStart($this->nodeDataRepository->countAll());

        /** @var NodeData $nodeData */
        foreach ($this->nodeDataRepository->iterate($nodeIterator) as $nodeData) {
            try {
                $nodeType = $nodeData->getNodeType();
            } catch (NodeTypeNotFoundException $e) {
                continue;
            }
            $assetProperties = $this->assetIntegrationService->getAssetPropertyNamesForNodeType($nodeType);

            if (!$assetProperties) {
                continue;
            }

            foreach ($assetProperties as $assetPropertyName) {
                if (!$nodeData->hasProperty($assetPropertyName)) {
                    continue;
                }

                /** @var array $assetIds */
                $assetIds = $nodeData->getProperty($assetPropertyName);

                if (!$assetIds) {
                    continue;
                }

                if (!is_array($assetIds)) {
                    $assetIds = [$assetIds];
                }

                foreach ($assetIds as $assetId) {
                    if ($assetId instanceof AssetInterface) {
                        if ($assetId instanceof AssetVariantInterface) {
                            try {
                                $assetId = $assetId->getOriginalAsset();
                            } catch (\Exception $e) {
                                $errors[] = [
                                    'node identifier' => $nodeData->getIdentifier(),
                                    'property' => $assetPropertyName,
                                    'exception message' => $e->getMessage(),
                                ];
                                continue;
                            }
                        }
                        $assetId = $this->persistenceManager->getIdentifierByObject($assetId);
                    }

                    $nodeIdentifier = $nodeData->getIdentifier();
                    $dimensionValues = $nodeData->getDimensionValues();
                    $workspace = $nodeData->getWorkspace()->getName();
                    $nodeTypeName = $nodeData->getNodeType()->getName();

                    $usageId = $this->assetIntegrationService->getUsageId(
                        $nodeIdentifier,
                        $dimensionValues,
                        $workspace
                    );

                    if (isset($knownUsages[$usageId][$assetId])) {
                        unset($unconfirmedUsages[$usageId][$assetId]);
                        continue;
                    }

                    $this->assetIntegrationService->registerUsage(
                        $nodeIdentifier,
                        $nodeTypeName,
                        $dimensionValues,
                        $workspace,
                        $assetId
                    );

                    $rowsAdded[] = [
                        $usageId,
                        $assetId,
                        $nodeIdentifier,
                        json_encode($dimensionValues),
                        $workspace,
                        $nodeTypeName
                    ];
                    $this->assetUsageLogger->info(sprintf(
                        'Added missing usage for asset %s in node %s',
                        $assetId,
                        $nodeIdentifier
                    ));
                }
            }
            $this->output->progressAdvance();
        }
        $this->output->progressFinish();
        $this->outputLine();

        if ($rowsAdded) {
            $this->outputLine('Added usages');
            $this->output->outputTable($rowsAdded, [
                'UsageId',
                'AssetId',
                'NodeIdentifier',
                'Dimensions',
                'Workspace',
                'NodeType',
            ]);
        } else {
            $this->outputLine('No usages were added');
        }

        $rowsRemoved = [];
        foreach ($unconfirmedUsages as $usageId => $assets) {
            foreach (array_keys($assets) as $assetId) {
                $this->assetUsageService->unregisterUsage($usageId, $assetId);
                $usage = $knownUsages[$usageId][$assetId]->getMetadata();
                $rowsRemoved[] = [
                    $usageId,
                    $assetId,
                    $usage['nodeIdentifier'] ?? 'n/a',
                    $usage['dimensions'] ?? 'n/a',
                    $usage['workspace'] ?? 'n/a',
                    $usage['nodeType'] ?? 'n/a',
                ];
                $this->assetUsageLogger->warning(sprintf(
                    'Removed usage for asset %s in node %s - this could indicate a problem',
                    $assetId,
                    $nodeIdentifier
                ));
            }
        }

        if ($rowsRemoved) {
            $this->outputLine('Removed usages');
            $this->output->outputTable($rowsRemoved, [
                'UsageId',
                'AssetId',
                'NodeIdentifier',
                'Dimensions',
                'Workspace',
                'NodeType',
            ]);
        } else {
            $this->outputLine('No usages were removed');
        }

        if ($errors) {
            $this->outputLine('Some asset reference errors occured. Please check the asset references in the database.');
            $this->output->outputTable($errors, [
                'NodeIdentifier',
                'Property',
                'Exception message',
            ]);
        }

        $this->assetUsageLogger->info(sprintf('Finished updating asset usage index. Added %d usages. Removed %d usages.', count($rowsAdded), count($rowsRemoved)));
    }
}
