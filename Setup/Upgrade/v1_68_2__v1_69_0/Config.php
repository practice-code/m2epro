<?php

namespace Ess\M2ePro\Setup\Upgrade\v1_68_2__v1_69_0;

use Ess\M2ePro\Model\Setup\Upgrade\Entity\AbstractConfig;

class Config extends AbstractConfig
{
    public function getFeaturesList(): array
    {
        return [
            '@y24_m10/EbayAccountAddSiteColumn',
        ];
    }
}
