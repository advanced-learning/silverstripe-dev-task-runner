<?php

class DevTaskRunnerCronTask implements CronTask
{
	private static $schedule = '*/2 * * * *';

	private $nextTask = null;

	/**
	 * @param DevTaskRun $nextTask
	 *
	 * @return void
	 */
	public function setNextTask(DevTaskRun $nextTask): void
	{
		$this->nextTask = $nextTask;
	}

	public function getSchedule() {
		return Config::inst()->get('DevTaskRunnerCronTask', 'schedule');
	}

	public function process() {
		$nextTask = $this->nextTask ?? DevTaskRun::get_next_task();

		if (!$nextTask) {
			return;
		}

		//create task instance
		$task = Injector::inst()->create($nextTask->Task);

		//get params
		$params = explode(' ', $nextTask->Params);
		$paramList = array();
		if ($params) {
			foreach ($params as $param) {
				$parts = explode('=', $param);

				if (count($parts) === 2) {
					$value = $parts[1];
					$value = json_decode($value, true) ?: $value;

					$paramList[$parts[0]] = $value;
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
