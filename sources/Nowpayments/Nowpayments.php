<?php
namespace IPS\nowpayments;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * NowPayments Gateway
 */
class _Nowpayments extends \IPS\nexus\Gateway
{
	/* !Features (Each gateway will override) */

	const SUPPORTS_REFUNDS = FALSE;
	const SUPPORTS_PARTIAL_REFUNDS = FALSE;
		
	/* !Payment Gateway */
	
	/**
	 * Authorize
	 *
	 * @param	\IPS\nexus\Transaction					$transaction	Transaction
	 * @param	array|\IPS\nexus\Customer\CreditCard	$values			Values from form OR a stored card object if this gateway supports them
	 * @param	\IPS\nexus\Fraud\MaxMind\Request|NULL	$maxMind		*If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made	
	 * @return	\IPS\DateTime|NULL		Auth is valid until or NULL to indicate auth is good forever
	 * @throws	\LogicException			Message will be displayed to user
	 */
	public function auth( \IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL, $recurrings = array(), $source = NULL )
	{
		$settings = json_decode( $this->settings, TRUE );
		$transaction->save();

		$transactionAmount = $transaction->amount->amountAsString();
		$hash = mb_strtoupper( md5( $settings['apikey'] . $settings['secret'] . ( \IPS\NEXUS_TEST_GATEWAYS ? 1 : $transaction->id ) . number_format( $transactionAmount, 2 ) ) );

		\IPS\Output::i()->redirect( \IPS\Settings::i()->base_url . "applications/nowpayments/interface/nowpayments.php?nexustransactionid={$transaction->id}&key={$hash}" );
		
	}
	
	/* !ACP Configuration */
	
	/**
	 * Settings
	 *
	 * @param	\IPS\Helpers\Form	$form	The form
	 * @return	void
	 */
	public function settings( &$form )
	{
		$settings = json_decode( $this->settings, TRUE );
		
		$form->add( new \IPS\Helpers\Form\Text( 'nowpayments_apikey', $settings['apikey'], TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'nowpayments_secret', $settings['secret'], TRUE ) ); // NtHgtp/GZjcnfou812BfFXkoX+zvfad3
		$form->add( new \IPS\Helpers\Form\Text( 'nowpayments_debug_email', $settings['debug_email'], TRUE ) );
	}
	
	/**
	 * Test Settings
	 *
	 * @return	void
	 * @throws	\InvalidArgumentException
	 */
	public function testSettings( $settings ) {
		return $settings;
	}
}