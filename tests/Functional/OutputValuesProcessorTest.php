<?php

declare(strict_types=1);

namespace Medas\PdoStorageTest\Functional;

use Medas\EntityManager\Selector\{
    Operants\Property,
    OutputValues\Max,
    OutputValues\Min,
    OutputValues\PropertyValue
};
use Medas\PdoStorage\Queries\Selector\StoreQueryBuilder\{Job, OutputValuesProcessor};
use Medas\PdoStorageTest\MockUps\{MockUpDatabase, MockupDriverHandler};
use PHPUnit\Framework\TestCase;

class OutputValuesProcessorTest extends TestCase
{
    public function testPropertiesAreQualifiedWithTheirStore(): void
    {
        $job = $this->createJob();

        service(OutputValuesProcessor::class)->process($job, [
            PropertyValue::c('name'),
            PropertyValue::c('role', 'Joined')->as('joinedRole'),
        ]);

        self::assertSame(['entities.`name`', 'joined.`role` as joinedRole'], $job->outputValues);
    }

    public function testMinimumAndMaximum(): void
    {
        $job = $this->createJob();

        service(OutputValuesProcessor::class)->process($job, [
            Min::c(Property::c('depth'))->as('depth'),
            Max::c(Property::c('role', 'Joined'))->as('role'),
        ]);

        self::assertSame(
            ['min(entities.`depth`) as depth', 'max(joined.`role`) as role'],
            $job->outputValues
        );
    }

    private function createJob(): Job
    {
        $job = new Job(new MockUpDatabase(), new MockupDriverHandler(), 'Entity');

        $job->stores['Entity'] = 'entities';
        $job->stores['Joined'] = 'joined';

        return $job;
    }
}
