<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class Policy
{
    public const TYPES = ['club', 'class', 'license', 'newsletter', 'offer'];

    public function __construct(private array $grants, private array $scope, private ?array $definitions = null)
    {
    }

    public static function audienceScope(array $userGroups, array $rules): array
    {
        $all = false;
        $tags = [];
        foreach ($rules as $rule) {
            if (in_array((int) ($rule['group'] ?? 0), $userGroups, true)) {
                $all = $all || ($rule['all'] ?? false) === true;
                $tags = array_merge($tags, (array) ($rule['tags'] ?? []));
            }
        }
        return ['all' => $all, 'tags' => array_values(array_unique($tags))];
    }

    public function assertAllowed(string $type, array $tags, string $action): void
    {
        if (
            !array_key_exists($type, $this->definitions ?? array_fill_keys(self::TYPES, [])) || !($this->grants['access'] ?? false)
            || !($this->grants[$action] ?? false) || !($this->grants[$type] ?? false)
            || ($action === 'send' && !($this->grants['send_' . $type] ?? $this->grants[$type] ?? false))
        ) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        if (($this->definitions[$type]['require_group'] ?? ($type === 'class')) && $tags === []) {
            throw new \RuntimeException('COM_INTERCOM_GROUP_REQUIRED', 422);
        }
        if (!($this->scope['all'] ?? false) && ($tags === [] || array_diff($tags, $this->scope['tags'] ?? []) !== [])) {
            throw new \RuntimeException('COM_INTERCOM_SCOPE_DENIED', 403);
        }
    }

    public function assertManageDraft(): void
    {
        if (!($this->grants['access'] ?? false) || !($this->grants['compose'] ?? false)) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
    }

    public function scope(): array
    {
        return $this->scope;
    }

    public function types(): array
    {
        return array_values(array_filter(array_keys($this->definitions ?? array_fill_keys(self::TYPES, [])), fn ($type) => $this->grants[$type] ?? false));
    }
}
