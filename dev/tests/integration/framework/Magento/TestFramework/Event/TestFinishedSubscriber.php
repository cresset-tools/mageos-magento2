<?php
/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\TestFramework\Event;

use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Finished;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

class TestFinishedSubscriber implements FinishedSubscriber
{
    /**
     * @param ExecutionState $executionState
     */
    public function __construct(private readonly ExecutionState $executionState)
    {
    }

    /**
     * Test finished Subscriber
     *
     * @param Finished $event
     */
    public function notify(Finished $event): void
    {
        $className = $event->test()->className();
        $methodName = $event->test()->methodName();

        $objectManager = Bootstrap::getObjectManager();
        /** @var TestCase $testObj */
        $testObj = $objectManager->create($className, ['name' => $methodName]);
        $phpUnit = $objectManager->create(PhpUnit::class);
        try {
            $phpUnit->endTest($testObj, 0);
        } catch (\Throwable $exception) {
            // A throwable escaping a test-finished subscriber (typically
            // "Unable to revert fixture") is treated by PHPUnit as an
            // internal error and aborts the entire test run — one broken
            // rollback discards every remaining test in the process. The
            // test itself has already been reported, so the best remaining
            // attribution is a runner warning; emit it and keep the run
            // alive.
            \PHPUnit\Event\Facade::emitter()->testRunnerTriggeredPhpunitWarning(
                sprintf(
                    "%s::%s produced a failure during cleanup:\n%s",
                    $className,
                    $methodName,
                    $exception->getMessage()
                )
            );
            // The failed cleanup may leave the session poisoned — a test
            // that died inside LOCK TABLES (e.g. Framework\Backup) keeps
            // its locks, every later DDL in any connection blocks on the
            // metadata lock, and the run hangs instead of finishing.
            try {
                $objectManager->get(\Magento\Framework\App\ResourceConnection::class)
                    ->getConnection()
                    ->query('UNLOCK TABLES');
            } catch (\Throwable $cleanupException) {
                // best effort — a broken connection will be re-established
            }
        }

        $this->executionState->clearTestData($testObj->toString());
        Magento::setCurrentEventObject(null);
        Magento::setTestPrepared(false);
    }
}
