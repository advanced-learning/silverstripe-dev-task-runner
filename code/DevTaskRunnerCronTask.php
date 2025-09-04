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
     */
    public function process(): void
    {
        /** @var DevTaskRun[] $tasksToRun */
        $tasksToRun = [];
        /** @var int[] $tasksToRunIds */
        $tasksToRunIds = [];

        // If a specific task has been provided, add it as the first one to run.
        if ($this->nextTask) {
            echo "A specific task was provided. It will be run first.\n";
            $tasksToRun[] = $this->nextTask;
        }

        echo "Building task list for this run. Max tasks: " . self::$maxTasksPerRun . "\n";

        // Fill the rest of the run with tasks from the queue, up to the maximum.
        while (count($tasksToRun) < self::$maxTasksPerRun) {
            $taskFromQueue = DevTaskRun::get()
                ->filter('Status', 'Queued')
                ->exclude('ID', $tasksToRunIds)
                ->sort('Created ASC')
                ->first();

            // If the queue is empty, stop adding tasks.
            if (!$taskFromQueue) {
                break;
            }

            $tasksToRun[] = $taskFromQueue;
            $tasksToRunIds[] = $taskFromQueue->ID;
        }

        if (empty($tasksToRun)) {
            echo "No tasks to run. Finishing run.\n";
            return;
        }

        echo "Starting task processing run for " . count($tasksToRun) . " task(s).\n";

        // Now, execute all the tasks that have been collected.
        foreach ($tasksToRun as $task) {
            $this->runTask($task);
        }

        echo "Task processing run finished.\n";
    }

    /**
     * Executes a single DevTaskRun record.
     *
     * @param DevTaskRun $taskToRun The task object to run.
     */
    private function runTask(DevTaskRun $taskToRun)
    {
        // Create an instance of the task class
        $task = Injector::inst()->create($taskToRun->Task);

        // Parse the parameters for the task
        $params = explode(' ', $taskToRun->Params);
        $paramList = [];
        if ($params) {
            foreach ($params as $param) {
                $parts = explode('=', $param, 2); // Ensure we only split on the first '='

                if (count($parts) === 2) {
                    $value = $parts[1];
                    // Attempt to decode JSON, otherwise use the raw string value
                    $decodedValue = json_decode($value, true);
                    $paramList[$parts[0]] = ($decodedValue !== null) ? $decodedValue : $value;
                }
            }
        }

        echo 'Starting task: ' . $task->getTitle() . ' (ID: ' . $taskToRun->ID . ")\n";
        // Update the task status to 'Running' to prevent it from being picked up again
        $taskToRun->Status = 'Running';
        $taskToRun->StartDate = SS_Datetime::now()->getValue();
        $taskToRun->write();

        $request = new SS_HTTPRequest('GET', 'dev/tasks/' . $taskToRun->Task, $paramList);

        // Capture output and handle potential errors
        ob_start();
        try {
            $task->run($request);
            $output = ob_get_clean();
            $wasError = false;
        } catch (Throwable $e) {
            $output = (string) $e;
            $wasError = true;
            // Ensure the output buffer is cleaned on error
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        // Update the task with the final status, output, and finish time
        $taskToRun->Status = $wasError ? 'Error' : 'Finished';
        $taskToRun->FinishDate = SS_Datetime::now()->getValue();
        $taskToRun->Output = $output;
        $taskToRun->write();

        echo 'Finished task ' . $task->getTitle() . ($wasError ? ' (with error)' : '') . "\n";
    }
}
