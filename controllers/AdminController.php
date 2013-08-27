<?php

class Subscriptioner_AdminController extends Am_Controller 
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper(); // only super-admins allowed to open this
    }
    
    function indexAction()
    {
        $this->view->title = "Subscriptioner Module Admin Page";
        $this->view->content = "Some HTML specific for your module admin page";
        // lay it out to admin header/footer
        $this->view->display('admin/layout.phtml');
    }
	
	function subscriptionsTabAction()
    {
		$this->user_id = $this->getInt('user_id');
        if (!$this->user_id)
            throw new Am_Exception_InputError("Wrong URL specified: no member# passed");
        $this->view->user_id = $this->user_id;
		
		$this->getDi()->plugins_payment->loadEnabled();
        $this->view->invoices = $this->getDi()->invoiceTable->findByUserId($this->user_id);
        
        foreach($this->view->invoices as $invoice)
        {
            if ($invoice->getStatus() == Invoice::RECURRING_ACTIVE)
            {
                $invoice->_cancelUrl = null;
                $ps = $this->getDi()->plugins_payment->loadGet($invoice->paysys_id, false);
                if ($ps)
                    $invoice->_cancelUrl = $ps->getAdminCancelUrl($invoice);
            }
        }

        $this->view->accessRecords = $this->getAccessRecords();
		
		$this->view->title = "User Subscriptions";
		$this->view->content = "Some Content";
		$this->view->display('admin/subscriptioner/subscriptions.phtml');
    }
	
	function getSubscriptionInfo($id) {
		return $this->getDi()->db->select("
			SELECT a.*, p.product_id, p.title as product_title,
			m.amount, m.paysys_id, m.receipt_id
            FROM ?_access a
			LEFT JOIN ?_product p USING (product_id)
			LEFT JOIN ?_invoice_payment m USING (invoice_payment_id)
            WHERE a.access_id = ?d
            ", $id);
	}
	
	function subscriptionAction()
    {
		$aid = $this->getInt('id');
		
		if($aid <= 0) throw new Am_Exception_InputError("Wrong Access #");
		$access = $this->getDi()->db->select("SELECT invoice_id, invoice_payment_id, user_id FROM ?_access WHERE access_id=?d", $aid);
		$id = $access[0]['invoice_payment_id'];
		$iid = $access[0]['invoice_id'];
		$tid = $access[0]['transaction_id'];
		$uid = $access[0]['user_id'];
		
		//var_dump($id, $iid);
		
		$info = $this->getSubscriptionInfo($aid);
		if(empty($info) || empty($info[0])) throw new Am_Exception_InputError("Wrong Payment #");
		$info = $info[0];
		
		$pr = $this->getDi()->productTable->load($info['product_id']);
		$pk = $pr->pk();
		
		if($id <= 0) {
			$info['receipt_id'] = 'manual';
			$info['amount'] = '0.00';
		}

		// form
        $form = new Am_Form_Admin('subscription-info-form');
        $form->setDataSources(array($this->_request));
        $form->method = 'post';
        $form->addHidden('invoice_id')->setValue($iid);
		
		if($form->isSubmitted()) $pk = 0;
		
		// product
        $sel = $form->addSelect('product_id')->setLabel('Subscription');    
        foreach($this->getDi()->billingPlanTable->getProductPlanOptions() as $k => $v)
			$sel->addOption($v, $k, strpos($k, $pk.'-') === 0 ? "selected='selected'" : '');
		$sel->addRule('required');
			
		// dates
		$date_begin = $form->addDate('begin_date')->setLabel('Subscription Begin');
		if(!$form->isSubmitted()) $date_begin->setValue(amDate($info['begin_date']));
		$date_begin->addRule('required');
		$date_end = $form->addDate('expire_date')->setLabel('Subscription End');
		if(!$form->isSubmitted()) $date_end->setValue(amDate($info['expire_date']));
		$date_end->addRule('required');
		
		// payment system
		if($form->isSubmitted()) $info['paysys_id'] = 0;
		$sel2 = $form->addSelect('paysys_id')->setLabel(___('Payment System'));
		foreach ($this->getDi()->paysystemList->getOptions() as $k => $v)
			$sel2->addOption($v, $k, ($k === $info['paysys_id']) ? "selected='selected'" : '');
		$sel2->addOption('Manual', 'manual', ("manual" === $info['paysys_id']) ? "selected='selected'" : '');
        $sel2->addRule('required');
		
		// receipt
		$receipt = $form->addText('receipt')->setLabel(___('Receipt, #'))
            ->setId('invoice-receipt');
		if(!$form->isSubmitted()) $receipt->setValue($info['receipt_id']);
		
		// amount
		$amount = $form->addText('amount')->setLabel(___('Amount, $'))
            ->setId('payment-amount');
		if(!$form->isSubmitted()) $amount->setValue($info['amount']);
		
		// save
        $form->addSubmit('_save', array('value' => ___('Save')));
        if ($form->isSubmitted() && $form->validate())
        {
			list($p,$b) = explode("-", $sel->getValue(), 2);
			$d1 = $date_begin->getValue();
			$d2 = $date_end->getValue();
			$pay = $sel2->getValue();
			$re = $receipt->getValue();
			$am = $amount->getValue();
            try {
				if($iid <= 0) {
					// create invoice
					$invoice = $this->getDi()->invoiceRecord;
					$invoice->setUser($this->getDi()->userTable->load($info['user_id']));
					$invoice->tm_added = sqlTime(date("Y-m-d"));
					$products = $this->getDi()->billingPlanTable->load($b);
					$product = $products->getProduct();
					$invoice->add($product, 1);
					$invoice->calculate();
					$invoice->setPaysystem('paypal');
					$invoice->data()->set('added-by-admin', $this->getDi()->authAdmin->getUserId());
					$invoice->save();
					$temp = $this->getDi()->db->query("SELECT invoice_id FROM ?_invoice
						ORDER BY invoice_id DESC");
					$iid = $temp[0]['invoice_id'];
					$this->getDi()->db->query("UPDATE ?_invoice SET status=1 WHERE invoice_id=?d", $iid);
				}
				$this->getDi()->db->query("UPDATE ?_invoice SET paysys_id=?, first_total=?d WHERE invoice_id=?d", $pay, $am, $iid);
				if($id > 0) {
					$this->getDi()->db->query("
						UPDATE ?_invoice_payment
						SET paysys_id=?, receipt_id=?, dattm=?, amount=?
						WHERE invoice_id=?d",
						$pay, $re, date("Y-m-d"), (float)$am, $iid);
				}
				else {
					$tid = 'manual-manual-'.time();
					$this->getDi()->db->query("
						INSERT INTO ?_invoice_payment
						(invoice_id, user_id, paysys_id, receipt_id, transaction_id, dattm, currency, amount)
						VALUES (?d, ?d, ?, ?, ?, ?, 'USD', ?)
					", $iid, $info['user_id'], $pay, $re, $tid, date("Y-m-d"), (float)$am, $aid);
					$temp = $this->getDi()->db->query("SELECT invoice_payment_id FROM ?_invoice_payment
						WHERE invoice_id=?d ORDER BY invoice_payment_id DESC", $iid);
					$id = $temp[0]['invoice_payment_id'];
				}
				$this->getDi()->db->query("UPDATE ?_access SET
					product_id=?d, begin_date=?, expire_date=?, invoice_payment_id=?, invoice_id=?, transaction_id=?
					WHERE access_id=?d",
					$p, $d1, $d2, $id, $iid, $tid, $aid);
				$s = 1;
				if(strtotime($d2) < strtotime(date('Y-m-d'))) $s = 2;
				$this->getDi()->db->query("UPDATE ?_user_status SET
					status=?d
					WHERE user_id=?d AND product_id=?d",
					$s, $uid, $p);
                if($s == 1) {
                    $this->getDi()->db->query("UPDATE ?_user SET
                        status=?d
                        WHERE user_id=?d",
                        $s, $uid);
                }
				return $this->ajaxResponse(array('ok'=>true));
            } catch(Am_Exception $e) {
				var_dump($e);
				echo "Error has occured, please recheck the data";
            }
        }
        echo $form->__toString();
    }
	
	function getAccessRecords()
    {
        return $this->getDi()->accessTable->selectObjects("SELECT a.*, p.title as product_title, m.amount, m.paysys_id
            FROM ?_access a LEFT JOIN ?_product p USING (product_id)
			LEFT JOIN ?_invoice_payment m USING (invoice_payment_id)
            WHERE a.user_id = ?d
            ORDER BY begin_date, expire_date, product_title
            ", $this->user_id);
    }
	
	function preDispatch()
    {
        
    }
	
	protected $user_id;
}
