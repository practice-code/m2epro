<?php

declare(strict_types=1);

namespace Ess\M2ePro\Helper\Module\Renderer;

class Description
{
    public const IMAGES_MODE_DEFAULT = 0;
    /**
     * Is not supported more. Links to non eBay resources are not allowed due to eBay regulations.
     */
    public const IMAGES_MODE_NEW_WINDOW = 1;
    public const IMAGES_MODE_GALLERY = 2;

    public const IMAGES_QTY_ALL = 0;

    public const LAYOUT_MODE_ROW = 'row';
    public const LAYOUT_MODE_COLUMN = 'column';

    private \Magento\Store\Model\App\Emulation $appEmulation;
    private \Magento\Email\Model\Template\Filter $filter;
    private \Magento\Framework\View\LayoutInterface $layout;

    public function __construct(
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Email\Model\Template\Filter $filter,
        \Magento\Framework\View\LayoutInterface $layout
    ) {
        $this->appEmulation = $appEmulation;
        $this->filter = $filter;
        $this->layout = $layout;
    }

    // ----------------------------------------

    public function parseTemplate($text, \Ess\M2ePro\Model\Magento\Product $magentoProduct)
    {
        // Start store emulation process
        $this->appEmulation->startEnvironmentEmulation(
            $magentoProduct->getStoreId(),
            \Magento\Framework\App\Area::AREA_FRONTEND,
            true
        );
        //--

        $text = $this->parseWithoutMagentoTemplate($text, $magentoProduct);

        // the CMS static block replacement i.e. {{media url=’image.jpg’}}
        $this->filter->setVariables(['product' => $magentoProduct->getProduct()]);
        $text = $this->filter->filter((string)$text);

        //-- Stop store emulation process
        $this->appEmulation->stopEnvironmentEmulation();

        //--

        return $text;
    }

    public function parseWithoutMagentoTemplate(string $text, \Ess\M2ePro\Model\Magento\Product $magentoProduct): string
    {
        $text = $this->insertAttributes($text, $magentoProduct);
        $text = $this->insertImages($text, $magentoProduct);
        $text = $this->insertMediaGalleries($text, $magentoProduct);

        return $text;
    }

    // ----------------------------------------

    private function insertAttributes($text, \Ess\M2ePro\Model\Magento\Product $magentoProduct)
    {
        preg_match_all("/#([A-Za-z_0-9]+?)#/", $text, $matches);

        if (empty($matches[0])) {
            return $text;
        }

        $search = [];
        $replace = [];
        foreach ($matches[1] as $attributeCode) {
            $value = $magentoProduct->getAttributeValue($attributeCode);

            if (!is_array($value) && $value != '') {
                if ($attributeCode == 'weight') {
                    $value = (float)$value;
                } elseif (in_array($attributeCode, ['price', 'special_price'])) {
                    $value = $magentoProduct->getProduct()->getFormattedPrice();
                }
                $search[] = '#' . $attributeCode . '#';
                $replace[] = $value;
            } else {
                $search[] = '#' . $attributeCode . '#';
                $replace[] = '';
            }
        }

        $text = str_replace($search, $replace, $text);

        return $text;
    }

