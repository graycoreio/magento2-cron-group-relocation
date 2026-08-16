<?php declare(strict_types=1);

namespace Graycore\CronGroupRelocation\Test\Unit\Plugin;

use Graycore\CronGroupRelocation\Plugin\RelocateCronJobGroupPlugin;
use Magento\Cron\Model\Config;
use PHPUnit\Framework\TestCase;

class RelocateCronJobGroupPluginTest extends TestCase
{
    private function job(string $instance, string $schedule = '0 0 * * *'): array
    {
        return ['name' => 'ignored', 'instance' => $instance, 'method' => 'execute', 'schedule' => $schedule];
    }

    private function plugin(array $relocations): RelocateCronJobGroupPlugin
    {
        return new RelocateCronJobGroupPlugin($relocations);
    }

    private function subject(): Config
    {
        return $this->createMock(Config::class);
    }

    public function testMovesJobToDestinationGroupAndRemovesItFromTheOriginal(): void
    {
        $slow = $this->job('Vendor\Module\Cron\SlowJob');

        $result = $this->plugin(['slow_job' => 'slow'])->afterGetJobs(
            $this->subject(),
            ['default' => ['slow_job' => $slow]]
        );

        self::assertSame(['slow_job' => $slow], $result['slow']);
        self::assertSame([], $result['default']);
    }

    public function testLeavesOtherJobsAndOtherGroupsAlone(): void
    {
        $urgent = $this->job('Vendor\Module\Cron\UrgentJob', '0 1 * * *');

        $result = $this->plugin(['slow_job' => 'slow'])->afterGetJobs(
            $this->subject(),
            [
                'default' => [
                    'slow_job' => $this->job('Vendor\Module\Cron\SlowJob'),
                    'urgent_job' => $urgent,
                ],
                'index' => ['some_index_job' => $this->job('Vendor\Module\Cron\IndexJob')],
            ]
        );

        self::assertSame(['urgent_job' => $urgent], $result['default']);
        self::assertArrayHasKey('some_index_job', $result['index']);
        self::assertArrayHasKey('slow_job', $result['slow']);
    }

    public function testCarriesTheWholeJobConfigAcrossSoDbScheduleOverridesSurvive(): void
    {
        // Config\Reader\Db merges core_config_data overrides in before getJobs()
        // returns, so whatever schedule is in effect must move with the job.
        $overridden = [
            'name' => 'slow_job',
            'instance' => 'Vendor\Module\Cron\SlowJob',
            'method' => 'execute',
            'schedule' => '30 2 * * *',
            'config_path' => 'crontab/default/jobs/slow_job/schedule/cron_expr',
        ];

        $result = $this->plugin(['slow_job' => 'slow'])->afterGetJobs(
            $this->subject(),
            ['default' => ['slow_job' => $overridden]]
        );

        self::assertSame($overridden, $result['slow']['slow_job']);
    }

    public function testDoesNotCreateTheDestinationGroupWhenNoRelocatedJobExists(): void
    {
        // e.g. the declaring module is disabled — the job code is simply absent,
        // and an empty group would still be locked and schedule-generated
        // against on every run.
        $result = $this->plugin(['absent_job' => 'slow'])->afterGetJobs(
            $this->subject(),
            ['default' => ['urgent_job' => $this->job('Vendor\Module\Cron\UrgentJob')]]
        );

        self::assertArrayNotHasKey('slow', $result);
    }

    public function testIsIdempotentWhenTheJobAlreadyLivesInTheDestinationGroup(): void
    {
        $input = ['slow' => ['slow_job' => $this->job('Vendor\Module\Cron\SlowJob')]];

        $result = $this->plugin(['slow_job' => 'slow'])->afterGetJobs($this->subject(), $input);

        self::assertSame($input, $result);
    }

    public function testGathersJobsDeclaredInDifferentSourceGroups(): void
    {
        $result = $this->plugin([
            'first_job' => 'slow',
            'second_job' => 'slow',
            'third_job' => 'slow',
        ])->afterGetJobs($this->subject(), [
            'default' => [
                'first_job' => $this->job('Vendor\One\Cron\FirstJob'),
                'keep_me' => $this->job('Vendor\One\Cron\KeepMe'),
            ],
            'index' => ['second_job' => $this->job('Vendor\Two\Cron\SecondJob')],
            'custom' => ['third_job' => $this->job('Vendor\Three\Cron\ThirdJob')],
        ]);

        self::assertSame(['first_job', 'second_job', 'third_job'], array_keys($result['slow']));
        self::assertSame(['keep_me'], array_keys($result['default']));
        self::assertSame([], $result['index']);
        self::assertSame([], $result['custom']);
    }

    public function testNoRelocationsConfiguredIsANoOp(): void
    {
        $input = ['default' => ['slow_job' => $this->job('Vendor\Module\Cron\SlowJob')]];

        self::assertSame($input, $this->plugin([])->afterGetJobs($this->subject(), $input));
    }
}
