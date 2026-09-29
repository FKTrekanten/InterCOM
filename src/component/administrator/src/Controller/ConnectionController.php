<?php

namespace FKT\Component\Intercom\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

final class ConnectionController extends BaseController
{
    private function runtime(bool $post = true)
    {
        $app = Factory::getApplication();
        if (
            !$app->getIdentity()->authorise('core.admin', 'com_intercom')
            || ($post && ($app->input->getMethod() !== 'POST' || !Session::checkToken('post')))
        ) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        return $app->bootComponent('com_intercom')->runtime;
    }

    private const OPTIONS = 'index.php?option=com_config&view=component&component=com_intercom';

    public function savecredentials(): void
    {
        $r = $this->runtime();
        $app = Factory::getApplication();
        try {
            $r->connection->save([
                'client_id' => trim($app->input->post->get('client_id', '', 'raw')),
                'client_secret' => trim($app->input->post->get('client_secret', '', 'raw')),
            ], (int) $app->getIdentity()->id);
            $app->enqueueMessage(Text::_('COM_INTERCOM_SAVED'));
        } catch (\Throwable $e) {
            $r->store->audit((int) $app->getIdentity()->id, 'connection.credentials_failed');
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
        }
        $this->setRedirect(self::OPTIONS);
    }

    public function importtokens(): void
    {
        $r = $this->runtime();
        $app = Factory::getApplication();
        try {
            $r->connection->importTokens(
                trim($app->input->post->get('access_token', '', 'raw')),
                trim($app->input->post->get('refresh_token', '', 'raw')),
                $app->input->post->getInt('expires_in', 0),
                (int) $app->getIdentity()->id
            );
            $app->enqueueMessage(Text::_('COM_INTERCOM_TOKENS_IMPORTED'));
        } catch (\Throwable $e) {
            $r->store->audit((int) $app->getIdentity()->id, 'connection.import_failed');
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_ERROR'), 'error');
        }
        $this->setRedirect(self::OPTIONS);
    }

    public function connect(): void
    {
        $r = $this->runtime();
        $app = Factory::getApplication();
        $values = $r->connection->credentials();
        if (empty($values['client_id']) || empty($values['client_secret']) || $values['client_id'] === 'fixture-id') {
            $app->enqueueMessage(Text::_('COM_INTERCOM_NOT_CONNECTED'), 'error');
            $this->setRedirect(self::OPTIONS);
            return;
        }
        $state = bin2hex(random_bytes(32));
        $redirect = Uri::root() . 'administrator/index.php?option=com_intercom&task=connection.callback';
        $app->getSession()->set('intercom.oauth', ['state' => $state, 'expires' => time() + 600,
            'actor' => (int) $app->getIdentity()->id, 'redirect' => $redirect]);
        $r->store->audit((int) $app->getIdentity()->id, 'connection.started');
        $app->redirect('https://rest.cleverreach.com/oauth/authorize.php?' . http_build_query([
            'client_id' => $values['client_id'], 'response_type' => 'code', 'redirect_uri' => $redirect, 'state' => $state]));
    }

    public function callback(): void
    {
        $r = $this->runtime(false);
        $app = Factory::getApplication();
        $pending = $app->getSession()->get('intercom.oauth', []);
        $app->getSession()->clear('intercom.oauth');
        try {
            if (
                ($pending['expires'] ?? 0) < time() || ($pending['actor'] ?? 0) !== (int) $app->getIdentity()->id
                || !hash_equals($pending['state'] ?? '', $app->input->getString('state')) || !$app->input->getString('code')
            ) {
                throw new \RuntimeException('COM_INTERCOM_DENIED');
            }
            $r->connection->authorize($app->input->getString('code'), $pending['redirect'], (int) $app->getIdentity()->id);
            $app->enqueueMessage(Text::_('COM_INTERCOM_CONNECTED'));
        } catch (\Throwable) {
            $r->store->audit((int) $app->getIdentity()->id, 'connection.failed');
            $app->enqueueMessage(Text::_('COM_INTERCOM_PROVIDER_ERROR'), 'error');
        }
        $this->setRedirect(self::OPTIONS);
    }
}
