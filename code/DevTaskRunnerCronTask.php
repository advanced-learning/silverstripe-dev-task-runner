<?php

/**
 * Created by PhpStorm.
 * User: Conrad
 * Date: 22/01/2016
 * Time: 9:58 AM
 */
class DevTaskRunnerCronTask implements CronTask
{
	private static $schedule = '*/2 * * * *';

	public function getSchedule() {
		return Config::inst()->get('DevTaskRunnerCronTask', 'schedule');
	}

	public function process() {
		$nextTask = DevTaskRun::get_next_task();

		if ($nextTask) {
			//create task instance
			$task = Injector::inst()->create($nextTask->Task);

			//get params
			$params = explode(' ', $nextTask->Params);
			$paramList = array();
			if ($params) {
				foreach ($params as $param) {
					$parts = explode('=', $param);

					if (count($parts) === 2) {
						$paramList[$parts[0]] = $parts[1];
					}
				}
			}

			echo 'Starting task ' . $task->getTitle() . "\n";
			//remove so it doesn't get rerun
			$nextTask->Status = 'Running';
			$nextTask->StartDate = SS_Datetime::now()->getValue();
			$nextTask->write();

			$request = new SS_HTTPRequest('GET', 'dev/tasks/' . $nextTask->Task, $paramList);

			ob_start();
			try {
				$task->run($request);
				$output = ob_get_clean();
				$wasError = false;
			} catch (Throwable $e) {
				$errorClass = get_class($e);
				$output = "Task threw $errorClass.\n" .
					"Message: {$e->getMessage()}\n" .
					"Code: {$e->getCode()}\n" .
					"Trace: {$e->getTraceAsString()}";
				$wasError = true;
				ob_clean();
			}

			$nextTask->Status = $wasError ? 'Error' : 'Finished';
			$nextTask->FinishDate = SS_Datetime::now()->getValue();
			$nextTask->Output = $output;
			$nextTask->write();

			echo 'Finished task ' . ($wasError ? '(with error) ' : '') . $task->getTitle() . "\n";
		}
	}
}
