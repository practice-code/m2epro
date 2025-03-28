<?php

declare(strict_types=1);

namespace Ess\M2ePro\Model\Ebay\Account;

use Ess\M2ePro\Model\Ebay\Account as EbayAccount;
use Ess\M2ePro\Model\Ebay\Account\Issue\ValidTokens;
use Ess\M2ePro\Model\ResourceModel\Ebay\Account as EbayAccountResource;

class Update
{
    /** @var \Ess\M2ePro\Helper\Data\Cache\Permanent */
    private $permanentCacheHelper;
    /** @var \Ess\M2ePro\Model\Ebay\Account\Store\Category\Update */
    private $storeCategoryUpdate;
    /** @var \Ess\M2ePro\Model\Ebay\Connector\DispatcherFactory */
    private $dispatcherFactory;
    /** @var \Ess\M2ePro\Model\Ebay\Account\BuilderFactory */
    private $builderFactory;

    public function __construct(
        \Ess\M2ePro\Helper\Data\Cache\Permanent $permanentCacheHelper,
        \Ess\M2ePro\Model\Ebay\Account\BuilderFactory $builderFactory,
        \Ess\M2ePro\Model\Ebay\Connector\DispatcherFactory $dispatcherFactory,
        \Ess\M2ePro\Model\Ebay\Account\Store\Category\Update $storeCategoryUpdate
    ) {
        $this->builderFactory = $builderFactory;
        $this->dispatcherFactory = $dispatcherFactory;
        $this->storeCategoryUpdate = $storeCategoryUpdate;
        $this->permanentCacheHelper = $permanentCacheHelper;
    }

    public function updateSettings(\Ess\M2ePro\Model\Account $account, array $data): \Ess\M2ePro\Model\Account
    {
        $this->builderFactory->create()->build($account, $data);

        return $account;
    }

    public function updateCredentials(
        \Ess\M2ePro\Model\Account $account,
        string $authCode,
        int $mode
    ): \Ess\M2ePro\Model\Account {
        $responseData = $this->updateOnServer($account, $authCode, $mode);

        $dataForUpdate = [
            EbayAccountResource::COLUMN_EBAY_SITE => $responseData['site'],
            EbayAccountResource::COLUMN_SELL_API_TOKEN_EXPIRED_DATE => $responseData['token_expired_date'],
            EbayAccountResource::COLUMN_USER_ID => $responseData['user_id'],
            EbayAccountResource::COLUMN_IS_TOKEN_EXIST => 1
        ];

        $account->getChildObject()->addData($dataForUpdate);
        $account->getChildObject()->save();

        $this->storeCategoryUpdate->process($account->getChildObject());

        $account->getChildObject()->updateUserPreferences();

        $this->permanentCacheHelper->removeValue(ValidTokens::ACCOUNT_TOKENS_CACHE_KEY);

        return $account;
    }

    private function updateOnServer(\Ess\M2ePro\Model\Account $account, string $authCode, int $mode): array
    {
        /** @var \Ess\M2ePro\Model\Ebay\Connector\Account\Update\Entity $connectorObj */
        $connectorObj = $this->dispatcherFactory
            ->create()
            ->getConnector(
                'account',
                'update',
                'entity',
                [
                    'mode' => $mode == EbayAccount::MODE_PRODUCTION ? 'production' : 'sandbox',
                    'auth_code' => $authCode,
                ],
                null,
                $account
            );

        $connectorObj->process();

        return $connectorObj->getResponseData();
    }
}
