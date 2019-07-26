<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_j2store
 * @subpackage 	Zibal
 * @copyright   zibal team => https://zibal.ir
 * @copyright   Copyright (C) 2017 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/plugins/payment.php');
if (!class_exists ('checkHack')) {
    require_once( JPATH_PLUGINS . '/j2store/payment_zibal/zibal_inputcheck.php');
}

class plgJ2StorePayment_zibal extends J2StorePaymentPlugin
{
    var $_element    = 'payment_zibal';

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage( 'com_j2store', JPATH_ADMINISTRATOR );
    }


    function onJ2StoreCalculateFees($order) {
        $payment_method = $order->get_payment_method ();

        if ($payment_method == $this->_element) {
            $total = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
            $surcharge = 0;
            $surcharge_percent = $this->params->get ( 'surcharge_percent', 0 );
            $surcharge_fixed = $this->params->get ( 'surcharge_fixed', 0 );
            if (( float ) $surcharge_percent > 0 || ( float ) $surcharge_fixed > 0) {
                // percentage
                if (( float ) $surcharge_percent > 0) {
                    $surcharge += ($total * ( float ) $surcharge_percent) / 100;
                }

                if (( float ) $surcharge_fixed > 0) {
                    $surcharge += ( float ) $surcharge_fixed;
                }

                $name = $this->params->get ( 'surcharge_name', JText::_ ( 'J2STORE_CART_SURCHARGE' ) );
                $tax_class_id = $this->params->get ( 'surcharge_tax_class_id', '' );
                $taxable = false;
                if ($tax_class_id && $tax_class_id > 0)
                    $taxable = true;
                if ($surcharge > 0) {
                    $order->add_fee ( $name, round ( $surcharge, 2 ), $taxable, $tax_class_id );
                }
            }
        }
    }

    function _prePayment( $data )
    {
        $app	= JFactory::getApplication();
        $vars = new JObject();
        $vars->order_id = $data['order_id'];
        $vars->orderpayment_id = $data['orderpayment_id'];
        $vars->orderpayment_amount = $data['orderpayment_amount'];
        $vars->orderpayment_type = $this->_element;
        $vars->button_text = $this->params->get('button_text', 'J2STORE_PLACE_ORDER');
        //============================================================================
        $vars->display_name = 'درگاه پرداخت آنلاین زیبال';
        $vars->merchant_id = $this->params->get('merchant_id', '');
        if ($vars->merchant_id == null || $vars->merchant_id == ''){
            $link = JRoute::_(JURI::root(). "index.php?option=com_j2store" );
            $app->redirect($link, '<h2>لطفا تنظیمات درگاه آنلاین زیبال را بررسی کنید</h2>', $msgType='Error');
        }
        else{
            $Amount = round($vars->orderpayment_amount,0);
//            $Description = 'خرید محصول از فروشگاه   ';
//            $Email = '';
            $Mobile = '';
            $CallbackURL = JRoute::_(JURI::root(). "index.php?option=com_j2store&view=checkout" ) .'&orderpayment_id='.$vars->orderpayment_id . '&orderpayment_type=' . $vars->orderpayment_type .'&task=confirmPayment' ;



            $result = $this->postToZibal('request',
                [
                    'merchant' => $vars->merchant_id,
                    'amount' => $Amount,
//					'description' => $Description,
                    'orderId'=>$vars->orderpayment_id,
                    'mobile' => $Mobile,
                    'callbackUrl' => $CallbackURL,
                ]
            );

            $resultStatus = $result->result;
            if ($resultStatus == 100) {
                if ($this->params->get('zibaldirect', '') == 0){
                    $vars->zibal= 'https://gateway.zibal.ir/start/'.$result->trackId;
                }
                else {
                    $vars->zibal= 'https://gateway.zibal.ir/start/'.$result->trackId.'/direct';
                }
                $html = $this->_getLayout('prepayment', $vars);
                return $html;
                // Header('Location: https://sandbox.zibal.com/pg/StartPay/'.$result->Authority);

            } else {
                $link = JRoute::_( "index.php?option=com_j2store" );
                $app->redirect($link, '<h2>ERR: '. $result->message .'</h2>', $msgType='Error');
            }

        }
    }


    /**
     * connects to zibal's rest api
     * @param $path
     * @param $parameters
     * @return stdClass
     */
    function postToZibal($path, $parameters)
    {
        $url = 'https://gateway.zibal.ir/v1/'.$path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($parameters));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response  = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    function _postPayment($data) {
        $app = JFactory::getApplication();
        $jinput = $app->input;
        $html = '';
        $orderpayment_id = $jinput->get->get('orderpayment_id', '0', 'INT');
        F0FTable::addIncludePath ( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance ( 'Order', 'J2StoreTable' )->getClone ();
        //$this->getShippingAddress()->phone_2; //mobile
        //==========================================================================
        $Authority = $jinput->get->get('trackId', '0', 'INT');
        $status = $jinput->get->get('success', '', 'STRING');

        if ($orderpayment->load ($orderpayment_id)){
            $customer_note = $orderpayment->customer_note;
            if($orderpayment->j2store_order_id == $orderpayment_id) {
                if (checkHack::checkString($status)){
                    if ($status == '1') {
                            $result = $this->postToZibal('verify',
                                [
                                    'merchant' =>  $this->params->get('merchant_id', ''),
                                    'trackId' => $Authority,
                                ]
                            );
                            if ($result->result == 100 && round($orderpayment->order_total,0)==$result->amount) {
                                $msg= $this->getGateMsg("موفق");
                                $this->saveStatus($msg,1,$customer_note,'ok',$result->refNumber,$orderpayment);
                                $app->enqueueMessage($result->refNumber . ' شماره مرجع تراکنش شما', 'message');
                            }
                            else {
                                $msg= $this->getGateMsg($result->message);
                                $this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);// error
                                $link = JRoute::_( "index.php?option=com_j2store" );
                                $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
                            }

                    }
                    else {
                        $msg= $this->getGateMsg(intval(17));
                        $this->saveStatus($msg,4,$customer_note,'nonok',null,$orderpayment);
                        $link = JRoute::_( "index.php?option=com_j2store" );
                        $app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error');
                    }
                }
                else {
                    $msg= $this->getGateMsg('hck2');
                    $this->saveStatus($msg,3,$customer_note,'nonok',null,$orderpayment);
                    $link = JRoute::_( "index.php?option=com_j2store" );
                    $app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error');
                }
            }
            else {
                $msg= $this->getGateMsg('notff');
                $link = JRoute::_( "index.php?option=com_j2store" );
                $app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error');
            }
        }
        else {
            $msg= $this->getGateMsg('notff');
            $link = JRoute::_( "index.php?option=com_j2store" );
            $app->redirect($link, '<h2>'.$msg.'</h2>' , $msgType='Error');
        }
    }

    function _renderForm( $data )
    {
        $user = JFactory::getUser();
        $vars = new JObject();
        $vars->onselection_text = $this->params->get('onselection', '');
        $html = $this->_getLayout('form', $vars);
        return $html;
    }

    function getPaymentStatus($payment_status) {
        $status = '';
        switch($payment_status) {
            case '1': $status = JText::_('J2STORE_CONFIRMED'); break;
            case '2': $status = JText::_('J2STORE_PROCESSED'); break;
            case '3': $status = JText::_('J2STORE_FAILED'); break;
            case '4': $status = JText::_('J2STORE_PENDING'); break;
            case '5': $status = JText::_('J2STORE_INCOMPLETE'); break;
            default: $status = JText::_('J2STORE_PENDING'); break;
        }
        return $status;
    }

    function saveStatus($msg,$statCode,$customer_note,$emptyCart,$trackingCode,$orderpayment){
        $html ='<br />';
        $html .='<strong>'.'زیبال'.'</strong>';
        $html .='<br />';
        if (isset($trackingCode)){
            $html .= '<br />';
            $html .= $trackingCode .'شماره تراکنش ';
            $html .= '<br />';
        }
        $html .='<br />' . $msg;
        $orderpayment->customer_note =$customer_note.$html;
        $payment_status = $this->getPaymentStatus($statCode);
        $orderpayment->transaction_status = $payment_status;
        $orderpayment->order_state = $payment_status;
        $orderpayment->order_state_id = $this->params->get('payment_status', $statCode);

        if ($orderpayment->store()) {
            if ($emptyCart == 'ok'){
                $orderpayment->payment_complete ();
                $orderpayment->empty_cart();
            }
        }
        else
        {
            $errors[] = $orderpayment->getError();
        }

        $vars = new JObject();
        $vars->onafterpayment_text = $msg;
        $html = $this->_getLayout('postpayment', $vars);
        $html .= $this->_displayArticle();
        return $html;
    }

    function getGateMsg ($msgId) {
        switch($msgId){
            case	'error': $out ='خطا غیر منتظره رخ داده است';break;
            case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
            case	'notff': $out = 'سفارش پیدا نشد';break;
            default: $out =$msgId;break;
        }
        return $out;
    }
    function getShippingAddress() {

        $user =	JFactory::getUser();
        $db = JFactory::getDBO();

        $query = "SELECT * FROM #__j2store_addresses WHERE user_id={$user->id}";
        $db->setQuery($query);
        return $db->loadObject();

    }

}
