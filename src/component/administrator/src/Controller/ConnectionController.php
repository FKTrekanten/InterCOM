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

    public function save(): void
    {
        $r = $this->runtime();
        $app = Factory::getApplication();
        try {
            $input = $app->input->post;
            $clientId = trim($input->get('client_id', '', 'raw'));
            $clientSecret = trim($input->get('client_secret', '', 'raw'));
            $rules = json_decode($input->get('audience_rules', '[]', 'raw'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($rules) || !array_is_list($rules)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
            }
            foreach ($rules as $rule) {
                if (
                    !is_array($rule) || !is_int($rule['group'] ?? null) || $rule['group'] < 1
                    || !is_bool($rule['all'] ?? false) || !is_array($rule['tags'] ?? [])
                ) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
                }
                foreach ($rule['tags'] ?? [] as $tag) {
                    if (!is_string($tag) || !str_starts_with($tag, 'group.') || str_contains($tag, ',')) {
                        throw new \RuntimeException('COM_INTERCOM_INVALID_RULES');
                    }
                }
            }
            $filters = array_values(array_unique(array_filter(array_map('intval', explode(',', $input->getString('filter_ids'))), fn ($v) => $v > 0)));
            $config = ['mode' => $input->getCmd('mode') === 'live' ? 'live' : 'fake',
                'retention_days' => max(1, min(3650, $input->getInt('retention_days', 30))),
                'group_id' => $input->getInt('group_id'), 'unsubscribe_form_id' => $input->getInt('unsubscribe_form_id'),
                'sender_email' => $input->getString('sender_email'), 'audience_rules' => json_encode($rules),
                'release_verified' => $input->getBool('release_verified'), 'categories' => []];
            if (
                $config['mode'] === 'live' && (!$config['group_id'] || !$config['unsubscribe_form_id']
                || !filter_var($config['sender_email'], FILTER_VALIDATE_EMAIL) || !$filters)
            ) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_SETTINGS');
            }
            foreach (\FKT\Component\Intercom\Administrator\Domain\Policy::TYPES as $type) {
                $config['categories'][$type] = $input->getInt('category_' . $type);
            }
            $r->store->transaction(function () use ($r, $config, $filters, $app, $clientId, $clientSecret): void {
                $r->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
                // Do not switch accounts, modes or recipient lists while any filter is reserved.
                $reserved = $r->store->row('SELECT f.filter_id FROM #__intercom_filters f LEFT JOIN #__intercom_drafts d ON d.id=f.draft_id WHERE f.draft_id IS NOT NULL AND (d.delivery_mode IS NULL OR d.delivery_mode != \'fake\') LIMIT 1 FOR UPDATE');
                foreach (['mode', 'group_id'] as $key) {
                    if ($reserved && ($r->config[$key] ?? ($key === 'mode' ? 'fake' : 0)) != $config[$key]) {
                        throw new \RuntimeException('COM_INTERCOM_LIVE_RESERVATIONS');
                    }
                }
                if (($r->config['mode'] ?? 'fake') !== $config['mode']) {
                    // Simulated mailings have no external recipient filter to protect.
                    $r->store->execute("UPDATE #__intercom_filters f JOIN #__intercom_drafts d ON d.id=f.draft_id SET f.draft_id=NULL WHERE d.delivery_mode='fake'");
                    $r->store->execute("UPDATE #__intercom_drafts SET filter_id=NULL,tested_revision=NULL,state='cancelled' WHERE delivery_mode='fake' AND state IN ('draft','tested','testing')");
                    $r->store->audit((int) $app->getIdentity()->id, 'simulation.reservations_cleared');
                    // A new provider configuration must supply its own filter IDs.
                    $r->store->execute('DELETE FROM #__intercom_filters WHERE draft_id IS NULL');
                }
                $json = $r->store->q(json_encode($config, JSON_THROW_ON_ERROR));
                $r->store->execute("UPDATE #__extensions SET params=$json WHERE element='com_intercom' AND type='component'");
                foreach ($filters as $id) {
                    $r->store->execute("INSERT IGNORE INTO #__intercom_filters (filter_id) VALUES ($id)");
                }
                $r->store->audit(
                    (int) $app->getIdentity()->id,
                    'configuration.saved',
                    0,
                    ['configuration' => $config, 'filter_ids' => $filters]
                );
                if ($clientId !== '' || $clientSecret !== '') {
                    $r->connection->save(['client_id' => $clientId,
                        'client_secret' => $clientSecret], (int) $app->getIdentity()->id);
                }
            });
            $app->enqueueMessage(Text::_('COM_INTERCOM_SAVED'));
        } catch (\Throwable $e) {
            $r->store->audit((int) $app->getIdentity()->id, 'configuration.failed');
            $app->enqueueMessage(Text::_(str_starts_with($e->getMessage(), 'COM_INTERCOM_') ? $e->getMessage() : 'COM_INTERCOM_INVALID_SETTINGS'), 'error');
        }
        $this->setRedirect('index.php?option=com_intercom');
    }

    public function connect(): void
    {
        $r = $this->runtime();
        $app = Factory::getApplication();
        $values = $r->connection->credentials();
        if (empty($values['client_id']) || empty($values['client_secret']) || $values['client_id'] === 'fixture-id') {
            $app->enqueueMessage(Text::_('COM_INTERCOM_NOT_CONNECTED'), 'error');
            $this->setRedirect('index.php?option=com_intercom');
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
        $this->setRedirect('index.php?option=com_intercom');
    }
}
