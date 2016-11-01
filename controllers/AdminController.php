<?php
/**
 * Subscriptioner controller
 *
 * @copyright 2016 Linguistica 360
 */

/**
 * Override some functions in transactions to simplify workflow
 * Standard transaction does not allow the paysystem to be different with the
 * existing ones
 */
class Am_Paysystem_Transaction_Subscriptioner
        extends Am_Paysystem_Transaction_Manual {
    public function setPaysysId($paysys)
    {
        $this->paysys = $paysys;
    }

    /** @override */
    public function getPaysysId()
    {
        return $this->paysys;
    }

    /** @override */
    public function getRecurringType()
    {
        return Am_Paysystem_Abstract::REPORTS_REBILL;
    }

    private $paysys; /**< payment system ID */
}


class Subscriptioner_AdminController extends Am_Controller {
    /** @override */
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper(); // only super-admins allowed to open this
    }

    /**
     * Render the content of a tab in an admin panel
     */
	function subscriptionsTabAction()
    {
        // extract user id from URL
		$user_id = $this->getInt('user_id');

        $accessRecords = $this->getDi()->accessTable->findBy([
            'user_id' => $user_id
        ]);

        // add additional information
        foreach ($accessRecords as $record) {
            // add product title
            $product = $this->getDi()->productTable->load($record->product_id);
            $record->product_title = $product->title;

            // if invoice exists populate payment system and amount
            $invoice = $record->getInvoice();
            if ($invoice) {
                $record->paysys_id = $invoice->paysys_id;

                $payments = $invoice->getPaymentRecords();
                if (count($payments)) {
                    $record->amount = $payments[0]->amount;
                }
            }
        }

        // populate view with necessary information
        $this->view->user_id = $user_id;
        $this->view->accessRecords = $accessRecords;

        // render view
		$this->view->title = "User Subscriptions";
		$this->view->display('admin/subscriptioner/subscriptions.phtml');
    }

    /**
     * Remove given access and invoice and return back to the list
     */
    function removeAction()
    {
        // extract access and user ids from URL
        $aid = $this->getInt('id');
        $uid = $this->getInt('user_id');

        // find access
        $access = $this->getDi()->accessTable->load($aid);
        if ($access) {
            // search of invoice
            $invoice = $access->getInvoice();

            // remove invoice (which will delete access as well) or just access
            if ($invoice) $invoice->delete();
            else $access->delete();
        }

        // redirect user back to user subscription list
        $url = REL_ROOT_URL . '/subscriptioner/admin/subscriptions-tab/user_id';
        $this->redirectLocation("$url/$uid");
    }

    /**
     * Handles subscription form for jquery dialogue
     * Called for both displaying form initially and submitting the form
     *
     * This function is so long because amember makes it really hard to separate
     * the form initialisation, from form submission and it will probably
     * require twice as much code, at least I documented it.
     */
	function subscriptionAction()
    {
        // extract invoice id
		$id = $this->getInt('id');
        $user_id = $this->getInt('user_id');

        // current invoice records
        $item = $payment = $plan = $planId = $access = null;

        // if invoice already exists, get its records from the database
        if ($id) {
            $invoice = $this->getDi()->invoiceTable->load($id);

            if ($invoice) {
                // payment records, may not exist yet
                $payments = $invoice->getPaymentRecords();
                if (count($payments)) $payment = $payments[0];

                // access records, may not exist yet (created on payment)
                $accesses = $invoice->getAccessRecords();
                if (count($accesses)) $access = $accesses[0];

                // item (product) and its billing plan, should always exist
                $items = $invoice->getItems();
                if (count($items)) {
                    $item = $items[0];
                    $planId = $item->billing_plan_id;
                    $plan = $this->getDi()->billingPlanTable->load($planId);
                }
            }
        }

		// form
        $form = new Am_Form_Admin('subscription-info-form');

        $form->setDataSources([$this->_request]);
        $form->method = 'POST';

        // that determines if the form is submitted or reqested
        $submitted = $form->isSubmitted();

		// product selection
        $products = $form->addSelect('product_id')->setLabel('Subscription')
            ->setId('products');
		$products->addRule('required');

        // populate product selection from all billing plans in the database
        $plans = $this->getDi()->billingPlanTable->selectAllSorted();
        foreach ($plans as $value) {
            $term = $value->first_period;
            $product = $value->getProduct();
            $name = "$product->title ({$value->getTerms()})";
            $amount = $value->first_price;
            $attributes = "data-term=\"$term\" data-price=\"$amount\"";
            $products->addOption($name, $value->plan_id, $attributes);
        }

		// date subscription (access) starts
		$dateBegin = $form->addDate('begin_date')->setLabel('Start Date')
            ->setId('begin_date');
		$dateBegin->addRule('required');

        // date subscription (access) ends
        $dateEnd = $form->addDate('expire_date')->setLabel('End Date')
            ->setId('expire_date');
		$dateEnd->addRule('required');

		// payment system
        $paysys = $form->addSelect('paysys_id')->setLabel('Payment System')
            ->setId('paysys');
        $paysys->addRule('required');

        // first in the list is "manual" transaction
		$paysys->addOption('Manual', 'manual');

        // later in the list we get all available paysystems
        $paysystems = $this->getDi()->paysystemList->getOptions();
		foreach ($paysystems as $key => $value) {
            $paysys->addOption($value, $key);
        }

		// receipt
		$receipt = $form->addText('receipt')->setLabel('Receipt, #')
            ->setId('invoice-receipt');
        $receipt->addRule('required');

		// amount
		$amount = $form->addText('amount')->setLabel('Amount, $')
            ->setId('payment-amount');
        $amount->addRule('required');

		// save
        $form->addSubmit('_save', ['value' => 'Save']);

        if ($form->isSubmitted() && $form->validate()) {
            // for is being submitted, so save the changes to the database,
            // we do not validate for now, the user should know what is he
            // doing

            if (!$invoice) {
                // it is a new subscription, so we need to create an invoice
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->user_id = $user_id;

                // hack: initialise inner user: setUser does not set user_id,
                // and vise versa
                $invoice->getUser();

                $invoice->tm_added = sqlTime(date("Y-m-d"));
            }

            // get selected plan and see if that matches current plan, if it
            // exists
            $newPlan = $products->getValue();
            if (!$plan || $newPlan != $planId) {
                // if not lets remove replace it with the new one
                $planId = $newPlan;

                // first we find it in the database and get its product
                $plan = $this->getDi()->billingPlanTable->load($planId);
                $product = $plan->getProduct();

                // then we remove it from invoice if it exists
                if ($item) $invoice->deleteItem($item);
                // and replace it with the new one (or just adding a new one)
                $invoice->add($product);

                // initialise current item with newly added product
                $item = $invoice->getItems()[0];
            }

            // calculate all numbers we don't care about
            $invoice->calculate();

            // set the ones we do care about
            $invoice->first_total = $amount->getValue();
            $invoice->first_subtotal = $amount->getValue();

            // set payment system (thankfully it doesn't get validated)
            $oldpaysys = $invoice->paysys_id;
            $invoice->paysys_id = $paysys->getValue();

            // not sure why we do this, but why not
            $invoice->data()->set('added-by-admin',
                                  $this->getDi()->authAdmin->getUserId());

            // save or create the invoice
            $invoice->save();

            if ($paysys->getValue() === 'free') {
                if ($oldpaysys !== 'free') {
                    $free = $this->getDi()->plugins_payment->get('free');
                    $trans = new Am_Paysystem_Transaction_Free($free);

                    // add transaction to invoice
                    $invoice->addAccessPeriod($trans);
                }

                if ($payment) {
                    $payment->delete();
                    $payment = null;
                }
            }
            else if (!$payment) {
                $trans = new Am_Paysystem_Transaction_Subscriptioner();

                // set initial payment parameters for validation
                $trans->setPaysysId($paysys->getValue());
                $trans->setAmount($amount->getValue())
                    ->setReceiptId($receipt->getValue())
                    ->setTime(new DateTime());

                // add payment to the invoice
                $payment = $invoice->addPayment($trans);
            }

            if ($payment) {
                // set payment details (maybe the same, but why not)
                $payment->amount = $invoice->first_total;
                $payment->paysys_id = $paysys->getValue();
                $payment->receipt_id = $receipt->getValue();

                // save or create payment
                $payment->save();
            }

            $invoice->updateStatus();

            // access records, should always exist
            $accesses = $invoice->getAccessRecords();

            if (count($accesses)) {
                $access = $accesses[0];

                // set new or same (we don't care) date and product
                $access->begin_date = $dateBegin->getValue();
                $access->expire_date = $dateEnd->getValue();
                $access->product_id = $plan->product_id;

                if ($payment) {
                    $access->invoice_payment_id = $payment->invoice_payment_id;
                }
                else $access->invoice_payment_id = null;

                // save chanes (or save the same record once more)
                $access->save();
            }

            // after changes we update user cache because weird amember caches
            // lots of stuff for no sensible reason
            $this->getDi()->resourceAccessTable->updateCache($user_id);

            // change product on the invoice price, so user can see the changed
            // price, this might actually interfere with upgrade plugin
            if ($item) {
                $item->first_price = $item->first_total = $invoice->first_total;
                $item->save();
            }

            // OK, all done, lets let the browser know we are done
            return $this->ajaxResponse([ 'ok' => true ]);
        }
        else {
            // populate form with values from database or with the default
            // values

            // product
            if ($item && $plan) {
                // if invoice has product selected
                $productId = $plan->product_id;
                $products->setValue($planId);
            } // otherwise the first product will be selected

            // payment system
            if ($invoice && $invoice->paysys_id) {
                // if invoice has payment system
                $paysys->setValue($invoice->paysys_id);
            } // otherwise the first payment system will be selected

            // dates
            if ($access) {
                // if access record exists
                $dateBegin->setValue(amDate($access->begin_date));
                $dateEnd->setValue(amDate($access->expire_date));
            }
            else {
                // set date begin to current date
                $dateBegin->setValue(amDate(sqlTime(date('Y-m-d'))));

                if ($plan) {
                    // if payment is not done yet, and access does not exist,
                    // but plan is already chosen, put the actual end date
                    // according to the plan
                    $duration = $plan->first_period;
                    if ($duration != Am_Period::MAX_SQL_DATE) {
                        // convert weird amember interval represenation to
                        // something we can actually use
                        $duration = str_replace('m', 'month', $duration);
                        $duration = str_replace('y', 'year', $duration);
                        $duration = time() + strtotime($duration);
                    }
                    $date = date('Y-m-d', $duration);
                    $dateEnd->setValue(amDate(sqlTime($date)));
                }
                // otherwise we just set date to nothing, and let javascript
                // initialise it according to randomly chosen product
                else $dateEnd->setValue('');
            }

            // receipt and amount
            if ($payment) {
                // we can populate those only if payment is made
                $receipt->setValue($payment->receipt_id);
                $amount->setValue($payment->amount);
            }
            else {
                // otherwise we use default values
                $receipt->setValue('manual');

                // for amount we use default price for the product
                if ($invoice) $amount->setValue($invoice->first_total);
                // or if it is not selected, just leave it zero
                else $amount->setValue('0.00');
            }
        }

        // now we convert the form to the string and echo, because... amember
        echo $form->__toString();

        // someone said that you should chop off (N - 1) hands of a
        // programmer, where N is number of echo's in his PHP library/script
        //
        // ... well, it's not my fault
    }
}
