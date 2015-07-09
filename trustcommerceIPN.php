<?php
/*
 * This file is part of CiviCRM.
 *
 * CiviCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * CiviCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with CiviCRM.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Copyright (C) 2012
 * Licensed to CiviCRM under the GPL v3 or higher
 *
 * Modified by Lisa Marie Maginnis <lisa@fsf.org> (http://www.fsf.org)
 *
 */

class CRM_Core_Payment_trustcommerce_IPN extends CRM_Core_Payment_BaseIPN {
  function __construct() {
    parent::__construct();
  }


  function main($component = 'contribute') {
  static $no = NULL;
    $billingid = CRM_Utils_Request::retrieve('billingid', 'String', $no, FALSE, 'GET');
    $input['status'] = CRM_Utils_Request::retrieve('status', 'String', $no, FALSE, 'GET');
    $input['amount'] = CRM_Utils_Request::retrieve('amount', 'String', $no, FALSE, 'GET');
    $input['date'] = CRM_Utils_Request::retrieve('date', 'String', $no, FALSE, 'GET');
    $input['trxn_id'] = CRM_Utils_Request::retrieve('trxn_id', 'String', $no, FALSE, 'GET');
    $checksum = CRM_Utils_Request::retrieve('checksum', 'String', $no, FALSE, 'GET');

    if ($billingid) {
      if( $input['status'] == '' || $input['amount'] == '' || $input['date'] == '' || $input['trxn_id'] == '' || md5($billingid.$input['trxn_id'].$input['amount'].$input['date']) != $checksum) {
	 CRM_Core_Error::debug_log_message("Error: IPN called with out proper fields");
	 echo "Error: invalid paramaters<p>\n";
	 exit;
       }


       $ids = $objects = array();
       $input['component'] = $component;

       // load post ids in $ids
       $ids = NULL;
       $ids = $this->getIDs($billingid, $input, $input['component']);

       $ids['trxn_id'] = $input['trxn_id'];

       if($this->checkDuplicate($input, $ids) != NULL) {
	 CRM_Core_Error::debug_log_message("Success: This payment has already been processed.");
	 echo "Success: This payment has already been processed<p>\n";
	 exit;
       }
       var_dump($ids);
       var_dump($input);

       if(array_key_exists('membership', $ids)) {
	 $membership = array();
	 $params = array('id' => $ids['membership']);
	 $obj = CRM_Member_BAO_Membership::retrieve($params, $membership);
	 $objects['membership'] = array(&$obj);
       }
       var_dump($ids);
       var_dump($input);

      $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_PaymentProcessorType',
							'TrustCommerce', 'id', 'name'
							);

      if (!$this->validateData($input, $ids, $objects, TRUE, $paymentProcessorID)) {
        return FALSE;
      }
      //     var_dump($objects);
      
      if ($component == 'contribute' && $ids['contributionRecur']) {
        // check if first contribution is completed, else complete first contribution
        $first = TRUE;
        if ($objects['contribution']->contribution_status_id == 1) {
          $first = FALSE;
        }

	
	return $this->processRecur($input, $ids, $objects, $first);
	
    }

  }
}

  protected function checkDuplicate($input, $ids) {
//    $sql='select id from civicrm_contribution where receive_date like \''.$input['date'].'%\' and total_amount='.$input['amount'].' and contact_id='.$ids['contact'].' and contribution_status_id =  1 limit 1';
    $sql="select id from civicrm_contribution where trxn_id = '".$ids['trxn_id']."'";


    $result = CRM_Core_DAO::executeQuery($sql);
    $result->fetch();
    $id = $result->id;
    return $id;
  }
  protected function processRecur($input, $ids, $objects, $first) {
    $recur = &$objects['contributionRecur'];
    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

    $transaction = new CRM_Core_Transaction();

    $now = date('YmdHis');

    // fix dates that already exist
    $dates = array('create_date', 'start_date', 'end_date', 'cancel_date', 'modified_date');
    foreach ($dates as $name) {
      if ($recur->$name) {
        $recur->$name = CRM_Utils_Date::isoToMysql($recur->$name);
      }
    }

    if (!$first) {
      // create a contribution and then get it processed
      $contribution = new CRM_Contribute_BAO_Contribution();
      $contribution->contact_id = $ids['contact'];
      $contribution->financial_type_id  = $objects['contributionType']->id;
      $contribution->contribution_page_id = $ids['contributionPage'];
      $contribution->contribution_recur_id = $ids['contributionRecur'];
      $contribution->receive_date = $input['date'];
      $contribution->currency = $objects['contribution']->currency;
      $contribution->payment_instrument_id = 1;
      $contribution->amount_level = $objects['contribution']->amount_level;
      $contribution->address_id = $objects['contribution']->address_id;
      $contribution->honor_contact_id = $objects['contribution']->honor_contact_id;
      $contribution->honor_type_id = $objects['contribution']->honor_type_id;
      $contribution->campaign_id = $objects['contribution']->campaign_id;
      $contribution->total_amount = $input['amount']; 

      $objects['contribution'] = &$contribution;
    }
    $objects['contribution']->invoice_id = md5(uniqid(rand(), TRUE));
    //    $objects['contribution']->total_amount = $objects['contribution']->total_amount;
    $objects['contribution']->trxn_id = $input['trxn_id'];

    // since we have processor loaded for sure at this point,

    $sendNotification = FALSE;
    if ($input['status'] == 1) {

      // Approved
      if ($first) {
        $recur->start_date = $now;
        $sendNotification = TRUE;
        $subscriptionPaymentStatus = CRM_Core_Payment::RECURRING_PAYMENT_START;
      }
      $statusName = 'In Progress';
      if (($recur->installments > 0) &&
	  ($input['subscription_paynum'] >= $recur->installments)
	  ) {
        // this is the last payment
        $statusName = 'Completed';
        $recur->end_date = $now;

        $sendNotification = TRUE;
        $subscriptionPaymentStatus = CRM_Core_Payment::RECURRING_PAYMENT_END;
      }
      $recur->trxn_id = $input['trxn_id'];
      $recur->total_amount = $input['amount'];
      $recur->payment_instrument_id = 1;
      $recur->fee = NULL;
      $recur->net_amount = NULL;

      $recur->modified_date = $now;
      $recur->contribution_status_id = array_search($statusName, $contributionStatus);
      $recur->save();
    }
    else {
      // Declined
      // failed status

      $recur->trxn_id = $input['trxn_id'];
      $recur->total_amount = $input['amount'];
      $recur->payment_instrument_id = 1;
      $recur->fee = NULL;
      $recur->net_amount = NULL;


      $recur->contribution_status_id = array_search('Failed', $contributionStatus);
      $recur->cancel_date = $now;
      $recur->save();

      CRM_Core_Error::debug_log_message("Subscription payment failed");

      // the recurring contribution has declined a payment or has failed
      // so we just fix the recurring contribution and not change any of
      // the existing contribiutions
      // CRM-9036
      return TRUE;

    }


    // check if contribution is already completed, if so we ignore this ipn
    if ($objects['contribution']->contribution_status_id == 1) {
      $transaction->commit();
      CRM_Core_Error::debug_log_message("returning since contribution has already been handled");
      echo 'Success: Contribution has already been handled<p>';
      echo '';
      return TRUE;
    }
    $input['is_test'] = 0;

    $this->completeTransaction($input, $ids, $objects, $transaction, $recur);

      echo 'Success: Created new contribution: '.$ids['contribution'].' for cid: '.$ids['contact'].'\n';
      CRM_Core_Error::debug_log_message('Success: Created new contribution: '.$ids['contribution'].' for cid: '.$ids['contact']);

    if ($sendNotification) {
      $autoRenewMembership = FALSE;
      if ($recur->id &&
	  isset($ids['membership']) && $ids['membership']
	  ) {
        $autoRenewMembership = TRUE;
      }




      //send recurring Notification email for user
      CRM_Contribute_BAO_ContributionPage::recurringNotify($subscriptionPaymentStatus,
							   $ids['contact'],
							   $ids['contributionPage'],
							   $recur,
							   $autoRenewMembership
							   );




    }



  }

 protected function getIDs($billingid, $input, $module) {
    $sql = "SELECT cr.id, cr.contact_id, co.id as coid
    	       FROM civicrm_contribution_recur cr
	       INNER JOIN civicrm_contribution co ON co.contribution_recur_id = cr.id
   	       WHERE cr.processor_id = '$billingid' LIMIT 1";


    $result = CRM_Core_DAO::executeQuery($sql);
    $result->fetch();
    $ids['contribution'] = $result->coid;
    $ids['contributionRecur'] = $result->id;
    $ids['contact'] = $result->contact_id;

    if (!$ids['contributionRecur']) {
      CRM_Core_Error::debug_log_message("Could not find billingid: ".$billingid);
      echo "Failure: Could not find contributionRecur<p>\n";
      exit();
    }

    // get page id based on contribution id
    $ids['contributionPage'] = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
      $ids['contribution'],
      'contribution_page_id'
    );

    if ($module == 'event') {
      // FIXME: figure out fields for event
    }
    else {
      // get the optional ids

      // Get membershipId. Join with membership payment table for additional checks
      $sql = "
    SELECT m.id
      FROM civicrm_membership as m
     WHERE m.contribution_recur_id = '{$ids['contributionRecur']}'
     LIMIT 1";
      if ($membershipId = CRM_Core_DAO::singleValueQuery($sql)) {

	$ids['membership'] = $membershipId;
      }
      
    }

  return $ids;
}



}