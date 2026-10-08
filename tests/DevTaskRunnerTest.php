<?php

class DevTaskRunnerTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function setUp(): void
    {
        parent::setUp();

        Config::inst()->update('DevTaskRunMonitor', 'cancel_check_interval', 0);
        Config::inst()->update('DevTaskRunMonitor', 'output_save_interval', 0);
        DevTaskRunnerTest_Task::reset();
    }

    public function testClaimSucceedsOnlyOnce()
    {
        $run = $this->createRun('Queued');
        $copy = DevTaskRun::get()->byID($run->ID);

        $this->assertTrue($run->claim('pod-a'));
        $this->assertFalse($copy->claim('pod-b'), 'A second process must not claim the same run');

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Running', $saved->Status);
        $this->assertEquals('pod-a', $saved->Host);
        $this->assertNotEmpty($saved->StartDate);
    }

    public function testClaimFailsWhenNotQueued()
    {
        foreach (['Draft', 'Running', 'Finished', 'Error', 'Cancelled'] as $status) {
            $this->assertFalse($this->createRun($status)->claim('pod-a'), "Claimed a $status run");
        }
    }

    public function testProcessRunsQueuedTaskAndSavesOutput()
    {
        $run = $this->createRun('Queued', 'name=world');

        $this->process();

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Finished', $saved->Status);
        $this->assertEquals('hello world', $saved->Output);
        $this->assertEquals((string) gethostname(), $saved->Host);
        $this->assertNotEmpty($saved->FinishDate);
        $this->assertNull($saved->FailureReason);
    }

    public function testProcessSkipsRunsClaimedByAnotherProcess()
    {
        $claimed = $this->createRun('Queued');
        $claimed->claim('another-pod');
        $queued = $this->createRun('Queued');

        $this->process();

        $this->assertEquals([$queued->ID], DevTaskRunnerTest_Task::$ranIds);
        $this->assertEquals('Running', DevTaskRun::get()->byID($claimed->ID)->Status);
    }

    public function testProcessRunsAtMostMaxTasks()
    {
        for ($i = 0; $i < 4; $i++) {
            $this->createRun('Queued');
        }

        $this->process();

        $this->assertCount(3, DevTaskRunnerTest_Task::$ranIds);
        $this->assertEquals(1, DevTaskRun::get()->filter('Status', 'Queued')->count());
    }

    public function testNextTaskIsClaimedBeforeItRuns()
    {
        $queued = $this->createRun('Queued');
        $running = $this->createRun('Queued');
        $running->claim('another-pod');

        $cron = new DevTaskRunnerCronTask();
        $cron->setNextTask($running);
        $this->process($cron);

        $this->assertEquals([$queued->ID], DevTaskRunnerTest_Task::$ranIds, 'A run claimed elsewhere must not run again');
    }

    public function testOutputIsSavedWhileTaskRuns()
    {
        $run = $this->createRun('Queued', 'mode=readOutputMidRun');

        $this->process();

        $this->assertEquals('before', DevTaskRunnerTest_Task::$outputSeenMidRun);
        $this->assertEquals('beforeafter', DevTaskRun::get()->byID($run->ID)->Output);
    }

    public function testTaskExceptionMarksRunAsErrorAndKeepsEarlierOutput()
    {
        $run = $this->createRun('Queued', 'mode=throw');

        $this->process();

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Error', $saved->Status);
        $this->assertEquals('RuntimeException: Task failed', $saved->FailureReason);
        $this->assertStringStartsWith('before throw', $saved->Output);
        $this->assertStringContainsString('Task failed', $saved->Output);
    }

    public function testCancelQueuedRunStopsItFromStarting()
    {
        $run = $this->createRun('Queued');

        $this->assertTrue($run->canCancel());
        $this->assertEquals('cancelled', $run->cancel());

        $this->process();

        $this->assertEquals([], DevTaskRunnerTest_Task::$ranIds);
        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Cancelled', $saved->Status);
        $this->assertNotEmpty($saved->FinishDate);
    }

    public function testCancelRunningRunStopsTaskAtNextCheck()
    {
        $run = $this->createRun('Queued', 'mode=cancelSelf');

        $this->process();

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Cancelled', $saved->Status);
        $this->assertTrue((bool) $saved->CancelRequested);
        $this->assertStringContainsString('before check', $saved->Output);
        $this->assertStringNotContainsString('after check', $saved->Output);
    }

    public function testCancelRequestOnRunningRunIsOnlyAFlag()
    {
        $run = $this->createRun('Queued');
        $run->claim('pod-a');

        $this->assertEquals('requested', $run->cancel());

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Running', $saved->Status);
        $this->assertEquals('Running (cancel requested)', $saved->StatusLabel());
        $this->assertFalse($saved->canCancel());
    }

    public function testCancelDoesNothingForEndedRuns()
    {
        foreach (['Draft', 'Finished', 'Error', 'Cancelled'] as $status) {
            $run = $this->createRun($status);
            $this->assertFalse($run->canCancel(), "Can cancel a $status run");
            $this->assertNull($run->cancel(), "Cancelled a $status run");
            $this->assertEquals($status, DevTaskRun::get()->byID($run->ID)->Status);
        }
    }

    public function testCheckCancelledOutsideTheRunnerDoesNothing()
    {
        DevTaskRun::checkCancelled();
        $this->assertNull(DevTaskRunMonitor::current());
    }

    public function testFormActionsAreAllowedOnItemRequest()
    {
        $request = new DevTaskRunItemRequest(null, null, $this->createRun('Queued'), null, 'DetailForm');

        $this->assertTrue($request->checkAccessAction('ItemEditForm'));
        // Reached even when the run has ended and the button is gone.
        $this->assertTrue($request->checkAccessAction('doCancelRun'));
    }

    public function testSignalWaitsUntilDeferredCallbackReturns()
    {
        if (!function_exists('pcntl_sigprocmask') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        $received = [];
        $previousHandler = pcntl_signal_get_handler(SIGINT);
        pcntl_signal(SIGINT, function ($signal) use (&$received) {
            $received[] = $signal;
        });

        try {
            DevTaskRunMonitor::withSignalsDeferred(function () use (&$received) {
                posix_kill(getmypid(), SIGINT);
                pcntl_signal_dispatch();
                $this->assertEmpty($received, 'The signal was handled inside the callback');
            });
            pcntl_signal_dispatch();

            $this->assertEquals([SIGINT], $received);
        } finally {
            pcntl_signal(SIGINT, $previousHandler);
        }
    }

    public function testShutdownAfterClaimMarksRunAsError()
    {
        $run = $this->createRun('Queued');
        $monitor = new DevTaskRunMonitor($run);

        $this->assertTrue($monitor->claim('pod-a'));
        $this->assertSame($monitor, DevTaskRunMonitor::current());

        error_clear_last();
        DevTaskRunMonitor::handleShutdown();

        $this->assertNull(DevTaskRunMonitor::current());
        $this->assertEquals('Error', DevTaskRun::get()->byID($run->ID)->Status);
    }

    public function testFinishSavesStatusWhenOutputCannotBeSaved()
    {
        $run = $this->createRun('Queued');
        $monitor = new DevTaskRunnerTest_FailingMonitor($run);
        $monitor->claim('pod-a');
        $monitor->start();
        Config::inst()->update('DevTaskRunMonitor', 'output_save_interval', 3600);
        echo 'lost';
        $monitor->failuresLeft = 1;
        $monitor->finish('Finished');

        $this->assertNull(DevTaskRunMonitor::current());
        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Finished', $saved->Status);
        $this->assertStringContainsString('[Some output could not be saved: Output save failed]', $saved->Output);
    }

    public function testOutputFromAFailedSaveIsSavedLater()
    {
        $run = $this->createRun('Queued');
        $monitor = new DevTaskRunnerTest_FailingMonitor($run);
        $monitor->claim('pod-a');
        $monitor->start();
        $monitor->failuresLeft = 1;
        echo 'first ';
        echo 'second';
        $monitor->finish('Finished');

        $this->assertEquals('first second', DevTaskRun::get()->byID($run->ID)->Output);
    }

    public function testInvalidUtf8OutputIsSaved()
    {
        $run = $this->createRun('Queued');
        $monitor = new DevTaskRunMonitor($run);
        $monitor->claim('pod-a');
        $monitor->start();
        echo "before \xff after";
        $monitor->finish('Finished');

        $this->assertEquals('before ? after', DevTaskRun::get()->byID($run->ID)->Output);
    }

    public function testTransactionLeftOpenByTaskIsRolledBack()
    {
        $marker = $this->createRun('Draft', 'unchanged');
        $failing = $this->createRun('Queued', 'mode=leaveTransactionOpen markerID=' . $marker->ID);
        $next = $this->createRun('Queued');

        $this->process();

        $this->assertEquals('Error', DevTaskRun::get()->byID($failing->ID)->Status);
        $this->assertEquals('Finished', DevTaskRun::get()->byID($next->ID)->Status);
        $this->assertEquals('unchanged', DevTaskRun::get()->byID($marker->ID)->Params);
    }

    public function testShutdownDuringRunMarksRunAsError()
    {
        $run = $this->createRun('Queued');
        $run->claim('pod-a');

        $monitor = new DevTaskRunMonitor($run);
        $monitor->start();
        echo 'partial';
        error_clear_last();
        DevTaskRunMonitor::handleShutdown();

        $this->assertNull(DevTaskRunMonitor::current());
        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Error', $saved->Status);
        $this->assertStringContainsString('ended before the task returned', $saved->FailureReason);
        $this->assertEquals('partial', $saved->Output);
    }

    public function testStatusSurvivesRollbackOfTaskTransaction()
    {
        $run = $this->createRun('Queued');
        $run->claim('pod-a');

        $monitor = new DevTaskRunMonitor($run);
        $monitor->start();
        DB::get_conn()->transactionStart();
        try {
            echo 'partial';
            error_clear_last();
            DevTaskRunMonitor::handleShutdown();
        } finally {
            // MySQL does this when the dying process's connection closes.
            DB::get_conn()->transactionRollback();
        }

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Error', $saved->Status);
        $this->assertEquals('partial', $saved->Output);
    }

    public function testCancelIsSeenInsideTaskTransaction()
    {
        $run = $this->createRun('Queued', 'mode=cancelInTransaction');

        $this->process();

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Cancelled', $saved->Status);
        $this->assertStringNotContainsString('after check', $saved->Output);
    }

    public function testFinishDoesNotOverwriteAnEndedRun()
    {
        $run = $this->createRun('Queued');
        $run->claim('pod-a');

        $monitor = new DevTaskRunMonitor($run);
        $monitor->start();
        $monitor->finish('Error', 'first');
        $monitor->finish('Finished');

        $saved = DevTaskRun::get()->byID($run->ID);
        $this->assertEquals('Error', $saved->Status);
        $this->assertEquals('first', $saved->FailureReason);
    }

    public function testOutputOverTheLimitIsTruncated()
    {
        $run = $this->createRun('Queued');
        $run->claim('pod-a');

        $monitor = new DevTaskRunMonitor($run);
        $monitor->start();
        echo str_repeat('x', DevTaskRunMonitor::OUTPUT_LIMIT + 100);
        echo 'dropped';
        $monitor->finish('Finished');

        $output = DevTaskRun::get()->byID($run->ID)->Output;
        $this->assertStringStartsWith(str_repeat('x', 100), $output);
        $this->assertStringContainsString('[Output truncated', $output);
        $this->assertStringNotContainsString('dropped', $output);
        $this->assertLessThan(16777215, strlen($output));
    }

    /**
     * @param string $status
     * @param string $params
     *
     * @return DevTaskRun
     */
    protected function createRun($status, $params = '')
    {
        $run = DevTaskRun::create();
        $run->Task = 'DevTaskRunnerTest_Task';
        $run->Params = $params;
        $run->Status = $status;
        $run->write();

        return $run;
    }

    /**
     * Runs the cron task, without its progress messages in the test output.
     *
     * @param DevTaskRunnerCronTask|null $cron
     */
    protected function process($cron = null)
    {
        ob_start();
        try {
            ($cron ?: new DevTaskRunnerCronTask())->process();
        } finally {
            ob_end_clean();
        }
    }
}

