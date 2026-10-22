<?php

declare(strict_types=1);

namespace Medas\PdoStorage\Exceptions;

use Medas\Core\Exceptions\BaseException;

// The subquery's properties are told apart from the selected entity's by their
// entity, so a WhereReferencedBy on the selected entity itself would be ambiguous.
class ReferencedByOwnEntity extends BaseException
{
    public function __construct(string $entity)
    {
        parent::__construct($entity);
    }

    public function pattern(): string
    {
        return 'WhereReferencedBy cannot refer from %s to itself';
    }
}
