<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use RuntimeException;

final class ProductNotFound extends RuntimeException
{
    public function __construct(string $sku)
    {
        parent::__construct("Товар [$sku] не найден или снят с продажи");
    }
}
