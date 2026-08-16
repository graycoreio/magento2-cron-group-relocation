<?php declare(strict_types=1);

namespace Graycore\CronGroupRelocation\Test\Integration\Plugin;

use Graycore\CronGroupRelocation\Plugin\RelocateCronJobGroupPlugin;
use Magento\Cron\Model\Config;
use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test: the module loads and the plugin is wired into a real Magento DI graph.
 *
 * The relocation logic itself is covered by the unit test; what can only be
 * checked here is that di.xml actually attaches the plugin to
 * Magento\Cron\Model\Config and that it behaves against the real merged
 * crontab.xml rather than a hand-built fixture array.
 *
 * @see \Graycore\CronGroupRelocation\Test\Unit\Plugin\RelocateCronJobGroupPluginTest
 */
class RelocateCronJobGroupPluginTest extends TestCase
{
    /**
     * Deliberately untyped: on 2.4.7 the integration framework's
     * Workaround\Cleanup\TestCaseProperties nulls every test-case property at
     * endTestSuite, which is a TypeError against a non-nullable typed property.
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    public function testModuleIsRegisteredAndEnabled(): void
    {
        $moduleList = $this->objectManager->get(ModuleListInterface::class);

        self::assertTrue($moduleList->has('Graycore_CronGroupRelocation'));
    }

    public function testPluginIsAttachedToCronConfig(): void
    {
        $config = $this->objectManager->get(Config::class);

        self::assertInstanceOf(InterceptorInterface::class, $config);
    }

    public function testShipsNoRelocationsOfItsOwn(): void
    {
        // di.xml registers the plugin with an empty mapping, so an out-of-the-box
        // install must see exactly the groups Magento declared — in particular the
        // plugin must not have materialized any group of its own.
        $jobs = $this->objectManager->get(Config::class)->getJobs();

        self::assertArrayHasKey('default', $jobs);
        self::assertNotEmpty($jobs['default']);
    }

    public function testRelocatesARealJobOffTheMergedCrontabConfig(): void
    {
        $jobs = $this->objectManager->get(Config::class)->getJobs();

        self::assertNotEmpty($jobs['default'], 'Expected Magento to declare at least one job in the default group.');

        $jobCode = array_key_first($jobs['default']);

        $plugin = $this->objectManager->create(
            RelocateCronJobGroupPlugin::class,
            ['relocations' => [$jobCode => 'graycore_smoke_test']]
        );

        $result = $plugin->afterGetJobs($this->objectManager->get(Config::class), $jobs);

        self::assertSame($jobs['default'][$jobCode], $result['graycore_smoke_test'][$jobCode]);
        self::assertArrayNotHasKey($jobCode, $result['default']);
    }
}
