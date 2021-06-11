<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher\Package;

use Spatie\DataTransferObject\DataTransferObject;

abstract class AbstractForm extends DataTransferObject implements FormInterface
{
    public int $package_id;

    public string $changelog;

    public string $chagelog_en;

    public function multipart(): array
    {
        // TODO: Implement multipart() method.
    }

}
