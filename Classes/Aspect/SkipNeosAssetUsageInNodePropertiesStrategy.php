<?php
declare(strict_types=1);

namespace Flowpack\Neos\AssetUsage\Aspect;

/*
 * This file is part of the Flowpack.Neos.AssetUsage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * This aspect disables the usage reference calculation for the asset usage strategy on Neos.Neos
 * in favor of the strategy contained in this package which is much faster.
 * Currently it's not possible to disable a strategy via Settings therefore we need this aspect.
 *
 * @Flow\Aspect
 */
class SkipNeosAssetUsageInNodePropertiesStrategy
{
    /**
     * @Flow\Around("method(Neos\Neos\Domain\Strategy\AssetUsageInNodePropertiesStrategy->getUsageReferences())")
     * @param JoinPointInterface $joinPoint
     * @return array
     */
    public function getUsageReferences(JoinPointInterface  $joinPoint): array
    {
        return [];
    }
}
