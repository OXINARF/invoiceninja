<?php

namespace App\Models\Traits;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GatewayType;
use App\Models\InvoiceItem;
use App\Models\AccountGatewaySettings;
use App\Models\Vendor;
use Utils;

/**
 * Class ChargesFees
 */
trait ChargesFees
{
    public function calcGatewayFee($gatewayTypeId = false, $includeTax = false)
    {
        $account = $this->account;
        $settings = $account->getGatewaySettings($gatewayTypeId);
        $fee = 0;

        if (! $account->gateway_fee_enabled) {
            return 0;
        }

        if (! $settings) {
            return 0;
        }

        if ($settings->fee_amount) {
            $fee += $settings->fee_amount;
        }

        if ($settings->fee_percent) {
            $amount = $this->partial > 0 ? $this->partial : $this->balance;
            $fee += $amount * $settings->fee_percent / 100;
        }

        // calculate final amount with tax
        // TODO: this doesn't account for taxes when custom field is used (nor when invoice tax is used, but that is pre-existing)
        if ($includeTax) {
            $preTaxFee = $fee;

            if ($settings->fee_tax_rate1) {
                $fee += $preTaxFee * $settings->fee_tax_rate1 / 100;
            }

            if ($settings->fee_tax_rate2) {
                $fee += $preTaxFee * $settings->fee_tax_rate2 / 100;
            }
        }

        return round($fee, 2);
    }

    public function getGatewayFeeItem()
    {
        if (! $this->relationLoaded('invoice_items')) {
            $this->load('invoice_items');
        }

        foreach ($this->invoice_items as $item) {
            if ($item->invoice_item_type_id == INVOICE_ITEM_TYPE_PENDING_GATEWAY_FEE) {
                return $item;
            }
        }

        return false;
    }

    public function addFeeExpense($fee, $gatewayTypeId)
    {
        $gateway = $this->account->getGatewayByType($gatewayTypeId);

        if (! $gateway) { // shouldn't happen
            return;
        }

        $vendor = Vendor::where('account_id', $this->account->id)->where('name', 'like', '%' . $gateway->name . '%')->first();

        if (! $vendor) { // only add expense if vendor has been created
            return;
        }

        $category = ExpenseCategory::where('account_id', $this->account->id)->where('name', 'like', '%Gateway%')->first();

        $expense = Expense::createNew($this);

        if ($category) {
            $expense->expense_category()->associate($category);
        }

        $this->load('client.currency');

        $expense->client()->associate($this->client);
        $expense->invoice()->associate($this);
        $expense->vendor()->associate($vendor);

        $expense->amount = $fee;
        $expense->expense_date = Utils::toSqlDate(Utils::today());
        $expense->invoice_currency_id = $this->client->currency_id ?: ($this->account->currency_id ?: 1);
        $expense->expense_currency_id = $this->invoice_currency_id;
        $expense->should_be_invoiced = 1;

        // TODO: add tax rates

        $expense->save();
    }
}
