<?php

declare(strict_types=1);

namespace Medas\PdoStorage\Queries\Selector\StoreQueryBuilder;

use Medas\Core\Attributes\Service;
use Medas\EntityManager\MetaDataManager;
use Medas\EntityManager\Selector\Conditions\WhereReferencedBy;
use Medas\PdoStorage\Exceptions\ReferencedByOwnEntity;

/**
 * Opens the `exists` subquery for a WhereReferencedBy: the referring store under
 * an alias of its own, so it cannot clash with the same store joined in the outer
 * query, matched on the referring property. CalculationsProcessor appends the
 * condition's own conditions and closes it.
 */
#[Service]
readonly class ReferencedByCompiler
{
    private const string ALIAS_SUFFIX = '__referring';

    public function __construct(
        private MetaDataManager $metaDataManager,
    )
    {
    }

    // The aliased store the subquery's conditions refer to.
    public function referringStore(Job $job, WhereReferencedBy $condition): string
    {
        if ($condition->referringEntity === $job->mainEntity) {
            throw new ReferencedByOwnEntity($condition->referringEntity);
        }

        $store = $this->metaDataManager->get($condition->referringEntity)->entity->store;

        return $job->driverHandler->quote($job->database, $store . self::ALIAS_SUFFIX);
    }

    public function open(Job $job, WhereReferencedBy $condition, string $referringStore): string
    {
        $store = $this->metaDataManager->get($condition->referringEntity)->entity->store;
        $quote = fn(string $name) => $job->driverHandler->quote($job->database, $name);

        return 'exists (select 1 from '
            . $quote($store)
            . ' as '
            . $referringStore
            . ' where '
            . $referringStore
            . '.'
            . $quote($condition->referringProperty)
            . ' = '
            . $job->storeForProperty(null, $condition->property)
            . '.'
            . $quote($condition->property);
    }
}
