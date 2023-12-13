<?php

class DevTaskRun extends DataObject
{
	private static $db = array(
		'Task' => 'Varchar(150)',
		'Params' => 'Varchar(255)',
		'Status' => 'Enum("Draft,Queued,Running,Finished,Error", "Draft")',
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
		'OutputPreview' => 'Output Preview',
	);

	private static $default_sort = 'Created DESC';

	private $queuedCheckBox;

	public function __construct($record = null, $isSingleton = false, $model = null)
	{
		$this->queuedCheckBox = CheckboxField::create('Queue');

		parent::__construct($record, $isSingleton, $model);
	}

	public function getCMSFields(): FieldList
	{
		$fields = parent::getCMSFields();

		$addStatusBefore = '';

		if (!$this->exists()) {
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

			$fields->addFieldToTab(
				'Root.Main',
				DropdownField::create('Task', 'Task', $taskList),
			);

			$fields->addFieldToTab(
				'Root.Main',
				LiteralField::create(
					'Instruction',
					'Note: You must save this task to be able to queue it for execution.'
				)
			);

			$fields->dataFieldByName('Params')->setDescription(
				'Add a list of params to be passed to the Task.' .
				'Separate with spaces, e.g. <code>param1=value1 param2=value2</code>.'
			);

			$addStatusBefore = 'Instruction';
		} else {
			$fields->addFieldToTab(
				'Root.Main',
				ReadonlyField::create('Task', 'Task', $this->TaskTitle()),
			);

			//add checkbox control for cms user for advancing task from 'Draft to 'Queued' status
			if ($this->Status === 'Draft') {
				$fields->addFieldToTab(
					'Root.Main',
					$this->queuedCheckBox
				);

				//if no longer in draft, remove above checkbox and make the Params readonly
			} else {
				$fields->removeByName('Queue');
				$fields->addFieldToTab(
					'Root.Main',
					ReadonlyField::create('Params', 'Params', $this->Params)
				);
			}

			$fields->addFieldToTab(
				'Root.Main',
				ReadonlyField::create('Description', 'Description', $this->getDesc()),
				'Params'
			);
		}

		if (!$this->exists() || $this->Status === 'Draft' || $this->Status === 'Queued') {
			$fields->removeByName('StartDate');
			$fields->removeByName('FinishDate');
			$fields->removeByName('Output');
		} else {
			$fields->addFieldsToTab(
				'Root.Main',
				[
					ReadonlyField::create('StartDate', 'Start Date', $this->StartDate),
					ReadonlyField::create('FinishDate', 'Finish Date', $this->FinishDate),
				]
			);

			$fields->addFieldToTab(
				'Root.Output',
				ReadonlyField::create('Output', '', $this->Output)
			);
			$fields->addFieldToTab(
				'Root.Output as HTML',
				LiteralField::create('OutputAsHtml', $this->Output)
			);

			$addStatusBefore = 'StartDate';
		}

		$fields->addFieldToTab(
			'Root.Main',
			ReadonlyField::create('Status', 'Status', $this->Status ?: 'Draft'),
			$addStatusBefore
		);

		return $fields;
	}

	public function onBeforeWrite()
	{
		parent::onBeforeWrite();

		/** @var CheckboxField $queueCheckbox */
		$queueCheckbox = $this->getCMSFields()->dataFieldByName('Queue');

		if ($queueCheckbox && $queueCheckbox->Value()) {
			$this->Status = 'Queued';
			$this->write();
		}
	}

	public function getDesc(): string
	{
		$taskName = $this->Task;

		if (class_exists($taskName)) {
			$instance = new $taskName();

			if ($instance instanceof BuildTask) {
				return $instance->getDescription();
			}
		}

		return "";
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

	public function OutputPreview()
	{
		return $this->Output ? (substr($this->Output, 0, 30) . '...') : '';
	}

	public static function get_next_task()
	{
		return DevTaskRun::get()->filter('Status', 'Queued')->sort('Created ASC')->first();
	}
}
