<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Controller\WorkflowArchiveController;
use OCA\TeamHub\Service\Workflow\WorkflowArchiveService;
use OCA\TeamHub\Service\Workflow\WorkflowAttachmentService;
use OCA\TeamHub\Workflow\WorkflowArchiveAudience;
use PHPUnit\Framework\TestCase;

/**
 * The archive controller's shape (WorkflowHub phase 6, v4.10.21).
 *
 * Nextcloud applies the permission attributes, not the controller, so no
 * functional test exercises them; they are audited by reflection the way
 * `ServiceTeamControllerTest` audits the Service Team routes. Three claims
 * the file exists to keep true:
 *
 *   1. **Every route is `#[NoAdminRequired]`, CSRF-protected and not
 *      public.** An archive belongs to the team that asked and the desk
 *      that answered — a Nextcloud administrator reads the requesting
 *      team's projection like any other participant, through the service,
 *      and never reads the desk's.
 *   2. **The audience defaults to the requesting team.** A caller who
 *      names no audience gets the reading that carries no internal note;
 *      the wider one is always asked for explicitly.
 *   3. **The controller decides nothing.** It holds no mapper and no
 *      resolver, so there is nowhere in it for a second, weaker copy of
 *      the permission rule to appear.
 */
class WorkflowArchiveControllerTest extends TestCase {

    private const METHODS = ['index', 'show', 'record', 'byInstance', 'attachments', 'attach', 'detach'];

    public function testEveryArchiveRouteIsMemberCallableAndCsrfProtected(): void {
        $rc = new \ReflectionClass(WorkflowArchiveController::class);
        foreach (self::METHODS as $method) {
            $attrs = $this->attributesOf($rc, $method);
            self::assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\NoCSRFRequired', $attrs, $method);
            self::assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $attrs, $method);
        }
    }

    public function testTheDefaultAudienceIsTheOneThatCarriesNoInternalNote(): void {
        $rc = new \ReflectionClass(WorkflowArchiveController::class);
        foreach (['show', 'record', 'byInstance'] as $method) {
            $parameters = [];
            foreach ($rc->getMethod($method)->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }
            self::assertArrayHasKey('audience', $parameters, $method);
            self::assertTrue($parameters['audience']->isDefaultValueAvailable(), $method);
            self::assertSame(
                WorkflowArchiveAudience::REQUESTING_TEAM,
                $parameters['audience']->getDefaultValue(),
                $method . ': an unnamed audience must be the narrow one',
            );
        }
    }

    public function testTheControllerHoldsNoMapperAndNoResolver(): void {
        $rc  = new \ReflectionClass(WorkflowArchiveController::class);
        $own = [];
        foreach ($rc->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $own[] = $type->getName();
            }
        }

        foreach ($own as $dependency) {
            self::assertStringNotContainsString('\\Db\\', $dependency, 'a controller that holds a mapper can query around a rule');
            self::assertStringNotContainsString('ActorResolver', $dependency);
            self::assertStringNotContainsString('ServiceTeamService', $dependency);
        }
        // The two services that own the rules, and nothing else that could
        // answer a permission question.
        self::assertContains(WorkflowArchiveService::class, $own);
        self::assertContains(WorkflowAttachmentService::class, $own);
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
