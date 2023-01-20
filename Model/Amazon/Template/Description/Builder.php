<?php

/**
 * @author     M2E Pro Developers Team
 * @copyright  M2E LTD
 * @license    Commercial use is forbidden
 */

namespace Ess\M2ePro\Model\Amazon\Template\Description;

class Builder extends \Ess\M2ePro\Model\ActiveRecord\AbstractBuilder
{
    protected function prepareData()
    {
        $data = [];

        $defaultData = $this->getDefaultData();
        unset($defaultData['id']);
        $keys = array_keys($defaultData);

        foreach ($keys as $key) {
            isset($this->rawData[$key]) && $data[$key] = $this->rawData[$key];
        }

        $data['title'] = strip_tags($data['title']);

        return $data;
    }

    public function getDefaultData()
    {
        return [
            'id' => '',
            'title' => '',
            'marketplace_id' => '',

            'is_new_asin_accepted' => 0,

            'category_path' => '',
            'product_data_nick' => '',
            'browsenode_id' => '',
        ];
    }
}
