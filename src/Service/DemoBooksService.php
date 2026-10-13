<?php

namespace Wexample\SymfonyAccountingDemo\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\AccountRole;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Repository\BankTransactionRepository;
use Wexample\SymfonyAccounting\Repository\LedgerRepository;
use Wexample\SymfonyAccounting\Service\Bank\AllocationService;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\ChartService;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyGeo\Repository\CountryRepository;

/**
 * The demo's books, written once on the first visit: a French workshop and a
 * Belgian one, each with its customers and supplier, invoices issued — one
 * paid, one late —, a quotation and a draft, a purchase, and a bank statement
 * whose lines are left for the reader to match. Amounts in euro cents,
 * quantities scaled by 100, VAT in basis points. Every name is made up.
 */
class DemoBooksService
{
    final public const string FRENCH = 'demo-fr';

    final public const string BELGIAN = 'demo-be';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LedgerRepository $ledgers,
        private readonly CountryRepository $countries,
        private readonly LedgerService $ledgerService,
        private readonly PartyService $partyService,
        private readonly InvoiceFactory $factory,
        private readonly InvoiceEmissionService $emission,
        private readonly ChartService $chart,
        private readonly BankImportService $bankImport,
        private readonly AllocationService $allocations,
        private readonly BankTransactionRepository $transactions,
    ) {
    }

    /**
     * @return array{fr: Ledger, be: Ledger}
     */
    public function ensure(): array
    {
        // Each ledger written whole or not at all: a demo half made would
        // never be completed.
        return [
            'fr' => $this->find(self::FRENCH) ?? $this->entityManager->wrapInTransaction(fn () => $this->french()),
            'be' => $this->find(self::BELGIAN) ?? $this->entityManager->wrapInTransaction(fn () => $this->belgian()),
        ];
    }

    private function find(string $reference): ?Ledger
    {
        return $this->ledgers->findOneBy(['externalReference' => $reference]);
    }

    private function french(): Ledger
    {
        $ledger = $this->ledger('Atelier Démo SARL', 'FR', self::FRENCH)
            ->setLegalForm('SARL')
            ->setLegalIdentifier('732829320')
            ->setVatNumber('FR44732829320')
            ->setEmail('compta@atelier-demo.example')
            ->setPostalAddress('12 rue des Exemples')
            ->setPostCode('69001')
            ->setCity('Lyon')
            ->setInvoicePrefix('ATD');

        $bakery = $this->party($ledger, 'Boulangerie Martin', 'FR', customer: true, city: 'Lyon');
        $studio = $this->party($ledger, 'Studio Lumen', 'FR', customer: true, city: 'Villeurbanne');
        $paper = $this->party($ledger, 'Papeterie & Co', 'FR', supplier: true, city: 'Grenoble');

        $paid = $this->sale($ledger, $bakery, '-80 days', 'Website redesign', [['Design workshop', 60000, 200], ['Development', 45000, 300]]);
        $late = $this->sale($ledger, $studio, '-55 days', 'Brand guidelines', [['Logo and colours', 90000, 100]]);
        $this->sale($ledger, $bakery, '-10 days', 'Monthly maintenance', [['Maintenance, October', 25000, 100]]);
        $this->draft($ledger, $studio, InvoiceType::Quotation, 'Photo shoot', [['Half-day shoot', 70000, 100], ['Retouching, per photo', 1500, 2000]]);
        $this->draft($ledger, $bakery, InvoiceType::Bill, 'Printed menus', [['Menu layout', 18000, 100]]);
        $purchase = $this->purchase($ledger, $paper, 'PAP-2026-0412', '-40 days', 'Paper and toner', [['A4 paper, boxes', 3200, 500], ['Toner', 8900, 100]]);

        $bank = $this->bankAccount($ledger, 'Banque Démo', 'FR7630006000011234567890189');
        $statement = $this->statement([
            ['-75 days', $paid, 'VIR BOULANGERIE MARTIN '.$paid->getNumber(), 'Boulangerie Martin'],
            ['-30 days', $purchase, 'PRLV PAPETERIE ET CO', 'Papeterie & Co', -1],
            ['-20 days', -1450, 'FRAIS TENUE DE COMPTE', null],
            ['-5 days', 30000, 'VIR STUDIO LUMEN ACOMPTE', 'Studio Lumen'],
        ]);
        $this->bankImport->import($bank, $statement);

        // The first payment is matched already, to show a paid invoice; the
        // rest is left for the reader.
        $this->entityManager->flush();
        foreach ($this->transactions->findBy(['bankAccount' => $bank]) as $line) {
            if ($line->getAmount() === $paid->calcRemainingAmount()) {
                $this->allocations->allocate($line, $paid);
            }
        }

        $this->entityManager->flush();
        unset($late);

        return $ledger;
    }

    private function belgian(): Ledger
    {
        $ledger = $this->ledger('Demo Atelier SRL', 'BE', self::BELGIAN)
            ->setLegalForm('SRL')
            ->setLegalIdentifier('0403.170.701')
            ->setVatNumber('BE0403170701')
            ->setEmail('accounting@demo-atelier.example')
            ->setPostalAddress('Rue de l\'Exemple 7')
            ->setPostCode('1000')
            ->setCity('Bruxelles')
            ->setInvoicePrefix('DAT')
            // What its annual accounts filed with the National Bank say.
            ->setSettings(['be_nbb' => ['court' => 'm31', 'last_deed_date' => '2020-01-15']]);

        $cafe = $this->party($ledger, 'Café Lumière', 'BE', customer: true, city: 'Liège');
        $print = $this->party($ledger, 'Drukkerij Voorbeeld', 'BE', supplier: true, city: 'Gent');

        $bill = $this->sale($ledger, $cafe, '-45 days', 'Terrace signage', [['Signage design', 55000, 100], ['Installation', 12000, 200]]);
        $this->purchase($ledger, $print, 'DV-26-118', '-25 days', 'Printing', [['Posters, A2', 450, 4000]]);
        $this->draft($ledger, $cafe, InvoiceType::Quotation, 'Menu boards', [['Menu boards, set', 38000, 100]]);

        $bank = $this->bankAccount($ledger, 'Demo Bank', 'BE68539007547034');
        $this->bankImport->import($bank, $this->statement([
            ['-15 days', $bill, 'VIREMENT CAFE LUMIERE '.$bill->getPaymentReference(), 'Café Lumière'],
        ]));
        $this->entityManager->flush();

        return $ledger;
    }

    private function ledger(
        string $name,
        string $country,
        string $reference
    ): Ledger {
        $ledger = $this->ledgerService->create(
            $name,
            $this->countries->findByIsoAlpha2Code($country) ?? throw new \RuntimeException(sprintf('The country %s is missing: load symfony-geo\'s countries.', $country)),
            fiscalYearStart: new DateTimeImmutable(date('Y').'-01-01'),
        );

        return $ledger->setExternalReference($reference);
    }

    private function party(
        Ledger $ledger,
        string $name,
        string $country,
        bool $customer = false,
        bool $supplier = false,
        ?string $city = null,
    ): Party {
        $party = $this->partyService->create($ledger, $name, $customer, $supplier, $this->countries->findByIsoAlpha2Code($country));
        $party->setCity($city)->setEmail(strtolower(preg_replace('/[^a-z]+/i', '.', $name)).'@example.com');

        return $party;
    }

    /**
     * @param list<array{string, int, int}> $lines title, unit price, quantity scaled by 100
     */
    private function sale(
        Ledger $ledger,
        Party $party,
        string $when,
        string $title,
        array $lines,
    ): Invoice {
        $invoice = $this->draft($ledger, $party, InvoiceType::Bill, $title, $lines, $when);
        $this->entityManager->flush();

        return $this->emission->emit($invoice);
    }

    /**
     * A supplier's invoice keeps the number the supplier gave it.
     *
     * @param list<array{string, int, int}> $lines
     */
    private function purchase(
        Ledger $ledger,
        Party $party,
        string $number,
        string $when,
        string $title,
        array $lines,
    ): Invoice {
        $invoice = $this->factory->create($ledger, InvoiceType::Bill, InvoiceDirection::Purchase, $party, new DateTimeImmutable($when), $title)
            ->setNumber($number);

        foreach ($lines as [$line, $price, $quantity]) {
            $this->factory->addItem($invoice, $line, $price, $quantity);
        }

        $this->entityManager->flush();

        return $this->emission->emit($invoice);
    }

    /**
     * @param list<array{string, int, int}> $lines
     */
    private function draft(
        Ledger $ledger,
        Party $party,
        InvoiceType $type,
        string $title,
        array $lines,
        string $when = 'today',
    ): Invoice {
        $invoice = $this->factory->create($ledger, $type, InvoiceDirection::Sale, $party, new DateTimeImmutable($when), $title);

        foreach ($lines as [$line, $price, $quantity]) {
            $this->factory->addItem($invoice, $line, $price, $quantity);
        }

        return $invoice;
    }

    private function bankAccount(
        Ledger $ledger,
        string $label,
        string $iban
    ): BankAccount {
        $account = (new BankAccount())
            ->setLedger($ledger)
            ->setLabel($label)
            ->setIban($iban)
            ->setAccountNumber($this->chart->getRoleNumber($ledger, AccountRole::Bank))
            ->setCurrencyCode($ledger->getCurrencyCode());
        $this->entityManager->persist($account);
        $ledger->setDefaultBankAccount($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Lines as a bank would send them; a document instead of an amount stands
     * for what it asks — paid in, or paid out with a sign of -1.
     *
     * @param list<array{0: string, 1: int|Invoice, 2: string, 3: ?string, 4?: int}> $lines
     */
    private function statement(array $lines): ParsedStatement
    {
        $statement = new ParsedStatement(currencyCode: 'EUR');

        foreach ($lines as $index => $line) {
            [$when, $amount, $label, $counterparty] = $line;

            if ($amount instanceof Invoice) {
                $amount = $amount->calcRemainingAmount() * ($line[4] ?? 1);
            }

            $statement->addTransaction(new ParsedTransaction(
                new DateTimeImmutable($when),
                $amount,
                $label,
                externalId: 'demo-'.$index.'-'.md5($label),
                counterpartyName: $counterparty,
            ));
        }

        return $statement;
    }
}
