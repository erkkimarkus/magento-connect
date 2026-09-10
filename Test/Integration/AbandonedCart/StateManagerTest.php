<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\AbandonedCart;

use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The tracker's own gate against the real table. Contacts are synthetic.
 */
class StateManagerTest extends IntegrationTestCase
{
    private const SUBJECT = 'cart-state-1@example.test';

    private StateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateManager = $this->objectManager->create(StateManager::class);
    }

    /**
     * PRO-2467: the erasure leaves a tombstone because the module may not
     * touch the core `quote` table. The cron's gate must still report that
     * quote as handled — otherwise a quote that is still active and idle past
     * the cutoff looks untracked to the next sweep and is mailed to the
     * address just erased.
     */
    public function testATombstonedQuoteStaysOutOfTheCronsCandidateSet(): void
    {
        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        self::assertSame(1, $this->stateManager->anonymizeForEmail(self::SUBJECT));

        self::assertSame(
            [11],
            $this->stateManager->filterAlreadyHandled([11]),
            'The tombstoned quote never reaches markMailed()/dispatchAutomation() again'
        );
    }
}
