<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions column of the unified log grid: a per-row Details action whose
 * href points at the drill-down controller. The column's JS component
 * (Smaily_Connect/js/grid/columns/log-actions) opens the href in a
 * slide-out modal instead of navigating.
 */
class LogActions extends Column
{
    /**
     * @param array<int|string, mixed> $components
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            $name = (string)$this->getData('name');
            foreach ($dataSource['data']['items'] as &$item) {
                if (!isset($item['log_id'])) {
                    continue;
                }
                $item[$name]['view'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'smaily_connect/log/details',
                        ['log_id' => $item['log_id']]
                    ),
                    'label' => __('Details'),
                ];
            }
        }

        return $dataSource;
    }
}
