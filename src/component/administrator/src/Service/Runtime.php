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

    public function __construct(public readonly Store $store, string $secret)
    {
        $this->connection = new Connection($store, new CredentialCipher($secret));
        $this->config = ComponentHelper::getParams('com_intercom')->toArray();
    }

    public function policy(User $user): Policy
    {
        $grants = [];
        foreach (array_merge(['access', 'compose', 'send'], Policy::TYPES) as $action) {
            $grants[$action] = $user->authorise('intercom.' . $action, 'com_intercom');
        }
        $rules = json_decode($this->config['audience_rules'] ?? '[]', true) ?: [];
        $scope = Policy::audienceScope(array_map('intval', $user->getAuthorisedGroups()), $rules);
        if ($user->authorise('core.admin', 'com_intercom')) {
            $scope = ['all' => true, 'tags' => []];
        }
        return new Policy($grants, $scope);
    }

    public function gateway(): DeliveryGateway
    {
        if (($this->config['mode'] ?? 'fake') === 'fake') {
            return new FakeGateway();
        }
        return new CleverReachGateway(fn () => $this->connection->token(), $this->config);
    }

    public function workflow(User $user): Workflow
    {
        return new Workflow($this->store, $this->gateway(), $this->policy($user), (int) $user->id);
    }
}
