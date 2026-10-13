<?php

namespace Wexample\SymfonyAccountingDemo\Traits;

use Wexample\SymfonyAccountingDemo\WexampleSymfonyAccountingDemoBundle;
use Wexample\SymfonyHelpers\Traits\BundleClassTrait;

trait SymfonyAccountingDemoBundleClassTrait
{
    use BundleClassTrait;

    public static function getBundleClassName(): string
    {
        return WexampleSymfonyAccountingDemoBundle::class;
    }
}
