<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\Data\OptionSourceInterface;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Queue row status filter options for admin grids. "Withdrawn" is not a
 * stored status but the one the Log derives for a row the store called back
 * (PRO-2454) — the grid's status column reads and filters on the same
 * derived value.
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
            ['value' => Collection::STATUS_WITHDRAWN, 'label' => __('Withdrawn')],
        ];
    }
}
