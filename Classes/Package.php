<?php
declare(strict_types=1);

namespace Flowpack\Neos\AssetUsage;

/*
 * This file is part of the Flowpack.Neos.AssetUsage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\Neos\AssetUsage\Service\AssetIntegrationService;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Neos\Service\PublishingService;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Media\Domain\Service\AssetService;

class Package extends BasePackage
{

    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(AssetService::class, 'assetRemoved', AssetIntegrationService::class, 'assetRemoved');
        $dispatcher->connect(Node::class, 'nodePropertyChanged', AssetIntegrationService::class, 'nodePropertyChanged');
        $dispatcher->connect(Node::class, 'nodeRemoved', AssetIntegrationService::class, 'nodeRemoved');
        $dispatcher->connect(Node::class, 'nodeAdded', AssetIntegrationService::class, 'nodeAdded');
        $dispatcher->connect(PublishingService::class, 'nodeDiscarded', AssetIntegrationService::class, 'nodeDiscarded');
        $dispatcher->connect(Workspace::class, 'beforeNodePublishing', AssetIntegrationService::class, 'beforeNodePublishing');
        $dispatcher->connect(Workspace::class, 'afterNodePublishing', AssetIntegrationService::class, 'afterNodePublishing');

        // TODO: Monitor "removeProperty" via AOP as it only triggers the `nodeUpdated` signal which is too generic
    }
}