class DevTaskRunnerTest_Task extends BuildTask implements TestOnly
{
    /** @var int[] */
    public static $ranIds = [];

    /** @var string|null */
    public static $outputSeenMidRun = null;

    public static function reset()
    {
        self::$ranIds = [];
        self::$outputSeenMidRun = null;
    }

    /**
     * A connection that stands in for an admin's web request.
     */
    public static function adminConn(): SS_Database
    {
        $database = DB::get_conn()->getSelectedDatabase();
        $conn = DB::get_conn('DevTaskRunnerTest_admin');

        if (!$conn) {
            global $databaseConfig;
            $config = $databaseConfig;
            $config['database'] = $database;
            $conn = DB::connect($config, 'DevTaskRunnerTest_admin');
        } elseif ($conn->getSelectedDatabase() !== $database) {
            $conn->selectDatabase($database);
        }

        return $conn;
    }

    public function run($request)
    {
        $run = DevTaskRun::get()->byID(DevTaskRunMonitor::current()->getRunID());
        self::$ranIds[] = (int) $run->ID;

        switch ($request->getVar('mode')) {
            case 'readOutputMidRun':
                echo 'before';
                self::$outputSeenMidRun = DB::prepared_query(
                    'SELECT "Output" FROM "DevTaskRun" WHERE "ID" = ?',
                    [$run->ID]
                )->value();
                echo 'after';
                return;
            case 'throw':
                echo 'before throw';
                throw new RuntimeException('Task failed');
            case 'cancelSelf':
                echo 'before check';
                $run->cancel();
                DevTaskRun::checkCancelled();
                echo 'after check';
                return;
            case 'cancelInTransaction':
                DB::get_conn()->transactionStart();
                try {
                    // The first read fixes the transaction's snapshot.
                    DB::query('SELECT COUNT(*) FROM "DevTaskRun"')->value();
                    // An admin cancels the run from another connection.
                    self::adminConn()->preparedQuery(
                        'UPDATE "DevTaskRun" SET "CancelRequested" = 1 WHERE "ID" = ?',
                        [$run->ID]
                    );
                    DevTaskRun::checkCancelled();
                    echo 'after check';
                } finally {
                    DB::get_conn()->transactionRollback();
                }
                return;
            case 'leaveTransactionOpen':
                DB::get_conn()->transactionStart();
                DB::prepared_query(
                    'UPDATE "DevTaskRun" SET "Params" = ? WHERE "ID" = ?',
                    ['changed', (int) $request->getVar('markerID')]
                );
                throw new RuntimeException('Task failed inside a transaction');
            default:
                echo 'hello ' . $request->getVar('name');
        }
    }
}

class DevTaskRunnerTest_FailingMonitor extends DevTaskRunMonitor implements TestOnly
{
    /**
     * The number of output saves that fail before saves work again.
     * @var int
     */
    public $failuresLeft = 0;

    protected function appendOutput(string $chunk): void
    {
        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;
            throw new RuntimeException('Output save failed');
        }

        parent::appendOutput($chunk);
    }
}
