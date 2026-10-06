<?php

namespace FKT\Plugin\Extension\Intercom\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Language\Text;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\EventInterface;
use FKT\Component\Intercom\Administrator\Service\Settings;

final class Intercom extends CMSPlugin implements SubscriberInterface
{
    private $pending = null;
    private bool $rejected = false;
    public static function getSubscribedEvents(): array
    {
        return ['onExtensionBeforeSave' => 'beforeSave', 'onExtensionAfterSave' => 'afterSave',
            'application.before_respond' => 'beforeRespond', 'onBeforeRespond' => 'beforeRespond'];
    }
    public function beforeSave(BeforeSaveEvent $event): void
    {
        $table = $event->getItem();
        if ($event->getContext() !== 'com_config.component' || ($table->element ?? '') !== 'com_intercom') {
            return;
        }
        $app = $this->getApplication();
        if (!$app->getIdentity()->authorise('core.admin', 'com_intercom')) {
            throw new \RuntimeException(Text::_('COM_INTERCOM_DENIED'));
        }
        $r = $app->bootComponent('com_intercom')->runtime;
        // Joomla core persists component asset rules before this event. Record that
        // snapshot even when validation subsequently rejects the ordinary settings.
        $rules = $r->store->row("SELECT rules FROM #__assets WHERE name='com_intercom'");
        $r->store->audit((int) $app->getIdentity()->id, 'component.permissions_saved', 0, ['rules' => json_decode($rules['rules'] ?? '{}', true)]);
        $r->store->begin();
        try {
            $config = (new Settings($r))->save(json_decode($table->params, true, 32, JSON_THROW_ON_ERROR), (int) $app->getIdentity()->id);
            $table->params = json_encode($config, JSON_THROW_ON_ERROR);
            $this->pending = $r;
            register_shutdown_function(static fn () => $r->store->rollback());
        } catch (\Throwable $e) {
            $r->store->rollback();
            $r->store->audit((int) $app->getIdentity()->id, 'configuration.failed');
            $this->rejected = true;
            $message = Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_INVALID_SETTINGS');
            // Joomla's Options controller translates JERROR_SAVE_FAILED without
            // sprintf, leaving its %s literal. Override only on this rejected
            // InterCOM save; the specific reason is already enqueued below.
            $app->getLanguage()->load('com_intercom.configfailure', JPATH_ADMINISTRATOR . '/components/com_intercom');
            $app->enqueueMessage($message, 'error');
            throw new \RuntimeException($message);
        }
    }
    public function afterSave(AfterSaveEvent $event): void
    {
        if ($this->pending && $event->getContext() === 'com_config.component' && ($event->getItem()->element ?? '') === 'com_intercom') {
            $this->pending->store->commit();
            $this->pending = null;
        }
    }
    public function beforeRespond(EventInterface $event): void
    {
        if ($this->rejected) {
            // The native controller stores a nested params/id/option wrapper after
            // catching the model exception. It cannot bind to the Options fields,
            // so the redirected form shows defaults. Reload persisted settings.
            $this->getApplication()->setUserState('com_config.edit.component.com_intercom.data', null);
            $this->rejected = false;
        }
    }
}
