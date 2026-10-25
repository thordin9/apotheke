<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Closure;

/**
 * The item form's AI autofill attributes (legacy view_form()): null when
 * autofill is off or the role may not create items. Built in Config\Services
 * from the Autofill module, which Catalog does not depend on.
 */
final class FormAutofill
{
    /** @param Closure(): (array{timeout:int,side:int,auto:bool,latency:?int}|null) $attrs */
    public function __construct(private readonly Closure $attrs)
    {
    }

    /** @return array{timeout:int,side:int,auto:bool,latency:?int}|null */
    public function attrs(): ?array
    {
        return ($this->attrs)();
    }
}
