<?php

declare(strict_types=1);

namespace Medas\PdoStorage\Queries\Selector\StoreQueryBuilder;

use Medas\Core\Attributes\Service;
use Medas\EntityManager\Selector\{
    Calculations\Calculation,
    Calculations\Literal,
    Calculations\RowCount,
    Conditions\OrIs,
    Conditions\WhereCollectionContains,
    Conditions\WhereContains,
    Conditions\WhereEndsWith,
    Conditions\WhereIn,
    Conditions\WhereIs,
    Conditions\WhereIsAtLeast,
    Conditions\WhereIsAtMost,
    Conditions\WhereIsLessThan,
    Conditions\WhereIsLessThanOrEqual,
    Conditions\WhereIsMoreThan,
    Conditions\WhereIsMoreThanOrEqual,
    Conditions\WhereIsNot,
    Conditions\WhereIsNotNull,
    Conditions\WhereIsNull,
    Conditions\WhereIsTruthy,
    Conditions\WhereNotIn,
    Conditions\WhereReferencedBy,
    Conditions\WhereStartsWith,
    Exceptions\UnhandledCalculationType,
    Exceptions\UnhandledOperantType,
    Operants\Argument,
    Operants\ArgumentArray,
    Operants\Operant,
    Operants\Property,
    Operants\Value,
    Operants\Values,
    OutputValues\OutputValue
};
use Medas\PdoStorage\Exceptions\CollectionContainsOperantIsNotAProperty;
use Medas\StorageManager\Shared\ValueSerializer;

