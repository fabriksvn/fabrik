<?php

/**
 * redirects the browser to subscriptions to perform payment
 * @package Joomla
 * @subpackage Fabrik
 * @author Rob Clayburn
 * @copyright (C) Rob Clayburn
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

//require the abstract plugin class
require_once(COM_FABRIK_FRONTEND . '/models/plugin-form.php');
JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fabrik/tables');

class plgFabrik_FormSubscriptions extends plgFabrik_Form {

	/**
	 * get the buisiness email either based on the accountemail field or the value
	 * found in the selected accoutnemail_element
	 * @param	object	$params
	 * @return	string	email
	 */
	
	protected function getBusinessEmail($params)
	{
		$w = $this->getWorker();
		$data = $this->getEmailData();
		$email = $w->parseMessageForPlaceHolder($this->params->get('subscriptions_accountemail'), $data);
		return $email;
	}

	/**
	* get transaction amount based on the cost field or the value
	* found in the selected cost_element
	* @param	object	$params
	* @return	string	cost
	*/
	
	protected function getAmount($params)
	{
		// @TODO replace with lookup of cost in Fabsub Plan Billing Cycles
		return '0.00';
	}

	/**
	* get transaction item name based on the item field or the value
	* found in the selected item_element
	* @return	array	item name
	*/
	
	protected function getItemName()
	{
		// @TODO replace with look up of plan name and billing cycle
		return array('raw item name', 'item name');
	}
	
	/**
	 * is this set up to be a subscription or single payment
	 * @return	bool
	 */
	
	protected function isSubscription()
	{
		return true;
	}
	
	/**
	 * append additional paypal values to the data to send to paypal
	 * @param	array	$opts
	 */
	
	protected function setSubscriptionValues(&$opts)
	{
		// @TODO replace site name and various placeholders
		$name = 'http://fabrikar.com/  {plan_name}  User: {fabsubs_users___name} ({fabsubs_users___username})';
		list($item_raw, $item) = $this->getItemName();
		
		$item_raw = JRequest::getVar('jos_fabrik_subs_users___billing_cycle');
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('cost, label, plan_name, duration AS p3, period_unit AS t3, ' . $db->Quote($item_raw) . ' AS item_number ')
		->from('#__fabrik_subs_plan_billing_cycle')
		->where('id = ' . $db->Quote($item_raw));
		
		$db->setQuery($query);
		$sub = $db->loadObject();
		
		if (is_object($sub))
		{
			$opts['p3'] = $sub->p3;
			$opts['t3'] = $sub->t3;
			$opts['a3'] = $sub->cost;
			//$opts['src'] = 1;
			$opts['no_note'] = 1;
			$opts['custom'] = '';
			$tmp = array_merge(JRequest::get('data'), JArrayHelper::fromObject($sub));
			$opts['item_name'] = $w->parseMessageForPlaceHolder($name, $tmp);//'http://fabrikar.com/ '.$sub->item_name. ' - User: subtest26012010 (subtest26012010)';
			$opts['invoice'] = uniqid('', true);
			// @TODO get this boolean src value from the billing cycle table
			$opts['src'] = $w->parseMessageForPlaceHolder('1', $tmp);
			$amount = $opts['amount'];
			unset($opts['amount']);
		}
		else
		{
			echo $db->getQuery();
			echo "<pre>";print_r($sub);exit;
			JError::raiseError(500, 'Could not determine subscription period, please check your settings');
		}
	}
	
	protected function getWorker()
	{
		if (!isset($this->w))
		{
			$this->w = new FabrikWorker();
		}
		return $this->w;
	}
	
	/**
	 * process the plugin, called at end of form submission
	 *
	 * @param	object	$params
	 * @param	object	form model
	 */

	function onAfterProcess(&$params, &$formModel)
	{
		$this->params = $params;
		$this->formModel = $formModel;
		$app = JFactory::getApplication();
		$data = $formModel->_fullFormData;
		$this->data = $data;
		if (!$this->shouldProcess('subscriptions_conditon'))
		{
			return true;
		}
		$emailData = $this->getEmailData();
		$w = $this->getWorker();
		$ipn = $this->getIPNHandler();
		
		$testMode = $this->params->get('subscriptions_testmode', false);
		$url = $testMode == 1 ? 'https://www.sandbox.paypal.com/us/cgi-bin/webscr?' : 'https://www.paypal.com/cgi-bin/webscr?';
		$opts = array();
		// @TODO switch this based on the gateway (jos_fabrik_subs_payment_gateways___subscription) value 
		$opts['cmd'] = '_xclick-subscriptions'; // _xclick-subscriptions or _xclick
		$opts['business'] = $this->getBusinessEmail($params);
		$opts['amount'] = $this->getAmount($params);
		list($item_raw, $item) = $this->getItemName($params);
		$opts['item_name'] = $item;
		if ($this->isSubscription())
		{
			$this->setSubscriptionValues($opts);
		}

		// $$$ rob 03/02/2011
		// check if we have a gateway subscription switch set up. This is for sites where
		// you can toggle between a subscription or a single payment. E.g. fabrikar com
		// if 'subscriptions_subscription_switch' is blank then use the $opts['cmd'] setting
		// if not empty it should be some eval'd PHP which needs to return true for the payment
		// to be treated as a subscription
		// We want to do this so that single payments can make use of Paypals option to pay via credit card
		// without a subscriptions account (subscriptions require a Paypal account)
		// We do this after the subscription code has been run as this code is still needed to look up the correct item_name

		/* $subSwitch = $params->get('subscriptions_subscription_switch');
		if (trim($subSwitch) !== '')
		{
			$subSwitch = $w->parseMessageForPlaceHolder($subSwitch);
			$isSub = @eval($subSwitch);
			if (!$isSub)
			{
				//reset the amount which was unset during subscription code
				$opts['amount'] = $amount;
				$opts['cmd'] = '_xclick';
				//unset any subscription options we may have set
				unset($opts['p3']);
				unset($opts['t3']);
				unset($opts['a3']);
				unset($opts['no_note']);
				//$opts['src'] = 0;
			}
		} */

		$opts['currency_code'] = $this->getCurrencyCode();
		$opts['notify_url'] = $this->getNotifyUrl();
		$opts['return'] = $this->getReturnUrl();
		$opts['custom'] = $this->getCustom();
		$qs = array();
		foreach ($opts as $k => $v)
		{
			$qs[] = $k . '=' . $v;
		}
		$url .= implode('&', $qs);
		// $$$ rob 04/02/2011 no longer doing redirect from ANY plugin EXCEPT the redirect plugin
		// - instead a session var is set (com_fabrik.form.X.redirect.url)
		// as the preferred redirect url

		$session = JFactory::getSession();
		$context = 'com_fabrik.form.' . $formModel->getId() . '.redirect.';

		// $$$ hugh - fixing issue with new redirect, which now needs to be an array.
		// Not sure if we need to preserve existing session data, or just create a new surl array,
		// to force ONLY recirect to Subscriptions?
		$surl = (array)$session->get($context.'url', array());
		$surl[$this->renderOrder] = $url;
		$session->set($context.'url', $surl);

		/// log the info
		

		$log = FabTable::getInstance('log', 'FabrikTable');
		$log->message_type = 'fabrik.subscriptions.onAfterProcess';
		$msg = new stdClass();
		$msg->opt = $opts;
		$msg->data = $data;
		$log->message = json_encode($msg);

		$log->store();
		return true;
	}
	
	/**
	 * get the currency code for the transaction e.g. USD
	 * return	string	currency code
	 */
	
	protected function getCurrencyCode()
	{
		// @TODO replace with Fabsub Plan Billing Cycles (jos_fabrik_subs_plan_billing_cycle___currency)
		$data = $this->getEmailData();
		return $this->getWorker()->parseMessageForPlaceHolder('USD', $data);
	}

	/**
	 * create the custom string value you can pass to Paypal
	 * @return	string
	 */

	protected function getCustom()
	{
		return $this->data['formid'] . ':' . $this->data['rowid'];
	}

	/**
	 * get the url that payment notifications (IPN) are sent to
	 * @return	string	url
	 */
	
	protected function getNotifyUrl()
	{
		$testSite = $this->params->get('subscriptions_test_site', '');
		$testSiteQs = $this->params->get('subscriptions_test_site_qs', '');
		$testMode = $this->params->get('subscriptions_testmode', false);
		$ppurl  = ($testMode == 1 && !empty($testSite)) ? $testSite : COM_FABRIK_LIVESITE;
		$ppurl .= '/index.php?option=com_fabrik&task=plugin.pluginAjax&formid=' . $formModel->get('id') . '&g=form&plugin=subscriptions&method=ipn';
		if ($testMode == 1 && !empty($testSiteQs))
		{
			$ppurl .= $testSiteQs;
		}
		$ppurl .= '&renderOrder=' . $this->renderOrder;
		urlencode($ppurl);
	}
	
	/**
	 * make the return url, this is the page you return to after paypal has component the transaction.
	 * @return	string	url.
	 */
	
	protected function getReturnUrl()
	{
		$url = '';
		$testSite = $this->params->get('subscriptions_test_site', '');
		$testSiteQs = $this->params->get('subscriptions_test_site_qs', '');
		$testMode = (bool)$this->params->get('subscriptions_testmode', false);
		
		$qs = '/index.php?option=com_fabrik&task=plugin.pluginAjax&formid=' . $this->formModel->get('id') . '&g=form&plugin=subscriptions&method=thanks&rowid=' . $this->data['rowid']. '&renderOrder=' . $this->renderOrder;
		
		if ($testMode)
		{
			$url = !empty($testSite) ? $testSite . $qs : COM_FABRIK_LIVESITE . $qs;
			if (!empty($testSiteQs))
			{
				$url .= $testSiteQs;
			}
		}
		else
		{
			$url = COM_FABRIK_LIVESITE . $qs;
		}
		return urlencode($url);
	}
	
	function onThanks()
	{
		$formid = JRequest::getInt('formid');
		$rowid = JRequest::getInt('rowid');
		JModel::addIncludePath(COM_FABRIK_FRONTEND . '/models');
		$formModel = JModel::getInstance('Form', 'FabrikFEModel');
		$formModel->setId($formid);
		$params = $formModel->getParams();
		$ret_msg = (array)$params->get('subscriptions_return_msg', array());
		$ret_msg = $ret_msg[JRequest::getInt('renderOrder')];
		if ($ret_msg)
		{
			$w = $this->getWorker();
			$listModel = $formModel->getlistModel();
			$row = $listModel->getRow($rowid);
			$ret_msg = $w->parseMessageForPlaceHolder($ret_msg, $row);
			if (stristr($ret_msg,'[show_all]'))
			{
				$all_data = array();
				foreach ($_REQUEST as $key => $val)
				{
					$all_data[] = "$key: $val";
				}
				JRequest::setVar('show_all', implode('<br />',$all_data));
			}
			$ret_msg = str_replace('[','{',$ret_msg);
			$ret_msg = str_replace(']','}',$ret_msg);
			$ret_msg = $w->parseMessageForPlaceHolder($ret_msg, $_REQUEST);
			echo $ret_msg;
		}
		else
		{
			echo JText::_("thanks");
		}
	}


	/**
	 * called from subscriptions at the end of the transaction
	 */

	function onIpn()
	{
		$config = JFactory::getConfig();
		$log = FabTable::getInstance('log', 'FabrikTable');
		$log->referring_url = $_SERVER['REQUEST_URI'];
		$log->message_type = 'fabrik.ipn.start';
		$log->message = json_encode($_REQUEST);
		$log->store();

		//lets try to load in the custom returned value so we can load up the form and its parameters
		$custom = JRequest::getVar('custom');
		list($formid, $rowid) = explode(":", $custom);

		//pretty sure they are added but double add
		JModel::addIncludePath(COM_FABRIK_FRONTEND . '/models');
		$formModel = JModel::getInstance('Form', 'FabrikFEModel');
		$formModel->setId($formid);
		$listModel = $formModel->getlistModel();
		$params = $formModel->getParams();
		$table = $listModel->getTable();
		$db = $listModel->getDb();

		// $$$ hugh
		// @TODO shortColName won't handle joined data, need to fix this to use safeColName
		// (don't forget to change nameQuote stuff later on as well)
		$renderOrder = JRequest::getInt('renderOrder');
		$ipn_txn_field = 'pp_txn_id';
		$ipn_payment_field = 'amount';

		$ipn_status_field = 'pp_payment_status';

		$w = $this->getWorker();

		$email_from = $admin_email = $config->get('mailfrom');
		// read the post from Subscriptions system and add 'cmd'
		$req = 'cmd=_notify-validate';
		foreach ($_POST as $key => $value)
		{
			$value = urlencode(stripslashes($value));
			$req .= '&' . $key . '=' . $value;
		}

		// post back to Subscriptions system to validate
		$header .= "POST /cgi-bin/webscr HTTP/1.0\r\n";
		$header .= "Host: www.paypal.com:443\r\n";
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$header .= "Content-Length: " . strlen($req) . "\r\n\r\n";

		$subscriptionsurl ($_POST['test_ipn'] == 1) ? 'ssl://www.sandbox.paypal.com' : 'ssl://www.paypal.com';

		// assign posted variables to local variables
		$item_name = JRequest::getVar('item_name');
		$item_number = JRequest::getVar('item_number');
		$payment_status = JRequest::getVar('payment_status');
		$payment_amount = JRequest::getVar('mc_gross');
		$payment_currency = JRequest::getVar('mc_currency');
		$txn_id = JRequest::getVar('txn_id');
		$txn_type = JRequest::getVar('txn_type');
		$receiver_email = JRequest::getVar('receiver_email');
		$payer_email = JRequest::getVar('payer_email');

		$status = 'ok';
		$err_msg = '';
		if (empty($formid) || empty($rowid))
		{
			$status = 'form.subscriptions.ipnfailure.custom_error';
			$err_msg = "formid or rowid empty in custom: $custom";
		}
		else
		{
			//@TODO implement a curl alternative as fsockopen is not always available
			$fp = fsockopen ($subscriptionsurl, 443, $errno, $errstr, 30);
			if (!$fp)
			{
				$status = 'form.subscriptions.ipnfailure.fsock_error';
				$err_msg = "fsock error: $errno;$errstr";
			}
			else
			{
				fputs ($fp, $header . $req);
				while (!feof($fp))
				{
					$res = fgets ($fp, 1024);
					// subscriptions steps (from their docs):
					// check the payment_status is Completed
					// check that txn_id has not been previously processed
					// check that receiver_email is your Primary Subscriptions email
					// check that payment_amount/payment_currency are correct
					// process payment
					if (strcmp ($res, "VERIFIED") == 0)
					{
						
						$query = $db->getQuery(true);
						$query->select($ipn_status_field)->from('#__fabrik_subs_invoices')
						->where($db->quoteName($ipn_txn_field) . ' = ' . $db->Quote($txn_id));
						$db->setQuery($query);
						$txn_result = $db->loadResult();
						if (!empty($txn_result))
						{
							if ($txn_result == 'Completed')
							{
								if ($payment_status != 'Reversed' && $payment_status != 'Refunded')
								{
									$status = 'form.subscriptions.ipnfailure.txn_seen';
									$err_msg = "transaction id already seen as Completed, new payment status makes no sense: $txn_id, $payment_status";
								}
							}
							else if ($txn_result == 'Reversed')
							{
								if ($payment_status != 'Canceled_Reversal')
								{
									$status = 'form.subscriptions.ipnfailure.txn_seen';
									$err_msg = "transaction id already seen as Reversed, new payment status makes no sense: $txn_id, $payment_status";
								}
							}
						}
						if ($status == 'ok')
						{
							$set_list = array();
						
							$set_list[$ipn_txn_field] = $txn_id;
							$set_list[$ipn_payment_field] = $payment_amount;
							$set_list[$ipn_status_field] = $payment_status;
							
							$ipn = $this->getIPNHandler($params, $renderOrder);

							if ($ipn !== false)
							{
								$request = $_REQUEST;
								$ipn_function = 'payment_status_' . $payment_status;
								if (method_exists($ipn, $ipn_function))
								{
									$status = $ipn->$ipn_function($listModel, $request, $set_list, $err_msg);
									if ($status != 'ok')
									{
										break;
									}
								}
								$txn_type_function = 'txn_type_' . $txn_type;
								if (method_exists($ipn, $txn_type_function))
								{
									$status = $ipn->$txn_type_function($listModel, $request, $set_list, $err_msg);
									if ($status != 'ok')
									{
										break;
									}
								}
							}

							if (!empty($set_list))
							{
								$set_array = array();
								foreach ($set_list as $set_field => $set_value)
								{
									$set_value = $db->Quote($set_value);
									$set_field = $db->quoteName($set_field);
									$set_array[] = "$set_field = $set_value";
								}
								$query = $db->getQuery(true);
								$query->update('#__fabrik_subs_invoices')
								->set( implode(',', $set_array))->where('id = ' . $db->Quote($rowid));
								$db->setQuery($query);
								if (!$db->query())
								{
									$status = 'form.subscriptions.ipnfailure.query_error';
									$err_msg = 'sql query error: ' . $db->getErrorMsg();
								}
							}
						}
					}
					else if (strcmp ($res, "INVALID") == 0)
					{
						$status = 'form.subscriptions.ipnfailure.invalid';
						$err_msg = 'subscriptions postback failed with INVALID';
					}
				}
				fclose ($fp);
			}
		}

		$receive_debug_emails = (array)$params->get('subscriptions_receive_debug_emails');
		$receive_debug_emails = $receive_debug_emails[$renderOrder];
		$send_default_email = (array)$params->get('subscriptions_send_default_email');
		$send_default_email = $send_default_email[$renderOrder];
		if ($status != 'ok')
		{
			foreach ($_POST as $key => $value)
			{
				$emailtext .= $key . " = " .$value ."\n\n";
			}

			if ($receive_debug_emails == '1')
			{
				$subject = $config->get('sitename').": Error with Fabrik Subscriptions IPN";
				JUtility::sendMail($email_from, $email_from, $admin_email, $subject, $emailtext, false);
			}
			$log->message_type = $status;
			$log->message = $emailtext ."\n//////////////\n" . $res ."\n//////////////\n". $req .  "\n//////////////\n".$err_msg;
			if ($send_default_email == '1')
			{
				$payer_emailtext = "There was an error processing your Subscriptions payment.  The administrator of this site has been informed.";
				JUtility::sendMail($email_from, $email_from, $payer_email, $subject, $payer_emailtext, false);
			}
		}
		else
		{
			foreach ($_POST as $key => $value)
			{
				$emailtext .= $key . " = " .$value ."\n\n";
			}
			if ($receive_debug_emails == '1')
			{
				$subject = $config->get('sitename') . ': IPN ' . $payment_status;
				JUtility::sendMail($email_from, $email_from, $admin_email, $subject, $emailtext, false);
			}
			$log->message_type = 'form.subscriptions.ipn.' . $payment_status;
			$query = $db->getQuery();
			$log->message = $emailtext ."\n//////////////\n" . $res ."\n//////////////\n". $req .  "\n//////////////\n".$query;

			if ($send_default_email == '1')
			{
				$payer_subject = "Subscriptions success";
				$payer_emailtext = "Your Subscriptions payment was succesfully processed.  The Subscriptions transaction id was $txn_id";
				JUtility::sendMail( $email_from, $email_from, $payer_email, $payer_subject, $payer_emailtext, false);
			}
		}

		$log->message .= "\n IPN custom function = $ipn_function";
		$log->message .= "\n IPN custom transaction function = $txn_type_function";
		$log->store();
		jexit();
	}

	/**
	 * get the custom IPN class
	 * @return	object	ipn handler class
	 */

	protected function getIPNHandler()
	{
		require_once('plugins/fabrik_form/subscriptions/scripts/ipn.php');
		return new fabrikSubscriptionsIPN();
	}
}

?>