<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service\ServiceTeam;

use OCA\TeamHub\AppInfo\Application;
use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Constants\ServiceIcons;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCA\TeamHub\Db\TeamServiceMapper;
use OCA\TeamHub\Exception\ValidationException;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The service catalog's categories and its two links, as a Nextcloud
 * administrator sets them on Settings → TeamHub → Services (v4.10.45).
 *
 * **Categories.** Until v4.10.45 the five categories were code
 * (`ServiceCatalogue::CATEGORIES`). Justin, 2026-09-25: an administrator
 * adds, renames, reorders and removes them. They are stored as one ordered
 * list in the app config (`serviceCategories`), each `{ key, label, icon }`:
 *
 *   - An instance that never saved the list reads the five built-ins, in
 *     their order, translated — nothing changes until somebody edits.
 *   - A built-in keeps its key. Its `label` is `''` while the administrator
 *     has not renamed it, and is then translated in the reader's language;
 *     a renamed built-in reads the administrator's words in every language,
 *     like a category the administrator added.
 *   - A category an administrator adds gets a generated key (`c_<hex>`).
 *
 * **A category a service is in cannot be removed** (Justin, 2026-09-25:
 * block, don't move). `usage()` counts the Nextcloud services a desk offers
 * and every service a team built, published or draft. A category that has
 * gone anyway — the Nextcloud services are claimed after their category was
 * removed while nobody offered them — is read as the first category, so a
 * service is never filed nowhere.
 *
 * **The links.** A service desk and a knowledge portal, shown under the
 * catalog. `https://` only, like every stored URL in the app.
 */
class ServiceCategoryService {

    public const CFG_CATEGORIES      = 'serviceCategories';
    public const CFG_SERVICE_DESK    = 'serviceDeskUrl';
    public const CFG_KNOWLEDGE_BASE  = 'knowledgePortalUrl';

    public const MAX_CATEGORIES = 20;
    public const MAX_LABEL      = 64;
    public const MAX_URL        = 2000;

    /** @var list<array{key: string, label: string, icon: string}>|null memoised per request */
    private ?array $stored = null;

    public function __construct(
        private IAppConfig                $appConfig,
        private ServiceTeamMapper         $teams,
        private ServiceCatalogEntryMapper $catalog,
        private TeamServiceMapper         $builtServices,
        private IL10N                     $l,
        private LoggerInterface           $logger,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // Categories — reads
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The categories in the administrator's order, each with the words the
     * reader reads.
     *
     * @return list<array{key: string, label: string, icon: string, builtIn: bool, customLabel: string}>
     */
    public function list(): array {
        $out = [];
        foreach ($this->stored() as $row) {
            $builtIn = in_array($row['key'], ServiceCatalogue::CATEGORIES, true);
            $out[] = [
                'key'         => $row['key'],
                'label'       => $row['label'] !== '' ? $row['label'] : ServiceCatalogue::categoryLabel($this->l, $row['key']),
                'icon'        => $row['icon'],
                'builtIn'     => $builtIn,
                'customLabel' => $row['label'],
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public function keys(): array {
        return array_column($this->stored(), 'key');
    }

    public function exists(string $key): bool {
        return $key !== '' && in_array($key, $this->keys(), true);
    }

    /** The category a service is filed under: the one it names, or the first. */
    public function resolve(string $wanted): string {
        if ($this->exists($wanted)) {
            return $wanted;
        }
        $keys = $this->keys();
        return $keys[0] ?? ServiceCatalogue::CATEGORY_SUPPORT;
    }

    /** @return array{key: string, label: string, icon: string} for a resolved key */
    public function describe(string $key): array {
        foreach ($this->list() as $row) {
            if ($row['key'] === $key) {
                return ['key' => $row['key'], 'label' => $row['label'], 'icon' => $row['icon']];
            }
        }
        return ['key' => $key, 'label' => ServiceCatalogue::categoryLabel($this->l, $key), 'icon' => ServiceIcons::DEFAULT];
    }

    /**
     * How many services are in each category: the Nextcloud services a desk
     * offers, and every service a team built — its published and its draft
     * category, each counted once.
     *
     * @return array<string, int>
     */
    public function usage(): array {
        $counts = array_fill_keys($this->keys(), 0);
        $bump = static function (string $key) use (&$counts): void {
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        };
        foreach ($this->teams->findAll(true) as $team) {
            foreach ($this->catalog->findByTeam($team->getTeamId(), true) as $entry) {
                if (!in_array($entry->getServiceKey(), ServiceCatalogue::SERVICES, true)) {
                    continue;
                }
                $bump($this->resolve(ServiceCatalogue::categoryFor($entry->getServiceKey())));
            }
        }
        foreach ($this->builtServices->findAllServices() as $row) {
            $seen = [];
            foreach ([$row->publishedDocument(), $row->draftDocument()] as $doc) {
                $category = (string)($doc['category'] ?? '');
                if ($category === '' || isset($seen[$category])) {
                    continue;
                }
                $seen[$category] = true;
                $bump($category);
            }
        }
        return $counts;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Categories — the administrator's write
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Replace the list. Each row is `{ key?, label, icon }`: a row without a
     * key is a new category. A built-in whose label equals its translated
     * name is stored as not renamed, so it stays translated.
     *
     * The caller is a Nextcloud administrator (`#[AuthorizedAdminSetting]`).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{key: string, label: string, icon: string, builtIn: bool, customLabel: string}>
     * @throws ValidationException
     */
    public function save(array $rows): array {
        if ($rows === []) {
            throw new ValidationException($this->l->t('Keep at least one category.'));
        }
        if (count($rows) > self::MAX_CATEGORIES) {
            throw new ValidationException($this->l->t('A catalog has at most %d categories.', [self::MAX_CATEGORIES]));
        }
        $existing = $this->keys();
        $out      = [];
        $seen     = [];
        $labels   = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ValidationException($this->l->t('A category could not be read.'));
            }
            $key   = trim((string)($row['key'] ?? ''));
            $label = trim((string)($row['label'] ?? ''));
            $icon  = trim((string)($row['icon'] ?? ''));
            $builtIn = in_array($key, ServiceCatalogue::CATEGORIES, true);

            if ($key === '') {
                $key = 'c_' . bin2hex(random_bytes(4));
            } elseif (!in_array($key, $existing, true) && !$builtIn) {
                throw new ValidationException($this->l->t('A category could not be read.'));
            }
            if (isset($seen[$key])) {
                throw new ValidationException($this->l->t('A category is listed twice.'));
            }
            $seen[$key] = true;

            if ($builtIn && ($label === '' || $label === ServiceCatalogue::categoryLabel($this->l, $key))) {
                $label = '';
            } elseif ($label === '') {
                throw new ValidationException($this->l->t('Every category needs a name.'));
            }
            if (mb_strlen($label) > self::MAX_LABEL || preg_match('/[\x00-\x1F\x7F]/', $label)) {
                throw new ValidationException($this->l->t('A category name is at most %d characters.', [self::MAX_LABEL]));
            }
            $shown = mb_strtolower($label !== '' ? $label : ServiceCatalogue::categoryLabel($this->l, $key));
            if (isset($labels[$shown])) {
                throw new ValidationException($this->l->t('Two categories have the same name.'));
            }
            $labels[$shown] = true;

            if ($icon === '' || !ServiceIcons::isAllowed($icon)) {
                $icon = self::defaultIcon($key);
            }
            $out[] = ['key' => $key, 'label' => $label, 'icon' => $icon];
        }

        // Block, don't move: a category a service is in stays.
        $removed = array_diff($existing, array_keys($seen));
        if ($removed !== []) {
            $usage = $this->usage();
            foreach ($removed as $key) {
                if (($usage[$key] ?? 0) > 0) {
                    $name = $this->describe($key)['label'];
                    throw new ValidationException($this->l->n(
                        '%1$s still has %2$d service. Move it to another category first.',
                        '%1$s still has %2$d services. Move them to another category first.',
                        $usage[$key],
                        [$name, $usage[$key]],
                    ));
                }
            }
        }

        $this->appConfig->setValueString(Application::APP_ID, self::CFG_CATEGORIES, json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->stored = $out;
        return $this->list();
    }

    // ──────────────────────────────────────────────────────────────────────
    // The links under the catalog
    // ──────────────────────────────────────────────────────────────────────

    /** @return array{serviceDesk: string, knowledgePortal: string} */
    public function links(): array {
        return [
            'serviceDesk'     => $this->appConfig->getValueString(Application::APP_ID, self::CFG_SERVICE_DESK, ''),
            'knowledgePortal' => $this->appConfig->getValueString(Application::APP_ID, self::CFG_KNOWLEDGE_BASE, ''),
        ];
    }

    /**
     * Set either link; `''` removes it. `https://` only.
     *
     * @param array<string, mixed> $input
     * @return array{serviceDesk: string, knowledgePortal: string}
     * @throws ValidationException
     */
    public function saveLinks(array $input): array {
        $map = ['serviceDesk' => self::CFG_SERVICE_DESK, 'knowledgePortal' => self::CFG_KNOWLEDGE_BASE];
        $clean = [];
        foreach ($map as $field => $configKey) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $clean[$configKey] = self::cleanUrl((string)$input[$field], $this->l);
        }
        foreach ($clean as $configKey => $url) {
            $this->appConfig->setValueString(Application::APP_ID, $configKey, $url);
        }
        return $this->links();
    }

    /** @throws ValidationException */
    public static function cleanUrl(string $url, IL10N $l): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (mb_strlen($url) > self::MAX_URL
            || preg_match('/[\x00-\x20\x7F]/', $url)
            || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || (string)($parts['host'] ?? '') === '') {
            throw new ValidationException($l->t('Enter a web address that starts with https://'));
        }
        return $url;
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @return list<array{key: string, label: string, icon: string}> */
    private function stored(): array {
        if ($this->stored !== null) {
            return $this->stored;
        }
        $raw  = $this->appConfig->getValueString(Application::APP_ID, self::CFG_CATEGORIES, '');
        $rows = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    $key = is_array($row) ? (string)($row['key'] ?? '') : '';
                    if ($key === '' || isset($rows[$key])) {
                        continue;
                    }
                    $icon = (string)($row['icon'] ?? '');
                    $rows[$key] = [
                        'key'   => $key,
                        'label' => (string)($row['label'] ?? ''),
                        'icon'  => ServiceIcons::isAllowed($icon) ? $icon : self::defaultIcon($key),
                    ];
                }
            } else {
                $this->logger->warning('[TeamHub][ServiceCategoryService] stored categories could not be read; using the built-ins', ['app' => Application::APP_ID]);
            }
        }
        if ($rows === []) {
            foreach (ServiceCatalogue::CATEGORIES as $key) {
                $rows[$key] = ['key' => $key, 'label' => '', 'icon' => self::defaultIcon($key)];
            }
        }
        return $this->stored = array_values($rows);
    }

    /** The icon a built-in category had before icons could be chosen. */
    public static function defaultIcon(string $key): string {
        return match ($key) {
            ServiceCatalogue::CATEGORY_TEAMS  => 'AccountGroupOutline',
            ServiceCatalogue::CATEGORY_ACCESS => 'AccountKeyOutline',
            ServiceCatalogue::CATEGORY_FILES  => 'FolderAccountOutline',
            ServiceCatalogue::CATEGORY_APPS   => 'ViewGridOutline',
            default                           => ServiceIcons::DEFAULT,
        };
    }
}
