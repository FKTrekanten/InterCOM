<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use FKT\Component\Intercom\Administrator\Domain\Policy;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\CleverReachGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\FakeGateway;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

final class Runtime
{
    public readonly Connection $connection;
    public readonly array $config;
    public readonly Catalog $catalog;
    public readonly Design $design;

    public function __construct(public readonly Store $store, string $secret)
    {
        $this->connection = new Connection($store, new CredentialCipher($secret));
        $this->config = ComponentHelper::getParams('com_intercom')->toArray();
        $this->catalog = new Catalog($store, $this->config);
        $this->design = new Design($store);
    }

    public function policy(User $user): Policy
    {
        $grants = [];
        foreach (['access', 'compose', 'send'] as $action) {
            $grants[$action] = $user->authorise('intercom.' . $action, 'com_intercom');
        }
        $definitions = $this->catalog->types();
        foreach ($definitions as $key => $definition) {
            $asset = 'com_intercom.communication.' . $definition['id'];
            $grants[$key] = $user->authorise('intercom.type.compose', $asset);
            $grants['send_' . $key] = $user->authorise('intercom.type.send', $asset);
        }
        $rules = json_decode($this->config['audience_rules'] ?? '[]', true) ?: [];
        $scope = Policy::audienceScope(array_map('intval', $user->getAuthorisedGroups()), $rules);
        if ($user->authorise('core.admin', 'com_intercom')) {
            $scope = ['all' => true, 'tags' => []];
        }
        return new Policy($grants, $scope, $definitions);
    }

    public function gateway(): DeliveryGateway
    {
        if (($this->config['mode'] ?? 'fake') === 'fake') {
            return new FakeGateway();
        }
        return new CleverReachGateway(fn () => $this->connection->token(), $this->config);
    }

    public function archive(): Archive
    {
        $gateway = $this->gateway();
        return new Archive(
            $this->store,
            (string) ($this->config['board_archive_email'] ?? ''),
            fn (int $id): bool => $gateway instanceof CleverReachGateway ? $gateway->finished($id) : false,
            static function (string $address, array $payload): bool {
                $app = \Joomla\CMS\Factory::getApplication();
                $mail = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer();
                $mail->setSender([$app->get('mailfrom'), $app->get('fromname')]);
                $mail->addRecipient($address);
                $mail->setSubject($payload['subject']);
                $mail->isHtml(true);
                $mail->setBody($payload['html']);
                $mail->AltBody = $payload['text'];
                return $mail->send() === true;
            }
        );
    }

    public function reconciliation(): ?Reconciliation
    {
        $gateway = $this->gateway();
        return $gateway instanceof \FKT\Component\Intercom\Administrator\Domain\ReconciliationGateway ? new Reconciliation($this->store, $gateway, $this->config) : null;
    }

    public function testDelivery(): TestDelivery
    {
        return new TestDelivery(static function (string $address, array $payload): bool {
            $app = \Joomla\CMS\Factory::getApplication();
            $mail = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer();
            $mail->setSender([$app->get('mailfrom'), $app->get('fromname')]);
            $mail->addRecipient($address);
            $mail->setSubject($payload['subject']);
            $mail->isHtml(true);
            $mail->setBody($payload['html']);
            $mail->AltBody = $payload['text'];
            return $mail->send() === true;
        });
    }

    public function workflow(User $user): Workflow
    {
        return new Workflow($this->store, $this->gateway(), $this->policy($user), (int) $user->id, $this->catalog, $this->archive(), $this->reconciliation(), $this->config, $this->testDelivery());
    }
}
