<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Controller\ServiceTeamAdminController;
use OCA\TeamHub\Controller\ServiceTeamController;
use OCA\TeamHub\Controller\TeamServiceController;
use PHPUnit\Framework\TestCase;

/**
 * The permission attributes of the three Service Team controllers
 * (WorkflowHub phase 5, v4.10.20; the third added v4.10.23).
 *
 * The gates are attributes on methods, which no functional test exercises
 * — Nextcloud applies them, not the controller — so they are audited by
 * reflection, the same way `WorkflowControllerTest` audits the workflow
 * routes. Three rules, and the whole file exists for them:
 *
 *   1. **Every agent route is `#[NoAdminRequired]` and none is exempt from
 *      CSRF or public.** A service agent is an ordinary member with a job.
 *   2. **Every team route is `#[NoAdminRequired]` too.** Claiming the
 *      Nextcloud services is a team admin's act, and a Nextcloud
 *      administrator has no part in it; the team-admin and
 *      Service-template checks are the controller's own and are asserted
 *      by `TeamServiceControllerTest`.
 *   3. **No method of the admin controller carries `#[NoAdminRequired]`.**
 *      That absence *is* the Nextcloud-administrator gate, and it is a
 *      property of the class rather than of remembering — which is why it
 *      is a separate file at all.
 */
class ServiceTeamControllerTest extends TestCase {

    // v4.10.29 — quotaTeams: the quota card's team picker.
    private const AGENT_METHODS = ['index', 'catalogue', 'claimStatus', 'quotaTeams', 'queue', 'statistics', 'claim', 'assign', 'release', 'internalNote'];
    private const TEAM_METHODS  = ['show', 'claim', 'release'];
    // v4.10.23 — the setup flow is gone; what is left is the checklist's
    // read and the release that takes an instance-wide claim back.
    private const ADMIN_METHODS = ['index', 'destroy'];

    public function testEveryAgentRouteIsMemberCallableAndCsrfProtected(): void {
        $rc = new \ReflectionClass(ServiceTeamController::class);
        foreach (self::AGENT_METHODS as $method) {
            $attrs = $this->attributesOf($rc, $method);
            self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attrs, $method);
        }
    }

    public function testEveryTeamRouteIsMemberCallableAndCsrfProtected(): void {
        $rc = new \ReflectionClass(TeamServiceController::class);
        foreach (self::TEAM_METHODS as $method) {
            $attrs = $this->attributesOf($rc, $method);
            self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attrs, $method);
        }
    }

    public function testNoSetupRouteIsMemberCallable(): void {
        $rc = new \ReflectionClass(ServiceTeamAdminController::class);
        foreach (self::ADMIN_METHODS as $method) {
            $attrs = $this->attributesOf($rc, $method);
            self::assertNotContains(
                'OCP\AppFramework\Http\Attribute\NoAdminRequired',
                $attrs,
                $method . ': the missing attribute is the administrator gate',
            );
            self::assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attrs, $method);
        }
    }

    public function testTheTwoHalvesAreSeparateClasses(): void {
        // Folding the setup methods into the agent controller would put an
        // administrator-only method in a file where every other method is
        // #[NoAdminRequired] — one forgotten attribute from being public to
        // every member.
        self::assertNotSame(ServiceTeamController::class, ServiceTeamAdminController::class);
        foreach (self::ADMIN_METHODS as $method) {
            if (in_array($method, self::AGENT_METHODS, true)) {
                continue;
            }
            self::assertFalse(
                method_exists(ServiceTeamController::class, $method),
                $method . ' is a setup method and must not live on the agent controller',
            );
        }
    }

    /**
     * @param \ReflectionClass<object> $rc
     * @return string[]
     */
    private function attributesOf(\ReflectionClass $rc, string $method): array {
        return array_map(
            static fn (\ReflectionAttribute $a): string => $a->getName(),
            $rc->getMethod($method)->getAttributes(),
        );
    }
}
