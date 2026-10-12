<?php

namespace Wexample\SymfonyAccountingDemo\Controller\Pages;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Wexample\SymfonyAccountingDemo\Service\DemoBooksService;
use Wexample\SymfonyAccountingDemo\Traits\SymfonyAccountingDemoBundleClassTrait;
use Wexample\SymfonyHelpers\Helper\VariableHelper;
use Wexample\SymfonyLoader\Controller\AbstractPagesController;

/**
 * The books of symfony-accounting-ds tried on two made-up ledgers, a French
 * one and a Belgian one: their charts, VAT and statements differ, the screens
 * do not.
 */
#[Route(path: '/accounting-demo/', name: 'accounting_demo_')]
final class AccountingDemoController extends AbstractPagesController
{
    use SymfonyAccountingDemoBundleClassTrait;

    final public const string ROUTE_INDEX = VariableHelper::INDEX;

    #[Route(path: '', name: self::ROUTE_INDEX)]
    public function index(DemoBooksService $books): Response
    {
        return $this->renderPage(self::ROUTE_INDEX, [
            'ledgers' => $books->ensure(),
        ]);
    }
}
