<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product fields included in abandoned cart automation payloads
 * (product_name_1..10 etc., legacy-compatible field names).
 */
class AbandonedFields implements OptionSourceInterface
{
    public const FIELD_NAME = 'name';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_IMAGE_URL = 'image_url';
    public const FIELD_SKU = 'sku';
    public const FIELD_QUANTITY = 'quantity';
    public const FIELD_PRICE = 'price';
    public const FIELD_BASE_PRICE = 'base_price';

    public const SUPPORTED_FIELDS = [
        self::FIELD_NAME,
        self::FIELD_DESCRIPTION,
        self::FIELD_IMAGE_URL,
        self::FIELD_SKU,
        self::FIELD_QUANTITY,
        self::FIELD_PRICE,
        self::FIELD_BASE_PRICE,
    ];

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::FIELD_NAME, 'label' => __('Product Name')],
            ['value' => self::FIELD_DESCRIPTION, 'label' => __('Product Description')],
            ['value' => self::FIELD_IMAGE_URL, 'label' => __('Product Image URL')],
            ['value' => self::FIELD_SKU, 'label' => __('Product SKU')],
            ['value' => self::FIELD_QUANTITY, 'label' => __('Product Quantity')],
            ['value' => self::FIELD_PRICE, 'label' => __('Product Price')],
            ['value' => self::FIELD_BASE_PRICE, 'label' => __('Product Base Price')],
        ];
    }
}
