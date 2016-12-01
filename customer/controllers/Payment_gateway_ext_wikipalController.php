<?php defined('MW_PATH') || exit('No direct script access allowed');
/** 
 * Controller file for service process.
 * 
 * @package MailWizz EMA
 * @subpackage Payment Gateway Wikipal
 * @author Serban George Cristian <cristian.serban@mailwizz.com> 
 * @link http://www.mailwizz.com/
 * @copyright 2013-2014 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 */
 
class Payment_gateway_ext_wikipalController extends Controller
{
    // the extension instance
    public $extension;
    
    /**
     * Process the IPN
     */
    public function actionIpn()
    {
		@session_start();

        if (!Yii::app()->request->isPostRequest) {
            $this->redirect(array('price_plans/index'));
        }
        
        $postData 			= Yii::app()->params['POST'];
		$postData['custom'] = $_SESSION['custom'];
		$postData['custon'] = $_SESSION['custon'];

        if (!$postData->itemAt('custom')) {
            header('location: '.$_SESSION['cancelUrl']);
            Yii::app()->end();
        }

        $transaction = PricePlanOrderTransaction::model()->findByAttributes(array(
            'payment_gateway_transaction_id' => $postData->itemAt('custom'),
            'status'                         => PricePlanOrderTransaction::STATUS_PENDING_RETRY,
        ));
        if (empty($transaction)) {
            header('location: '.$_SESSION['cancelUrl']);
            Yii::app()->end();
        }
        
        $newTransaction = clone $transaction;
        $newTransaction->transaction_id                 = null;
        $newTransaction->transaction_uid                = null;
        $newTransaction->isNewRecord                    = true;
        $newTransaction->date_added                     = new CDbExpression('NOW()');
        $newTransaction->status                         = PricePlanOrderTransaction::STATUS_FAILED;
        $newTransaction->payment_gateway_response       = print_r($postData->toArray(), true);
        $newTransaction->payment_gateway_transaction_id = $postData->itemAt('authority');
        
        $model = $this->extension->getExtModel();

		$MerchantID 		= $model->merchant;
		$Price 				= $_POST['Price'];
		$Authority 			= $_POST['authority'];
		$InvoiceNumber 		= $_POST['InvoiceNumber'];
		$result          	= '0';
		
		if ($_POST['status'] == 1) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, 'http://gatepay.co/webservice/paymentVerify.php');
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
			curl_setopt($curl, CURLOPT_POSTFIELDS, "MerchantID=$MerchantID&Price=$Price&Authority=$Authority");
			curl_setopt($curl, CURLOPT_TIMEOUT, 400);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			$resultPayment = json_decode(curl_exec($curl));
			curl_close($curl);
			if ($resultPayment->Status == 100) {
				$result = '1';
			} else {
				$result = '0';
			}
		} else {
			$result = '0';
		}

        $paymentStatus  = $result=='1'?'completed':'failed';//strtolower(trim($postData->itemAt('payment_status'))); 
        $paymentPending = strpos($paymentStatus, 'pending') === 0;
        $paymentFailed  = strpos($paymentStatus, 'failed') === 0;
        $paymentSuccess = strpos($paymentStatus, 'completed') === 0;
        
        $verified  = $result=='1'?true:false;//strpos(strtolower(trim($request['message'])), 'verified') === 0;
        $order     = $transaction->order;
        
        if ($order->status == PricePlanOrder::STATUS_COMPLETE) {
            $newTransaction->save(false);
				header('location: '.$_SESSION['cancelUrl']);
				echo('double spending');
            Yii::app()->end();
        }
        
        if (!$verified || $paymentFailed) {
            $order->status = PricePlanOrder::STATUS_FAILED;
            $order->save(false);
            
            $transaction->status = PricePlanOrderTransaction::STATUS_FAILED;
            $transaction->save(false);
            
            $newTransaction->save(false);
				header('location: '.$_SESSION['cancelUrl']);
            echo('failed');
            Yii::app()->end();
        }
        
        if ($paymentPending) {
            $newTransaction->status = PricePlanOrderTransaction::STATUS_PENDING_RETRY;
            $newTransaction->save(false);
				echo('pending');
				header('location: '.$_SESSION['cancelUrl']);
            Yii::app()->end();
        }
        
        $order->status = PricePlanOrder::STATUS_COMPLETE;
        $order->save(false);
        
        $transaction->status = PricePlanOrderTransaction::STATUS_SUCCESS;
        $transaction->save(false);
        
        $newTransaction->status = PricePlanOrderTransaction::STATUS_SUCCESS;
        $newTransaction->save(false);
		  header('location: '.$_SESSION['returnUrl']);
		  echo('completed');
        Yii::app()->end();
    }
}