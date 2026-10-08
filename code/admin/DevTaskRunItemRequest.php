<?php

/**
 * Adds a "Cancel run" action to the DevTaskRun edit form.
 */
class DevTaskRunItemRequest extends GridFieldDetailForm_ItemRequest
{
    /**
     * SilverStripe checks the allowed actions of the class that declares the
     * method, so the overridden ItemEditForm must be listed again here.
     * doCancelRun is listed so that a click still reaches it after the run has
     * ended and the button is gone from the form.
     *
     * @var array
     */
    private static $allowed_actions = [
        'ItemEditForm',
        'doCancelRun',
    ];

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();

        if ($form instanceof Form
            && $this->record instanceof DevTaskRun
            && $this->record->canCancel()
            && $this->record->canEdit()
        ) {
            $form->Actions()->push(
                FormAction::create('doCancelRun', 'Cancel run')
                    ->setUseButtonTag(true)
                    ->addExtraClass('ss-ui-action-destructive')
            );
        }

        return $form;
    }

    /**
     * @param array|SS_HTTPRequest $data
     * @param Form|null $form Null when the action is called by URL, not by a form submission.
     */
    public function doCancelRun($data, $form = null)
    {
        // Only a form submission has passed the CSRF check.
        if (!$form instanceof Form) {
            return $this->httpError(404);
        }

        $controller = $this->getToplevelController();

        if (!$this->record->canEdit()) {
            return $controller->httpError(403);
        }

        $result = $this->record->cancel();

        if ($result === 'cancelled') {
            $form->sessionMessage('Cancelled the run. It will not start.', 'good', false);
        } elseif ($result === 'requested') {
            $form->sessionMessage(
                'Asked the task to stop. It stops at its next cancel check.'
                . ' Tasks that do not check for cancel requests run to the end.',
                'good',
                false
            );
        } else {
            $form->sessionMessage('The run could not be cancelled, because it is no longer queued or running.', 'bad', false);
        }

        // Reload so the form shows the new status.
        $this->record = DevTaskRun::get()->byID($this->record->ID);

        return $this->edit($controller->getRequest());
    }
}
