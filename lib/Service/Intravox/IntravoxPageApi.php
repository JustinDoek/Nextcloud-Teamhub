<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\Intravox;

use Psr\Container\ContainerInterface;

/**
 * The one place TeamHub names anything inside IntraVox (v4.13.55).
 *
 * ## Why this class exists
 *
 * TeamHub drives IntraVox in-process through Nextcloud's DI container, never
 * over HTTP (CLAUDE.md forbids calling back into the same instance, and the
 * NC28+ loopback block would refuse it anyway). Until IntraVox 3.0.0 that meant
 * one class, `OCA\IntraVox\Service\PageService`, named in five places across
 * TeamHub.
 *
 * **IntraVox 3.0.0 (2026-09-16) deleted that class** and split the page engine
 * into one service per concern. Nothing we call changed shape; everything we
 * call moved, and two methods were renamed. Every one of those five call sites
 * was therefore asking the container for a class that no longer exists.
 *
 * Collecting the names here means the next time IntraVox reorganises, one file
 * changes instead of five, and a reader can see the whole surface we depend on
 * without grepping for it. That is the entire job of this class: it adds no
 * behaviour, validates nothing, and deliberately passes arguments and return
 * values straight through.
 *
 * ## The map
 *
 * | What we need          | IntraVox ≤ 2.7.1 (facade) | IntraVox ≥ 3.0.0            |
 * |-----------------------|---------------------------|-----------------------------|
 * | list every page       | `PageService::listPages`  | `Listing\PageLister::listAll` |
 * | one page by id        | `PageService::getPage`    | `Read\PageReadService::getPage` |
 * | create a page         | `PageService::createPage` | `Write\PageWriteService::createPage` |
 * | write a page's content| `PageService::updatePage` | `Write\PageWriteService::updatePage` |
 * | delete a page         | `PageService::deletePage` | `Write\PageWriteService::deletePage` |
 * | a page's ancestors    | `PageService::getBreadcrumb` | `Path\BreadcrumbService::build` |
 *
 * **Both generations are supported on purpose.** TeamHub ships to instances we
 * do not control, and an instance still on IntraVox 2.7.1 is not a bug to be
 * fixed by breaking it. The generation is decided once, by asking whether the
 * old facade still exists, and every call then goes one way or the other.
 *
 * ## Why the container and not `new`
 *
 * These services are registered in IntraVox's *own* app container with explicit
 * factories, because several of them cannot be autowired: `FolderContext` takes
 * a `?string $userId` with no default, and IntraVox 3.0.0 made it refuse to be
 * built by a container that fills its test seams (their changelog, "Pages
 * loaded again after the split"). Nextcloud's `ServerContainer` routes a
 * request for an `OCA\<App>\…` class to that app's container, so asking for the
 * class by name from here gets IntraVox's own correctly-built instance. Calling
 * `new` on any of them would reproduce exactly the bug IntraVox fixed.
 *
 * ## What this class does NOT paper over
 *
 * Argument shapes are IntraVox's, not ours. `createPage()` still requires both
 * `id` and `title` in `$data` and still ignores `$data['layout']` (DESIGN
 * §2.38), `updatePage()` still rebuilds the whole page and still needs `title`
 * in every call, and `deletePage()` still takes the slug (3.x also accepts a
 * `page-…` uniqueId, 2.x does not, so we keep passing the slug). Those are
 * documented where they are relied on, not hidden here.
 */
final class IntravoxPageApi {

    /** The pre-3.0.0 facade. Its absence is how we detect a 3.x install. */
    public const LEGACY_FACADE = '\\OCA\\IntraVox\\Service\\PageService';

    private const GEN3_LISTER     = '\\OCA\\IntraVox\\Service\\Listing\\PageLister';
    private const GEN3_READER     = '\\OCA\\IntraVox\\Service\\Read\\PageReadService';
    private const GEN3_WRITER     = '\\OCA\\IntraVox\\Service\\Write\\PageWriteService';
    private const GEN3_BREADCRUMB = '\\OCA\\IntraVox\\Service\\Path\\BreadcrumbService';

    private function __construct(
        private ContainerInterface $container,
        private bool $legacy,
    ) {}

    /**
     * Bind to whichever generation of IntraVox this instance has.
     *
     * Returns null when IntraVox is absent or disabled — a disabled app has no
     * autoloader registered, so `class_exists()` is false for both generations
     * and nothing here can be reached. Callers treat null as "skip", exactly as
     * they treated a missing app before.
     */
    public static function bind(ContainerInterface $container): ?self {
        if (class_exists(self::LEGACY_FACADE)) {
            return self::forGeneration($container, true);
        }
        if (class_exists(self::GEN3_WRITER)) {
            return self::forGeneration($container, false);
        }
        return null;
    }

