<?php

class DevTaskRun extends DataObject
{
	private static $db = array(
		'Task' => 'Varchar(150)',
		'Params' => 'Varchar(255)',
		'Status' => 'Enum("Queued,Running,Finished,Error", "Queued")',
		'StartDate' => 'SS_Datetime',
		'FinishDate' => 'SS_Datetime',
		'Output' => 'Text',
	);

	private static $summary_fields = array(
		'TaskTitle' => 'Task',
		'Params' => 'Params',
		'Status' => 'Status',
		'StartDate' => 'Start Date',
		'FinishDate' => 'Finish Date',
	);

	private static $default_sort = 'Created DESC';

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		if (!$this->Status || $this->Status === 'Queued') {
			$fields->removeByName('StartDate');
			$fields->removeByName('FinishDate');
			$fields->removeByName('Output');

			$taskList = array();

			//defined allowed task list
			$tasks = $this->config()->task_list;

			//default to all tasks
			if (!$tasks) {
				$tasks = ClassInfo::subclassesFor('BuildTask');
				//remove first item which is BuildTask
				array_shift($tasks);
			}

			foreach ($tasks as $task) {
				$taskList[$task] = singleton($task)->getTitle();
			}

			$fields->addFieldsToTab('Root.Main', array(
				DropdownField::create('Task', 'Task', $taskList),
			));

			$fields->dataFieldByName('Params')->setDescription(
				'Add a list of params to be passed to the Task.' .
				'Separate with spaces, e.g. <code>param1=value1 param2=value2</code>.'
			);

			$addStatusBefore = '';
		} else {
			$fields->addFieldsToTab(
				'Root.Main',
				[
					ReadonlyField::create('Task', 'Task', $this->TaskTitle()),
					ReadonlyField::create('Params', 'Params', $this->Params),
					ReadonlyField::create('StartDate', 'Start Date', $this->StartDate),
					ReadonlyField::create('FinishDate', 'Finish Date', $this->FinishDate),
					ReadonlyField::create('Output', 'Output', $this->Output),
				]
			);

			$addStatusBefore = 'StartDate';
		}

		$fields->addFieldToTab(
			'Root.Main',
			ReadonlyField::create('Status', 'Status', $this->Status ?: 'Queued'),
			$addStatusBefore
		);

		return $fields;
	}

	public function TaskTitle() {
	    if (!class_exists($this->Task)) {
            return "Deleted Task - $this->Task";
        }
        if (!method_exists($this->Task, 'getTitle')) {
            return "$this->Task";
        }
		return singleton($this->Task)->getTitle();
	}

	public static function get_next_task()
	{
		return DevTaskRun::get()->filter('Status', 'Queued')->sort('Created ASC')->first();
	}
}
