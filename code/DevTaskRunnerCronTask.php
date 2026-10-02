<?php

class DevTaskRunnerCronTask implements CronTask
{
    private static $schedule = '* * * * *';

    private $nextTask = null;

    /**
     * The maximum number of tasks to run in a single `process`.
     * @see process
     * @var integer
     */
    private static $maxTasksPerRun = 3;

    /**
     * @param DevTaskRun|null $nextTask
     *
     * @return void
     */
    public function setNextTask(?DevTaskRun $nextTask): void
    {
        $this->nextTask = $nextTask;
    }

    public function getSchedule(): string
    {
        return Config::inst()->get('DevTaskRunnerCronTask', 'schedule');
    }

    /**
     * Processes tasks. It will run the manually specified `nextTask` first,
     * then continue to process tasks from the queue until the maximum
     * number of tasks for this run has been reached.
     *
     * Each run is claimed just before it starts, so that other cron processes
     * can take the rest of the queue, and no run is started twice.
     */
    public function process(): void
    {
        DevTaskRunMonitor::registerHandlers();

        $host = (string) gethostname();
        $ran = 0;

        // If a specific task has been provided, run it first.
        if ($this->nextTask) {
            echo "A specific task was provided. It will be run first.\n";

            if ($this->nextTask->claim($host)) {
                $this->runTask($this->nextTask);
                $ran++;
            } else {
                echo 'Task ' . $this->nextTask->ID . " is not queued, or another process claimed it. Skipping it.\n";
            }
        }

        echo "Running tasks from the queue. Max tasks: " . self::$maxTasksPerRun . "\n";

        while ($ran < self::$maxTasksPerRun) {
            $taskFromQueue = $this->claimNextQueued($host);

            // If the queue is empty, stop.
            if (!$taskFromQueue) {
                break;
            }

            $this->runTask($taskFromQueue);
            $ran++;
        }

        if (!$ran) {
            echo "No tasks to run. Finishing run.\n";
            return;
        }

        echo "Task processing run finished. Ran $ran task(s).\n";
    }

    /**
     * Claims the oldest queued run. When another process claims it first,
     * tries the next one.
     *
     * @param string $host
     *
     * @return DevTaskRun|null
     */
    private function claimNextQueued(string $host): ?DevTaskRun
    {
        // A lost claim leaves that run Running, so the next query skips it.
        // The limit only stops a loop if claims keep failing.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            /** @var DevTaskRun|null $taskFromQueue */
            $taskFromQueue = DevTaskRun::get()
                ->filter('Status', 'Queued')
                ->sort('Created ASC, ID ASC')
                ->first();

            if (!$taskFromQueue) {
                return null;
            }

            if ($taskFromQueue->claim($host)) {
                return $taskFromQueue;
            }
        }

        return null;
    }

    /**
     * Executes a single DevTaskRun record. The run must already be claimed.
     *
     * @param DevTaskRun $taskToRun The task object to run.
     */
    private function runTask(DevTaskRun $taskToRun)
    {
        echo 'Starting task: ' . $taskToRun->TaskTitle() . ' (ID: ' . $taskToRun->ID . ")\n";

        $monitor = new DevTaskRunMonitor($taskToRun);
        $monitor->start();

        $status = 'Finished';
        $failureReason = null;

        try {
            // Create an instance of the task class
            $task = Injector::inst()->create($taskToRun->Task);
            $request = new SS_HTTPRequest('GET', 'dev/tasks/' . $taskToRun->Task, $this->parseParams($taskToRun->Params));
            $task->run($request);
        } catch (DevTaskCancelledException $e) {
            $status = 'Cancelled';
            echo "\n" . $e->getMessage() . "\n";
        } catch (Throwable $e) {
            $status = 'Error';
            $failureReason = get_class($e) . ': ' . $e->getMessage();
            echo "\n" . $e;
        }

        $monitor->finish($status, $failureReason);

        echo 'Finished task ' . $taskToRun->TaskTitle() . ($status === 'Finished' ? '' : " ($status)") . "\n";
    }

    /**
     * Parses `param1=value1 param2=value2` into an array. JSON values are decoded.
     *
     * @param string|null $params
     *
     * @return array
     */
    private function parseParams(?string $params): array
    {
        $paramList = [];

        foreach (explode(' ', (string) $params) as $param) {
            $parts = explode('=', $param, 2); // Ensure we only split on the first '='

            if (count($parts) === 2) {
                $value = $parts[1];
                // Attempt to decode JSON, otherwise use the raw string value
                $decodedValue = json_decode($value, true);
                $paramList[$parts[0]] = ($decodedValue !== null) ? $decodedValue : $value;
            }
        }

        return $paramList;
    }
}