    /**
     * Bind to a named generation instead of detecting one.
     *
     * The detection in `bind()` is a `class_exists()` on classes that only a
     * real IntraVox install provides, and both generations cannot be present in
     * one process — so this is the only way to exercise both routings in a test
     * run. It is a seam, which IntraVox's own 3.0.0 release is a cautionary
     * tale about, so note what it is not: it does not construct anything, it
     * does not substitute a collaborator, and every service still comes from
     * the container. The worst a wrong value can do is ask the container for a
     * class name that does not exist on this instance, which throws where the
     * caller already catches.
     */
    public static function forGeneration(ContainerInterface $container, bool $legacy): self {
        return new self($container, $legacy);
    }

    /**
     * 'legacy' for IntraVox ≤ 2.7.1, 'split' for ≥ 3.0.0. Reported by the admin
     * diagnostic so a support question can be answered without a shell.
     */
    public function generation(): string {
        return $this->legacy ? 'legacy' : 'split';
    }

    /**
     * The classes this instance actually binds to, for the admin diagnostic.
     *
     * @return array<string, string>
     */
    public function boundClasses(): array {
        if ($this->legacy) {
            return ['pages' => self::LEGACY_FACADE];
        }
        return [
            'list'       => self::GEN3_LISTER,
            'read'       => self::GEN3_READER,
            'write'      => self::GEN3_WRITER,
            'breadcrumb' => self::GEN3_BREADCRUMB,
        ];
    }

    // -------------------------------------------------------------------------
    // The surface TeamHub uses
    // -------------------------------------------------------------------------

    /**
     * Every page in the reader's language, as `uniqueId` + `title` + `modified`
     * + `status` + `permissions`.
     *
     * **Still no `path`**, in either generation, which is why `getTeamPage()`
     * shortlists by title here and then confirms each candidate with
     * `getPage()` (DESIGN §2.41). Checked against IntraVox 3.1.2's
     * `PageLister::listAll()`, which returns the same shape the facade did.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPages(): array {
        $result = $this->legacy
            ? $this->service(self::LEGACY_FACADE)->listPages()
            : $this->service(self::GEN3_LISTER)->listAll();

        return is_array($result) ? $result : [];
    }

    /** One page by uniqueId or slug, including its `path`. */
    public function getPage(string $id): array {
        $result = $this->legacy
            ? $this->service(self::LEGACY_FACADE)->getPage($id)
            : $this->service(self::GEN3_READER)->getPage($id);

        return is_array($result) ? $result : [];
    }

    /**
     * Create a page shell. `$data` must carry `id` and `title`; `layout` is
     * ignored on create in both generations, so content follows in
     * `updatePage()` (DESIGN §2.38).
     *
     * `$parentPath` is passed explicitly rather than relying on a default:
     * 3.x dropped the `= null` the facade had, so a one-argument call is now an
     * `ArgumentCountError`.
     */
    public function createPage(array $data, ?string $parentPath): array {
        $result = $this->legacy
            ? $this->service(self::LEGACY_FACADE)->createPage($data, $parentPath)
            : $this->service(self::GEN3_WRITER)->createPage($data, $parentPath);

        return is_array($result) ? $result : [];
    }

    /**
     * Replace a page's content. `$data` must always include `title`, in both
     * generations: the sanitiser calls `sanitizeText($data['title'])` with no
     * null check, so omitting it is a TypeError rather than a no-op.
     */
    public function updatePage(string $id, array $data): array {
        $result = $this->legacy
            ? $this->service(self::LEGACY_FACADE)->updatePage($id, $data)
            : $this->service(self::GEN3_WRITER)->updatePage($id, $data);

        return is_array($result) ? $result : [];
    }

    /** Delete a page. We pass the slug, which both generations resolve. */
    public function deletePage(string $id): void {
        if ($this->legacy) {
            $this->service(self::LEGACY_FACADE)->deletePage($id);
            return;
        }
        $this->service(self::GEN3_WRITER)->deletePage($id);
    }

    /**
     * A page's ancestors, outermost first. Renamed in 3.x from `getBreadcrumb`
     * to `build` and moved onto a service of its own.
     *
     * @return array<int, array<string, mixed>>
     */
    public function breadcrumb(string $id): array {
        $result = $this->legacy
            ? $this->service(self::LEGACY_FACADE)->getBreadcrumb($id)
            : $this->service(self::GEN3_BREADCRUMB)->build($id);

        return is_array($result) ? $result : [];
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve one IntraVox service through Nextcloud's container, which routes
     * an `OCA\IntraVox\…` name to IntraVox's own app container and its own
     * factories. Never cached on this object: these services hold per-request
     * folder and language state, and IntraVox's container already decides what
     * is shared and what is not.
     */
    private function service(string $class): object {
        /** @var object */
        return $this->container->get(ltrim($class, '\\'));
    }
}