#[Service]
readonly class CalculationsProcessor
{
    public function __construct(
        private CollectionContainsCompiler $collectionContainsCompiler,
        private ReferencedByCompiler       $referencedByCompiler,
        private ValueSerializer            $valueSerializer,
    )
    {
    }

    /** @param Calculation[]|Calculation $calculations */
    public function process(Job $job, array|Calculation $calculations): void
    {
        if (!is_array($calculations)) {
            $calculations = [$calculations];
        }

        $job->currentCalculation = $calculations ? '' : null;
        $isFirstCalculation = true;

        foreach ($calculations as $calculation) {
            if (!$isFirstCalculation) {
                $job->currentCalculation .= ' and ';
            }

            $this->processCalculation($job, $calculation);

            $isFirstCalculation = false;
        }
    }

    private function processCalculation(Job $job, Calculation $calculation): void
    {
        if ($calculation instanceof OutputValue) {
            throw new UnhandledCalculationType($calculation);
        }
        else {
            match ($calculation::class) {
                WhereIsTruthy::class => $this->processTruthy($job, $calculation),
                WhereIs::class => $this->processComparison($job, $calculation, '='),
                WhereIsNot::class => $this->processComparison($job, $calculation, '!='),
                WhereIn::class => $this->processComparison($job, $calculation, ' in '),

                WhereCollectionContains::class
                    => $this->processCollectionContains($job, $calculation),

                WhereNotIn::class => $this->processComparison($job, $calculation, ' not in '),
                WhereReferencedBy::class => $this->processReferencedBy($job, $calculation),
                WhereIsMoreThan::class => $this->processComparison($job, $calculation, '>'),
                WhereIsLessThan::class => $this->processComparison($job, $calculation, '<'),

                WhereIsMoreThanOrEqual::class, WhereIsAtLeast::class
                    => $this->processComparison($job, $calculation, '>='),

                WhereIsLessThanOrEqual::class, WhereIsAtMost::class
                    => $this->processComparison($job, $calculation, '<='),

                WhereIsNull::class => $this->processNullComparison($job, $calculation, true),
                WhereIsNotNull::class => $this->processNullComparison($job, $calculation, false),
                WhereContains::class => $this->processLikeComparison($job, $calculation, '%', '%'),
                WhereStartsWith::class => $this->processLikeComparison($job, $calculation, '', '%'),
                WhereEndsWith::class => $this->processLikeComparison($job, $calculation, '%', ''),
                RowCount::class => $this->processRowCount($job),
                Literal::class => $this->processLiteral($job, $calculation),
                OrIs::class => $this->processOrIs($job, $calculation),
                Property::class => $this->processProperty($job, $calculation),
                default => throw new UnhandledCalculationType($calculation),
            };
        }
    }

    private function processTruthy(Job $job, WhereIsTruthy $calculation): void
    {
        $job->currentCalculation .= $this->operantToQuery($job, $calculation->property);
    }

    private function processCollectionContains(Job $job, WhereCollectionContains $calculation): void
    {
        if (!$calculation->property instanceof Property) {
            throw new CollectionContainsOperantIsNotAProperty($calculation->property);
        }

        $valueQuery = $this->operantToQuery($job, $calculation->value);

        $job->currentCalculation .= $this->collectionContainsCompiler->compile(
            $job,
            $calculation->property,
            $valueQuery,
        );
    }

    private function processReferencedBy(Job $job, WhereReferencedBy $calculation): void
    {
        $referringStore = $this->referencedByCompiler->referringStore($job, $calculation);

        $job->currentCalculation .= $this->referencedByCompiler->open(
            $job,
            $calculation,
            $referringStore
        );

        // While the subquery's conditions are written, the referring entity's
        // properties resolve to its aliased store; an outer join of the same
        // entity gets its own store back afterwards.
        $hadOuterStore = array_key_exists($calculation->referringEntity, $job->stores);
        $outerStore = $job->stores[$calculation->referringEntity] ?? null;
        $job->stores[$calculation->referringEntity] = $referringStore;

        try {
            foreach ($calculation->conditions as $condition) {
                $job->currentCalculation .= ' and ';

                $this->processCalculation($job, $condition);
            }
        }

        finally{
            if ($hadOuterStore) {
                $job->stores[$calculation->referringEntity] = $outerStore;
            }
            else {
                unset($job->stores[$calculation->referringEntity]);
            }
        }

        $job->currentCalculation .= ')';
    }

    private function processNullComparison(Job $job, WhereIsNull $calculation, bool $isNull): void
    {
        $job->currentCalculation .= $this->operantToQuery($job, $calculation->property)
            . ($isNull ? ' is null' : ' is not null');
    }

    private function processLikeComparison(Job $job, WhereIs $calculation, string $prefix, string $suffix): void
    {
        if ($calculation->value instanceof Value) {
            $likeValue = new Value($prefix . $calculation->value->value . $suffix);
            $calculation = new WhereIs($calculation->property, $likeValue);
        }
        elseif ($calculation->value instanceof Argument && ($prefix !== '' || $suffix !== '')) {
            $job->argumentLikeWrappers[$calculation->value->name] = [
                'prefix' => $prefix,
                'suffix' => $suffix,
            ];
        }

        $this->processComparison($job, $calculation, ' like ');
    }

    private function processComparison(Job $job, WhereIs $calculation, string $operator): void
    {
        $nameQuery = $this->operantToQuery($job, $calculation->property);
        $addOrIsNull = false;
        $valueQuery = $this->operantToQuery($job, $calculation->value, $addOrIsNull);
        $baseQuery = $nameQuery . $operator . $valueQuery;

        if ($addOrIsNull) {
            $job->currentCalculation .= '(' . $baseQuery . ' or ' . $nameQuery . ' is null)';
        }
        else {
            $job->currentCalculation .= $baseQuery;
        }
    }

    private function processRowCount(Job $job): void
    {
        $job->currentCalculation .= '*';
    }

    private function processLiteral(Job $job, Literal $calculation): void
    {
        $job->currentCalculation .= $this->addValue($calculation->literal, $job);
    }

    private function processOrIs(Job $job, OrIs $calculation): void
    {
        $job->currentCalculation .= '(';
        $isFirstCalculation = true;

        foreach ($calculation->conditions as $condition) {
            if (!$isFirstCalculation) {
                $job->currentCalculation .= ' or ';
            }

            $this->processCalculation($job, $condition);

            $isFirstCalculation = false;
        }

        $job->currentCalculation .= ')';
    }

    /**
     * A bare property, e.g. the argument of an aggregate such as Max
     */
    private function processProperty(Job $job, Property $calculation): void
    {
        $job->currentCalculation .= $this->operantToQuery($job, $calculation);
    }

    private function operantToQuery(Job $job, Operant $operant, bool|null &$addOrIsNull = null): string
    {
        if ($operant instanceof Property) {
            return $job->storeForProperty($operant->entity, $operant->name)
                . '.'
                . $job->driverHandler->quote($job->database, $operant->name);
        }

        if ($operant instanceof Argument) {
            $job->foundArguments[$operant->name] = true;
            $occurrence = $job->argumentOccurrences[$operant->name] ?? 0;
            $job->argumentOccurrences[$operant->name] = $occurrence + 1;

            // The first occurrence keeps the plain name; repeats get a suffix so the same
            // logical argument can be bound to multiple distinct placeholders under native
            // (non-emulated) prepares, which don't allow a placeholder name to repeat.
            $placeholderName = $occurrence === 0
                ? $operant->name
                : $operant->name . '__' . $occurrence;

            return ':' . $placeholderName;
        }

        if ($operant instanceof ArgumentArray) {
            $job->foundArguments[$operant->name] = true;
            $job->variableSizedParameters[$operant->name] = true;

            return '(:' . $operant->name . ')';
        }

        if ($operant instanceof Value) {
            return $this->addValue($operant->value, $job);
        }

        if ($operant instanceof Values) {
            $names = [];

            foreach ($operant->value as $value) {
                if ($value === null) {
                    $addOrIsNull = true;
                }
                else {
                    $names[] = $this->addValue($value, $job);
                }
            }

            return '(' . implode(',', $names) . ')';
        }

        throw new UnhandledOperantType($operant);
    }

    private function addValue(mixed &$value, Job $job): string
    {
        $value = $this->valueSerializer->serialize($value);
        $name = 'c' . count($job->foundConstants);
        $job->foundConstants[$name] = $value;

        return ':' . $name;
    }
}