    private function insertImages($text, \Ess\M2ePro\Model\Magento\Product $magentoProduct)
    {
        preg_match_all("/#image\[(.*?)\]#/", $text, $matches);

        if (empty($matches[0])) {
            return $text;
        }

        $mainImage = $magentoProduct->getImage('image');
        $mainImageLink = $mainImage ? $mainImage->getUrl() : '';

        $search = [];
        $replace = [];

        foreach ($matches[0] as $key => $match) {
            $tempImageAttributes = explode(',', $matches[1][$key]);
            $realImageAttributes = [];
            for ($i = 0; $i < 6; $i++) {
                if (!isset($tempImageAttributes[$i])) {
                    $realImageAttributes[$i] = 0;
                } else {
                    $realImageAttributes[$i] = (int)$tempImageAttributes[$i];
                }
            }

            $tempImageLink = $mainImageLink;
            if ($realImageAttributes[5] != 0) {
                $tempImage = $magentoProduct->getGalleryImageByPosition($realImageAttributes[5]);
                $tempImageLink = empty($tempImage) ? '' : $tempImage->getUrl();
            }

            if (!in_array($realImageAttributes[3], [self::IMAGES_MODE_DEFAULT])) {
                $realImageAttributes[3] = self::IMAGES_MODE_DEFAULT;
            }

            $blockObj = $this->layout->createBlock(
                \Ess\M2ePro\Block\Adminhtml\Renderer\Description\Image::class
            );

            $data = [
                'width'        => $realImageAttributes[0],
                'height'       => $realImageAttributes[1],
                'margin'       => $realImageAttributes[2],
                'linked_mode'  => $realImageAttributes[3],
                'watermark'    => $realImageAttributes[4],
                'src'          => $tempImageLink,
                'index_number' => $key,
            ];
            $search[] = $match;
            $replace[] = ($tempImageLink == '')
                ? '' :
                preg_replace('/\s{2,}/', '', $blockObj->addData($data)->toHtml());
        }

        $text = str_replace($search, $replace, $text);

        return $text;
    }

    private function insertMediaGalleries($text, \Ess\M2ePro\Model\Magento\Product $magentoProduct)
    {
        preg_match_all("/#media_gallery\[(.*?)\]#/", $text, $matches);

        if (empty($matches[0])) {
            return $text;
        }

        $search = [];
        $replace = [];

        foreach ($matches[0] as $key => $match) {
            $tempMediaGalleryAttributes = explode(',', $matches[1][$key]);
            $realMediaGalleryAttributes = [];
            for ($i = 0; $i < 8; $i++) {
                if (!isset($tempMediaGalleryAttributes[$i])) {
                    $realMediaGalleryAttributes[$i] = '';
                } else {
                    $realMediaGalleryAttributes[$i] = $tempMediaGalleryAttributes[$i];
                }
            }

            $imagesQty = (int)$realMediaGalleryAttributes[5];
            if ($imagesQty === self::IMAGES_QTY_ALL) {
                $imagesQty = $realMediaGalleryAttributes[3] == self::IMAGES_MODE_GALLERY ? 100 : 25;
            }

            $galleryImagesLinks = [];
            foreach ($magentoProduct->getGalleryImages($imagesQty) as $image) {
                if (!$image->getUrl()) {
                    continue;
                }

                $galleryImagesLinks[] = $image->getUrl();
            }

            if (empty($galleryImagesLinks)) {
                $search = $matches[0];
                $replace = '';
                break;
            }

            if (!in_array($realMediaGalleryAttributes[3], [self::IMAGES_MODE_DEFAULT, self::IMAGES_MODE_GALLERY])) {
                $realMediaGalleryAttributes[3] = self::IMAGES_MODE_GALLERY;
            }

            if (!in_array($realMediaGalleryAttributes[4], [self::LAYOUT_MODE_ROW, self::LAYOUT_MODE_COLUMN])) {
                $realMediaGalleryAttributes[4] = self::LAYOUT_MODE_ROW;
            }

            $data = [
                'width'        => (int)$realMediaGalleryAttributes[0],
                'height'       => (int)$realMediaGalleryAttributes[1],
                'margin'       => (int)$realMediaGalleryAttributes[2],
                'linked_mode'  => (int)$realMediaGalleryAttributes[3],
                'layout'       => $realMediaGalleryAttributes[4],
                'gallery_hint' => trim($realMediaGalleryAttributes[6], '"'),
                'watermark'    => (int)$realMediaGalleryAttributes[7],
                'images'       => $galleryImagesLinks,
                'index_number' => $key,
            ];

            $blockObj = $this->layout->createBlock(
                \Ess\M2ePro\Block\Adminhtml\Renderer\Description\Gallery::class
            );
            $tempHtml = $blockObj->addData($data)->toHtml();

            $search[] = $match;
            $replace[] = preg_replace('/\s{2,}/', '', $tempHtml);
        }

        return str_replace($search, $replace, $text);
    }
}
