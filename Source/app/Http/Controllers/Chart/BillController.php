<?php
/**
 * BillController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Chart;

use Carbon\Carbon;
use FireflyIII\Generator\Chart\Basic\GeneratorInterface;
use FireflyIII\Helpers\Collector\TransactionCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Transaction;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Currency\CurrencyRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Class BillController.
 */
class BillController extends Controller
{
    /** @var GeneratorInterface Chart generation methods. */
    protected $generator;

    /**
     * BillController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->generator = app(GeneratorInterface::class);
    }


    /**
     * Shows all bills and whether or not they've been paid this month (pie chart).
     *
     * @param BillRepositoryInterface $repository
     *
     * @return JsonResponse
     */
    public function frontpage(BillRepositoryInterface $repository): JsonResponse
    {
        $start = session('start', Carbon::now()->startOfMonth());
        $end   = session('end', Carbon::now()->endOfMonth());
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('chart.bill.frontpage');
        if ($cache->has()) {
            return response()->json($cache->get()); // @codeCoverageIgnore
        }
        /** @var CurrencyRepositoryInterface $currencyRepository */
        $currencyRepository = app(CurrencyRepositoryInterface::class);

        $chartData  = [];
        $currencies = [];
        $paid       = $repository->getBillsPaidInRangePerCurrency($start, $end); // will be a negative amount.
        $unpaid     = $repository->getBillsUnpaidInRangePerCurrency($start, $end); // will be a positive amount.

        foreach ($paid as $currencyId => $amount) {
            $currencies[$currencyId] = $currencies[$currencyId] ?? $currencyRepository->findNull($currencyId);
            $label                   = (string)trans('firefly.paid_in_currency', ['currency' => $currencies[$currencyId]->name]);
            $chartData[$label]       = ['amount' => $amount, 'currency_symbol' => $currencies[$currencyId]->symbol];
        }
        foreach ($unpaid as $currencyId => $amount) {
            $currencies[$currencyId] = $currencies[$currencyId] ?? $currencyRepository->findNull($currencyId);
            $label                   = (string)trans('firefly.unpaid_in_currency', ['currency' => $currencies[$currencyId]->name]);
            $chartData[$label]       = ['amount' => $amount, 'currency_symbol' => $currencies[$currencyId]->symbol];
        }

        $data = $this->generator->multiCurrencyPieChart($chartData);
        $cache->store($data);

        return response()->json($data);
    }


    /**
     * Shows overview for a single bill.
     *
     * @param TransactionCollectorInterface $collector
     * @param Bill                          $bill
     *
     * @return JsonResponse
     */
    public function single(TransactionCollectorInterface $collector, Bill $bill): JsonResponse
    {
        $cache = new CacheProperties;
        $cache->addProperty('chart.bill.single');
        $cache->addProperty($bill->id);
        if ($cache->has()) {
            return response()->json($cache->get()); // @codeCoverageIgnore
        }

        $results = $collector->setAllAssetAccounts()->setBills(new Collection([$bill]))->getTransactions();
        /** @var Collection $results */
        $results   = $results->sortBy(
            function (Transaction $transaction) {
                return $transaction->date->format('U');
            }
        );
        $chartData = [
            ['type' => 'bar', 'label' => (string)trans('firefly.min-amount'), 'currency_symbol' => $bill->transactionCurrency->symbol, 'entries' => []],
            ['type' => 'bar', 'label' => (string)trans('firefly.max-amount'), 'currency_symbol' => $bill->transactionCurrency->symbol, 'entries' => []],
            ['type' => 'line', 'label' => (string)trans('firefly.journal-amount'), 'currency_symbol' => $bill->transactionCurrency->symbol, 'entries' => []],
        ];

        /** @var Transaction $entry */
        foreach ($results as $entry) {
            $date                           = $entry->date->formatLocalized((string)trans('config.month_and_day'));
            $chartData[0]['entries'][$date] = $bill->amount_min; // minimum amount of bill
            $chartData[1]['entries'][$date] = $bill->amount_max; // maximum amount of bill

            // append amount because there are more than one per moment:
            if (!isset($chartData[2]['entries'][$date])) {
                $chartData[2]['entries'][$date] = '0';
            }
            $amount                         = bcmul($entry->transaction_amount, '-1');
            $chartData[2]['entries'][$date] = bcadd($chartData[2]['entries'][$date], $amount);  // amount of journal
        }

        $data = $this->generator->multiSet($chartData);
        $cache->store($data);

        return response()->json($data);
    }
}