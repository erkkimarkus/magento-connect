<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Queue row status filter options for admin grids.
 */
class QueueStatusOptions implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'pending', 'label' => __('Pending')],
            ['value' => 'sending', 'label' => __('Sending')],
            ['value' => 'sent', 'label' => __('Sent')],
            ['value' => 'failed', 'label' => __('Failed')],
        ];
    }
}
