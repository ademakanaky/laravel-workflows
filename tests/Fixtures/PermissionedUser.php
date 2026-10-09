<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

class PermissionedUser extends User
{
    protected $table = 'users';

    /** @var array<string, list<string>> */
    private static array $permissions = [];

    public function grant(string ...$permissions): self
    {
        self::$permissions[(string) $this->getKey()] = $permissions;

        return $this;
    }

    public function hasPermissionTo(string $permission): bool
    {
        return in_array($permission, self::$permissions[(string) $this->getKey()] ?? [], true);
    }
}
