<?php

namespace Wexample\SymfonyAccountingDemo\Traits;

use Wexample\SymfonyHelpers\Traits\BundleClassTrait;
use Wexample\SymfonyAccountingDemo\WexampleSymfonyAccountingDemoBundle;

trait SymfonyAccountingDemoBundleClassTrait
{
    use BundleClassTrait;

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyAccountingDemoBundle::class;
    }
}
