<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Stubs;

/**
 * Test double for the official integration app's service (v4.9.3).
 *
 * Same public `request()` signature as the real
 * `OCA\OpenProject\Service\OpenProjectAPIService`; the handler closure plays
 * OpenProject. `OpenProjectClient` only needs the object the container hands
 * it to have that method, so this class does not extend the real one — the
 * real constructor wants the whole app wired up. When the real app is absent
 * from the container, tests/bootstrap.php aliases this class to the real
 * name so `class_exists()` in the client answers true either way.
 */
class OpenProjectAPIService {

    /** @var callable(string, string, array, string): mixed */
    private $handler;

    private bool $oidcUser;

    public function __construct(?callable $handler = null, bool $oidcUser = false) {
        $this->handler  = $handler ?? static fn (): array => ['error' => 'no handler', 'statusCode' => 500];
        $this->oidcUser = $oidcUser;
    }

    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET') {
        return ($this->handler)($userId, $endPoint, $params, $method);
    }

    public function isOIDCUser(): bool {
        return $this->oidcUser;
    }
}
