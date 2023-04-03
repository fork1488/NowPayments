<?php
require_once '../../../init.php';

if (\IPS\Request::i()->nexustransactionid)
{
    try
    {
        $transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->nexustransactionid);

        if ($transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING)
        {
            throw new \OutofRangeException;
        }
    }
    catch(\OutOfRangeException $e)
    {
        \IPS\Output::i()->redirect(\IPS\Http\Url::internal("app=nexus&module=payments&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->nexustransactionid, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https));
    }

    $settings = json_decode($transaction->method->settings, true);
    $hash = mb_strtoupper(md5($settings['apikey'] . $settings['secret'] . (\IPS\NEXUS_TEST_GATEWAYS ? 1 : $transaction->id) . number_format($transaction->amount->amountAsString() , 2)));

    if ($hash !== \IPS\Request::i()->key)
    {
        \IPS\Output::i()->redirect($transaction->invoice->checkoutUrl())->setQueryString( array( '_step' => 'checkout_pay', 'err' => $transaction->member->language()->addToStack('gateway_err') ) );
    }

    $settings = json_decode($transaction->method->settings, true);

    try
    {
        $postfields = array(
            'price_amount' => (float)(string)$transaction->amount->amount,
            'price_currency' => $transaction->amount->currency,
            'order_id' => $transaction->id,
			'order_description' => 'Invoice: ' . $transaction->invoice->id,
            'success_url' => (string)$transaction->url() ,
            'cancel_url' => (string)$transaction->invoice->checkoutUrl()
        );

        $payload = json_encode($postfields, JSON_UNESCAPED_SLASHES);

        $response = \IPS\Http\Url::external('https://api.nowpayments.io/v1/invoice')
			->request()
			->setHeaders(array('x-api-key' => $settings['apikey'],'Content-type' => 'application/json'))
			->post($payload)
			->decodeJson();

        if ($response['id']) 
			\IPS\Output::i()->redirect($response['invoice_url']);
        else 
			\IPS\Output::i()->sendOutput('NowPayments error due create invoice', 500);
    }
    catch(\IPS\Http\Request\Exception $e)
    {
        \IPS\Output::i()->sendOutput('NowPayments does not respond', 500);
    }
    catch(\RuntimeException $e)
    {
        \IPS\Output::i()->sendOutput('NowPayments return shit', 500);
    }
}
elseif (\IPS\Request::i()->notify)
{
    try
    {
        $error_msg = "Unknown error";
        $auth_ok = false;
        $request_data = null;
        if (isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) && !empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG']))
        {
            $recived_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];
            $request_json = file_get_contents('php://input');
            $request_data = json_decode($request_json, true);
            ksort($request_data);
            $sorted_request_json = json_encode($request_data, JSON_UNESCAPED_SLASHES);
            if ($request_json !== false && !empty($request_json))
            {
				$decodedBody = $request_data;
				
				$transaction = \IPS\nexus\Transaction::load($decodedBody['order_id']);
				$settings = json_decode($transaction->method->settings, true);
				
				$debug_email = $settings['debug_email'];
				
                $hmac = hash_hmac("sha512", $sorted_request_json, trim($settings['secret']));
				
                if ($hmac == $recived_hmac)
                {
                    $auth_ok = true;
					
					$transactionId = $decodedBody['payment_id'];
                    $status = $decodedBody['payment_status'];
                    $paymentAmount = $decodedBody['price_amount'];
					$paymentCurrency = $decodedBody['price_currency'];

                    $order_currency = $transaction->currency;
                    $order_total = (float)(string)$transaction->amount->amount;

                    if ($status == 'confirmed')
                    {
						// Check the original currency to make sure the buyer didn't change it.
						if ( mb_strtoupper($paymentCurrency) != mb_strtoupper($order_currency) ) {
							errorAndDie("Currency does not match order currency.");
						}
						
						 // Check amount against order total
						if ($paymentAmount < $order_total)
						{
							errorAndDie("Payment less than order total");
						}
						
						if ($transaction->status === \IPS\nexus\Transaction::STATUS_HELD) // not tested
						{
							errorAndDie("Payment can`t confirmed because its held");
						}
						
                        // payment is complete
                        $transaction->gw_id = $transactionId;
                        $transaction->auth = NULL;
                        $transaction->approve(NULL);
                        $transaction->save();
                        $transaction->sendNotification();

                    }
                    else if ($status == 'failed'/* || $status == 'expired'*/) // if expired its flood client email?
                    {
                        //payment error
                        if ($transaction->status !== \IPS\nexus\Transaction::STATUS_REFUSED)
                        {
							$transaction->gw_id = $transactionId;
                            $transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
                            $transaction->save();
                            $transaction->sendNotification();
                        }
                    }
					else if ($status == 'refunded')
                    {
                        //payment error
                        if ($transaction->status !== \IPS\nexus\Transaction::STATUS_REFUNDED)
                        {
							$transaction->gw_id = $transactionId;
                            $transaction->status = \IPS\nexus\Transaction::STATUS_REFUNDED;
                            $transaction->save();
                            $transaction->sendNotification();
                        }
                    }
                    else if ($status == 'partially_paid')
                    {
                        //payment is pending
                        if ($transaction->status !== \IPS\nexus\Transaction::STATUS_HELD)
                        {
                            $transaction->gw_id = $transactionId;
                            $transaction->status = \IPS\nexus\Transaction::STATUS_HELD;
                            $transaction->save();
                            $transaction->sendNotification();
                        }
						
						errorAndDie('partially_paid');
                    }
					else if ($status == 'confirming')
					{
						//payment is pending
						if ($transaction->status !== \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING)
						{
							$transaction->gw_id = $transactionId;
							$transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
							$transaction->save();
							$transaction->sendNotification();
						}
					}
					else if ($status == 'waiting')
					{
						//payment is new
						if ($transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING)
						{
							$transaction->gw_id = $transactionId;
							$transaction->status = \IPS\nexus\Transaction::STATUS_PENDING;
							$transaction->save();
							$transaction->sendNotification();
						}
					}
					
					die('OK');
				}
				else
				{
					$error_msg = 'HMAC signature does not match';
				}
			}
			else
			{
				$error_msg = 'Error reading POST data';
			}
		}
		else
		{
			$error_msg = 'No HMAC signature sent.';
		}
		
		errorAndDie($error_msg);
	}
	catch(\OutOfRangeException $e)
	{
		errorAndDie('OutOfRangeException');
	}
}
else
	die('Unknown action');

function errorAndDie($error_msg)
{
    global $debug_email;
    global $decodedBody;
    if (!empty($debug_email))
    {
        $report = 'Error: ' . $error_msg . "\n\n";
        $report .= "POST Data\n\n";
        $report .= json_encode($decodedBody, JSON_PRETTY_PRINT);
        $email = \IPS\Email::buildFromContent("NowPayments Commerce IPN Error", $report);
        $email->send($debug_email);
    }
    die( 'IPN Error: '.$error_msg );
}
?>
