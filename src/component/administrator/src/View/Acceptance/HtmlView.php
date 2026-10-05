<?php

namespace FKT\Component\Intercom\Administrator\View\Acceptance;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseView;
use FKT\Component\Intercom\Administrator\Service\ReleaseApproval;

final class HtmlView extends BaseView
{
    public $runtime;
    public $proof;
    public $approved;
    public $proofCurrent;
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_intercom') || !$user->authorise('core.admin', 'com_intercom')) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $this->runtime = $app->bootComponent('com_intercom')->runtime;
        $approval = new ReleaseApproval($this->runtime->store);
        $this->proof = $approval->latest((int) $user->id);
        $this->approved = $approval->valid();
        $this->proofCurrent = $this->proof && hash_equals($this->proof['fingerprint'], $approval->fingerprint());
        parent::display($tpl);
    }
}
