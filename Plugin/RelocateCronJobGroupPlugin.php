<?php declare(strict_types=1);

namespace Graycore\CronGroupRelocation\Plugin;

use Magento\Cron\Model\Config;

/**
 * Moves cron jobs out of the group their declaring module put them in and into
 * another group.
 *
 * crontab.xml is additive: declaring an existing job code under a second group
 * registers it in *both* groups (the converter keys its output
 * [groupId][jobCode], and the DOM merge treats /config/group/job as identified
 * by group id + job name), so the job would be scheduled and run twice. There
 * is no XML construct that removes a job from a group, so relocation has to
 * happen on the merged config.
 *
 * Interception point is deliberate: Magento\Cron\Model\Config::getJobs() is the
 * only path Magento\Cron\Observer\ProcessCronQueueObserver reads jobs through,
 * and it sits downstream of both the XML reader and Config\Reader\Db — so any
 * per-job schedule override stored in core_config_data travels with the job to
 * its new group instead of being silently dropped.
 *
 * @see \Magento\Cron\Observer\ProcessCronQueueObserver::execute()
 */
class RelocateCronJobGroupPlugin
{
    /**
     * @param string[] $relocations Job code => destination cron group id.
     */
    public function __construct(
        private readonly array $relocations = []
    ) {
    }

    /**
     * Rewrite the merged job list so relocated jobs appear only under their destination group.
     *
     * @param Config $subject
     * @param array $result Group id => job code => job config.
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetJobs(Config $subject, array $result): array
    {
        foreach ($this->relocations as $jobCode => $destinationGroup) {
            $jobConfig = null;

            // array_keys() snapshots the group list so the loop is unaffected by
            // the destination group being created part way through.
            foreach (array_keys($result) as $groupId) {
                if ($groupId === $destinationGroup || !isset($result[$groupId][$jobCode])) {
                    continue;
                }

                $jobConfig = $result[$groupId][$jobCode];
                unset($result[$groupId][$jobCode]);
            }

            // Only materialize the destination group for jobs that actually
            // exist. A disabled declaring module must not leave a phantom group
            // behind for ProcessCronQueueObserver to lock and generate against.
            if ($jobConfig !== null) {
                $result[$destinationGroup][$jobCode] = $jobConfig;
            }
        }

        return $result;
    }
}
