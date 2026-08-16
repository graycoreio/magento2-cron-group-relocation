# Magento 2 Cron Group Relocation

## Magento Version Support
![Magento v2.3 Supported](https://img.shields.io/badge/Magento-2.3-brightgreen.svg?labelColor=2f2b2f&logo=magento&logoColor=f26724&color=464246&longCache=true&style=flat)
![Magento v2.4 Supported](https://img.shields.io/badge/Magento-2.4-brightgreen.svg?labelColor=2f2b2f&logo=magento&logoColor=f26724&color=464246&longCache=true&style=flat)

Ever have one slow cron job in the `default` group hold up every other job in that group, and try to move it somewhere else?

```xml
<!-- your module's crontab.xml -->
<group id="reports">
    <job name="aggregate_sales_report_bestsellers_data" instance="..." method="execute">
        <schedule>0 0 * * *</schedule>
    </job>
</group>
```

Then find it running **twice** a night — once in `reports`, and still once in `default`.

This package moves a cron job into a different group than the one its declaring module put it in, without running it twice.

## Purpose
`crontab.xml` is additive. `Magento\Cron\Model\Config\Converter\Xml` keys its output `[groupId][jobCode]` and the DOM merge identifies `/config/group/job` by group id plus job name, so redeclaring an existing job code under a second group leaves it registered in both. There is no XML construct that removes a job from a group.

That makes a vendor module's group choice effectively final — even though group choice is what controls which jobs share a lock, a process, and a schedule look-ahead window. A single slow job in `default` can consume the window in which another job's schedule row had to be generated, and Magento only ever schedules forward, so that job is neither run nor marked `missed`. It silently does not exist for that cycle.

This package lets you put such a job in its own group, so its runtime is somebody else's problem.

## Getting Started
This module is intended to be installed with [composer](https://getcomposer.org/). From the root of your Magento 2 project:

1. Download the package
```bash
composer require graycore/magento2-cron-group-relocation
```
2. Enable the package
```bash
./bin/magento module:enable Graycore_CronGroupRelocation
```
3. Declare a destination group in your own module's `etc/cron_groups.xml`
```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Cron:etc/cron_groups.xsd">
    <group id="reports">
        <schedule_ahead_for>60</schedule_ahead_for>
        <schedule_lifetime>180</schedule_lifetime>
        <use_separate_process>0</use_separate_process>
    </group>
</config>
```
4. Map jobs into it from your own module's `etc/di.xml`
```xml
<type name="Graycore\CronGroupRelocation\Plugin\RelocateCronJobGroupPlugin">
    <arguments>
        <argument name="relocations" xsi:type="array">
            <item name="aggregate_sales_report_bestsellers_data" xsi:type="string">reports</item>
            <item name="aggregate_sales_report_order_data" xsi:type="string">reports</item>
        </argument>
    </arguments>
</type>
```
5. Sequence your module after this one in `etc/module.xml`, so your arguments merge on top
```xml
<sequence>
    <module name="Graycore_CronGroupRelocation"/>
</sequence>
```
6. Flush the config cache
```bash
./bin/magento cache:flush config
```

## Features
* Moves any cron job to any group, keyed by job code — no need to know which module declared it
* Never runs a job twice, unlike redeclaring it in `crontab.xml`
* Preserves the job's schedule, instance, method and `config_path` exactly as merged
* Preserves `core_config_data` schedule overrides — it intercepts *downstream* of `Magento\Cron\Model\Config\Reader\Db`, so the schedule actually in effect travels with the job
* Creates no group for a job that doesn't exist, so a disabled declaring module can't leave an empty group behind to be locked and schedule-generated against
* Idempotent, and a no-op when nothing is configured
* No database tables, no admin config, no cron jobs of its own

## Upgrading
* [Semver Policy](https://semver.org/)
