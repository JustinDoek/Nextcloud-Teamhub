<?php
declare(strict_types=1);

namespace OCA\TeamHub\Tests\Unit\Workflow;

use OCA\TeamHub\Constants\ServiceCatalogue;
use OCA\TeamHub\Db\ServiceCatalogEntry;
use OCA\TeamHub\Db\ServiceCatalogEntryMapper;
use OCA\TeamHub\Db\ServiceTeam;
use OCA\TeamHub\Db\ServiceTeamMapper;
use OCA\TeamHub\Db\TeamService;
use OCA\TeamHub\Exception\ValidationException;
use OCA\TeamHub\Service\ServiceTeam\ServiceCategoryService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * v4.10.45 — the service catalog's categories and links, as a Nextcloud
 * administrator sets them on Settings → TeamHub → Services.
 */
class ServiceCategoryServiceTest extends TestCase {

    /** @var array<string, string> the app config */
    private array $stored = [];
    /** @var list<string> service keys a desk offers */
    private array $offered = [];
    private InMemoryTeamServiceMapper $built;

    protected function setUp(): void {
        $this->built = new InMemoryTeamServiceMapper();
    }

    private function service(): ServiceCategoryService {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
        $config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
            $this->stored[$key] = $value;
            return true;
        });

        $desk = new ServiceTeam();
        $desk->setTeamId('desk');
        $teams = $this->createMock(ServiceTeamMapper::class);
        $teams->method('findAll')->willReturn($this->offered === [] ? [] : [$desk]);

        $catalog = $this->createMock(ServiceCatalogEntryMapper::class);
        $catalog->method('findByTeam')->willReturnCallback(fn (): array => array_map(static function (string $key): ServiceCatalogEntry {
            $entry = new ServiceCatalogEntry();
            $entry->setServiceKey($key);
            return $entry;
        }, $this->offered));

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $params = []): string => vsprintf($count === 1 ? $one : $many, $params));

        return new ServiceCategoryService($config, $teams, $catalog, $this->built, $l, $this->createMock(LoggerInterface::class));
    }

    /** @return list<array{key: string, label: string, icon: string}> */
    private function asSaved(): array {
        return array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'icon' => $c['icon']], $this->service()->list());
    }

    public function testNothingStoredIsTheBuiltInsTranslated(): void {
        $list = $this->service()->list();
        $this->assertSame(ServiceCatalogue::CATEGORIES, array_column($list, 'key'));
        $this->assertSame('Teams and spaces', $list[0]['label']);
        $this->assertSame('', $list[0]['customLabel']);
        $this->assertSame('AccountGroupOutline', $list[0]['icon']);
    }

    public function testAnAdministratorAddsRenamesAndReorders(): void {
        $rows = $this->asSaved();
        $rows[0]['label'] = 'Teams';
        $rows[1]['icon']  = 'Skull';
        $rows[] = ['label' => 'Printing', 'icon' => 'Printer'];
        $saved = $this->service()->save(array_reverse($rows));

        $this->assertSame('Printing', $saved[0]['label']);
        $this->assertMatchesRegularExpression('/^c_[0-9a-f]{8}$/', $saved[0]['key']);
        $this->assertSame('Printer', $saved[0]['icon']);
        $teams = $saved[count($saved) - 1];
        $this->assertSame([ServiceCatalogue::CATEGORY_TEAMS, 'Teams', 'Teams'], [$teams['key'], $teams['label'], $teams['customLabel']]);
        $this->assertSame('AccountKeyOutline', $saved[count($saved) - 2]['icon'], 'an icon nobody offers falls back');
        $this->assertTrue($this->service()->exists($saved[0]['key']));
    }

    public function testABuiltInKeepsItsTranslationUntilRenamed(): void {
        $saved = $this->service()->save($this->asSaved());
        $this->assertSame('', $saved[2]['customLabel'], 'saving the translated name is not a rename');
    }

    public function testACategoryAServiceIsInCannotBeRemoved(): void {
        $this->offered = [ServiceCatalogue::NEW_TEAM];
        $rows = array_values(array_filter($this->asSaved(), static fn (array $c): bool => $c['key'] !== ServiceCatalogue::CATEGORY_TEAMS));
        try {
            $this->service()->save($rows);
            $this->fail('removed a category a service is in');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Teams and spaces', $e->getMessage());
        }

        // Nor one a team's draft is in.
        $row = new TeamService();
        $row->setTeamId('desk');
        $row->setDraft(json_encode(['category' => ServiceCatalogue::CATEGORY_APPS]));
        $this->built->insert($row);
        $this->assertSame(1, $this->service()->usage()[ServiceCatalogue::CATEGORY_APPS]);

        // An empty one goes.
        $rows = array_values(array_filter($this->asSaved(), static fn (array $c): bool => $c['key'] !== ServiceCatalogue::CATEGORY_ACCESS));
        $this->assertCount(4, $this->service()->save($rows));
        $this->assertSame(ServiceCatalogue::CATEGORY_TEAMS, $this->service()->resolve(ServiceCatalogue::CATEGORY_ACCESS), 'a removed one reads as the first');
    }

    public function testAtLeastOneNamedCategoryWithoutDoubles(): void {
        foreach ([[], [['label' => ''] + ['icon' => '']], [['label' => 'A'], ['label' => 'a']]] as $rows) {
            try {
                $this->service()->save($rows);
                $this->fail('accepted ' . json_encode($rows));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheLinksAreHttpsOnly(): void {
        $this->assertSame(
            ['serviceDesk' => 'https://desk.example.org/', 'knowledgePortal' => ''],
            $this->service()->saveLinks(['serviceDesk' => ' https://desk.example.org/ ']),
        );
        foreach (['javascript:alert(1)', 'http://desk.example.org', 'https://', 'https://a b'] as $bad) {
            try {
                $this->service()->saveLinks(['knowledgePortal' => $bad]);
                $this->fail('accepted ' . $bad);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('', $this->service()->saveLinks(['serviceDesk' => ''])['serviceDesk'], 'empty removes it');
    }
}
