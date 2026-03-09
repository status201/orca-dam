<?php

namespace App\Exceptions;

use App\Models\Asset;
use RuntimeException;

class DuplicateAssetException extends RuntimeException
{
    public Asset $existingAsset;

    public function __construct(Asset $existingAsset)
    {
        $this->existingAsset = $existingAsset;
        parent::__construct("Duplicate of existing asset (ID: {$existingAsset->id})");
    }
}
