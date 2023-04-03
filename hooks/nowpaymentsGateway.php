//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class nowpayments_hook_nowpaymentsGateway extends _HOOK_CLASS_
{
	/**
	 * Gateways
	 * @invisionbyte.ru
	 * @return	array
	 */
	static public function gateways()
	{
		try
		{
			try
			{
				$array = parent::gateways();
		        $array['Nowpayments'] = 'IPS\nowpayments\Nowpayments';
		      	return $array;
			}
			catch ( \RuntimeException $e )
			{
				if ( method_exists( get_parent_class(), __FUNCTION__ ) )
				{
					return \call_user_func_array( 'parent::' . __FUNCTION__, \func_get_args() );
				}
				else
				{
					throw $e;
				}
			}
		}
		catch ( \Error | \RuntimeException $e )
		{
			if ( method_exists( get_parent_class(), __FUNCTION__ ) )
			{
				return \call_user_func_array( 'parent::' . __FUNCTION__, \func_get_args() );
			}
			else
			{
				throw $e;
			}
		}
	}

}
