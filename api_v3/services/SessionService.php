<?php

/**
 * Session service
 *
 * @service session
 * @package api
 * @subpackage services
 */
class SessionService extends KalturaBaseService
{
    
	
	protected function partnerRequired($actionName)
	{
		if ($actionName === 'startWidgetSession') {
			return false;
		}
		return parent::partnerRequired($actionName);
	}
	
	
	/**
	 * Start a session with Kaltura's server.
	 * The result KS is the session key that you should pass to all services that requires a ticket.
	 * 
	 * @action start
	 * @param string $secret Remember to provide the correct secret according to the sessionType you want
	 * @param string $userId
	 * @param KalturaSessionType $type Regular session or Admin session
	 * @param int $partnerId
	 * @param int $expiry KS expiry time in seconds
	 * @param string $privileges 
	 * @return string
	 *
	 * @throws APIErrors::START_SESSION_ERROR
	 */
	function startAction($secret, $userId = "", $type = 0, $partnerId = null, $expiry = 86400 , $privileges = null )
	{
		KalturaResponseCacher::disableCache();
		// make sure the secret fits the one in the partner's table
		$ks = "";
		$result = kSessionUtils::startKSession ( $partnerId , $secret , $userId , $ks , $expiry , $type , "" , $privileges );

		if ( $result >= 0 )
		{
			return $ks;
		}
		else
		{
			throw new KalturaAPIException ( APIErrors::START_SESSION_ERROR ,$partnerId );
		}
	}
	
	
	/**
	 * End a session with the Kaltura server, making the current KS invalid.
	 * 
	 * @action end
	 */
	function endAction()
	{
		KalturaResponseCacher::disableCache();
		
		$ks = $this->getKs();
		if($ks)
			$ks->kill();
	}

	/**
	 * Start an impersonated session with Kaltura's server.
	 * The result KS is the session key that you should pass to all services that requires a ticket.
	 * 
	 * @action impersonate
	 * @param string $secret - should be the secret (admin or user) of the original partnerId (not impersonatedPartnerId).
	 * @param int $impersonatedPartnerId
	 * @param string $userId - impersonated userId
	 * @param KalturaSessionType $type
	 * @param int $partnerId
	 * @param int $expiry KS expiry time in seconds
	 * @param string $privileges 
	 * @return string
	 *
	 * @throws APIErrors::START_SESSION_ERROR
	 */
	function impersonateAction($secret, $impersonatedPartnerId, $userId = "", $type = KalturaSessionType::USER, $partnerId = null, $expiry = 86400 , $privileges = null )
	{
		KalturaResponseCacher::disableCache();
		
		// verify that partnerId exists and is in correspondence with given secret
		$result = myPartnerUtils::isValidSecret($partnerId, $secret, "", $expiry, $type);
		if ($result !== true)
		{
			throw new KalturaAPIException ( APIErrors::START_SESSION_ERROR, $partnerId );
		}
				
		// verify partner is allowed to start session for another partner
		$impersonatedPartner = null;
		if (!myPartnerUtils::allowPartnerAccessPartner($partnerId, $this->partnerGroup(), $impersonatedPartnerId))
		{
		    $c = PartnerPeer::getDefaultCriteria();
		    $c->addAnd(PartnerPeer::ID, $impersonatedPartnerId);
		    $impersonatedPartner = PartnerPeer::doSelectOne($c);
		}
		else 
		{
    		// get impersonated partner
    		$impersonatedPartner = PartnerPeer::retrieveByPK($impersonatedPartnerId);
		}
		
		if(!$impersonatedPartner)
		{
			// impersonated partner could not be fetched from the DB
			throw new KalturaAPIException ( APIErrors::START_SESSION_ERROR ,$partnerId );
		}
		
		// set the correct secret according to required session type
		if($type == KalturaSessionType::ADMIN)
		{
			$impersonatedSecret = $impersonatedPartner->getAdminSecret();
		}
		else
		{
			$impersonatedSecret = $impersonatedPartner->getSecret();
		}
		
		// make sure the secret fits the one in the partner's table
		$ks = "";
		$result = kSessionUtils::startKSession ( $impersonatedPartner->getId() , $impersonatedSecret, $userId , $ks , $expiry , $type , "" , $privileges, $partnerId );

		if ( $result >= 0 )
		{
			return $ks;
		}
		else
		{
			throw new KalturaAPIException ( APIErrors::START_SESSION_ERROR ,$partnerId );
		}
	}

	/**
	 * Start an impersonated session with Kaltura's server.
	 * The result KS info contains the session key that you should pass to all services that requires a ticket.
	 * Type, expiry and privileges won't be changed if they're not set
	 * 
	 * @action impersonateByKs
	 * @param string $session The old KS of the impersonated partner
	 * @param KalturaSessionType $type Type of the new KS 
	 * @param int $expiry Expiry time in seconds of the new KS
	 * @param string $privileges Privileges of the new KS
	 * @return KalturaSessionInfo
	 *
	 * @throws APIErrors::START_SESSION_ERROR
	 */
	function impersonateByKsAction($session, $type = null, $expiry = null , $privileges = null)
	{
		KalturaResponseCacher::disableCache();
		
		$oldKS = null;
		try
		{
			$oldKS = ks::fromSecureString($session);
		}
		catch(Exception $e)
		{
			KalturaLog::err($e->getMessage());
			throw new KalturaAPIException(APIErrors::START_SESSION_ERROR, $this->getPartnerId());
		}
		$impersonatedPartnerId = $oldKS->partner_id;
		$impersonatedUserId = $oldKS->user;
		$impersonatedType = $oldKS->type; 
		$impersonatedExpiry = $oldKS->valid_until - time(); 
		$impersonatedPrivileges = $oldKS->privileges;
		
		if(!is_null($type))
			$impersonatedType = $type;
		if(!is_null($expiry)) 
			$impersonatedExpiry = $expiry;
		if($privileges) 
			$impersonatedPrivileges = $privileges;
		
		// verify partner is allowed to start session for another partner
		$impersonatedPartner = null;
		if(!myPartnerUtils::allowPartnerAccessPartner($this->getPartnerId(), $this->partnerGroup(), $impersonatedPartnerId))
		{
			$c = PartnerPeer::getDefaultCriteria();
			$c->addAnd(PartnerPeer::ID, $impersonatedPartnerId);
			$impersonatedPartner = PartnerPeer::doSelectOne($c);
		}
		else
		{
			// get impersonated partner
			$impersonatedPartner = PartnerPeer::retrieveByPK($impersonatedPartnerId);
		}
		
		if(!$impersonatedPartner)
		{
			KalturaLog::err("Impersonated partner [$impersonatedPartnerId ]could not be fetched from the DB");
			throw new KalturaAPIException(APIErrors::START_SESSION_ERROR, $this->getPartnerId());
		}
		
		// set the correct secret according to required session type
		if($impersonatedType == KalturaSessionType::ADMIN)
		{
			$impersonatedSecret = $impersonatedPartner->getAdminSecret();
		}
		else
		{
			$impersonatedSecret = $impersonatedPartner->getSecret();
		}
		
		$sessionInfo = new KalturaSessionInfo();
		
		$result = kSessionUtils::startKSession($impersonatedPartnerId, $impersonatedSecret, $impersonatedUserId, $sessionInfo->ks, $impersonatedExpiry, $impersonatedType, '', $impersonatedPrivileges, $this->getPartnerId());
		if($result < 0)
		{
			KalturaLog::err("Failed starting a session with result [$result]");
			throw new KalturaAPIException(APIErrors::START_SESSION_ERROR, $this->getPartnerId());
		}
	
		// getting the kuser from the db
		$c = KalturaCriteria::create(kuserPeer::OM_CLASS);
		$c->add(kuserPeer::PARTNER_ID, $impersonatedPartnerId);
		$c->add(kuserPeer::PUSER_ID, $impersonatedUserId);
		$c->add(kuserPeer::STATUS, KuserStatus::DELETED, KalturaCriteria::NOT_EQUAL);
		
		kuserPeer::setUseCriteriaFilter(false);
		$kuser = kuserPeer::doSelectOne($c);
		kuserPeer::setUseCriteriaFilter(true);
		
		// assign the kuser into KalturaUser object
		$user = new KalturaUser();
		if($kuser)
		{
			$user->fromObject($kuser);
		}
		else 
		{
			$user->id =  $impersonatedUserId;
			$user->partnerId = $impersonatedPartnerId;
			$user->screenName =  $impersonatedUserId;
			$user->isAdmin = ($impersonatedType == KalturaSessionType::ADMIN);
		}
		
		$sessionInfo->partnerId = $impersonatedPartnerId;
		$sessionInfo->user = $user;
		$sessionInfo->expiry = $impersonatedExpiry;
		$sessionInfo->sessionType = $impersonatedType;
		$sessionInfo->privileges = $impersonatedPrivileges;
		
		return $sessionInfo;
	}
	
	/**
	 * Start a session for Kaltura's flash widgets
	 * 
	 * @action startWidgetSession
	 * @param string $widgetId
	 * @param int $expiry
	 * 
	 * @throws APIErrors::INVALID_WIDGET_ID
	 * @throws APIErrors::MISSING_KS
	 * @throws APIErrors::INVALID_KS
	 * @throws APIErrors::START_WIDGET_SESSION_ERROR
	 * @return KalturaStartWidgetSessionResponse
	 */	
	function startWidgetSession ( $widgetId , $expiry = 86400 )
	{
		// make sure the secret fits the one in the partner's table
		$ksStr = "";
		
		$widget = widgetPeer::retrieveByPK( $widgetId );
		if ( !$widget )
		{
			throw new KalturaAPIException ( APIErrors::INVALID_WIDGET_ID , $widgetId );
		}

		$partnerId = $widget->getPartnerId();

		//$partner = PartnerPeer::retrieveByPK( $partner_id );
		// TODO - see how to decide if the partner has a URL to redirect to


		// according to the partner's policy and the widget's policy - define the privileges of the ks
		// TODO - decide !! - for now only view - any kshow
		$privileges = "view:*,widget:1";
		
		if(PermissionPeer::isValidForPartner(PermissionName::FEATURE_ENTITLEMENT, $partnerId) &&
			!$widget->getEnforceEntitlement() && $widget->getEntryId())
			$privileges .= ','. kSessionBase::PRIVILEGE_DISABLE_ENTITLEMENT_FOR_ENTRY . ':' . $widget->getEntryId();
			
		if(PermissionPeer::isValidForPartner(PermissionName::FEATURE_ENTITLEMENT, $partnerId) &&
			!is_null($widget->getPrivacyContext()) && $widget->getPrivacyContext() != '' )
			$privileges .= ','. kSessionBase::PRIVILEGE_PRIVACY_CONTEXT . ':' . $widget->getPrivacyContext();
		
		$userId = 0;
		/*if ( $widget->getSecurityType() == widget::WIDGET_SECURITY_TYPE_FORCE_KS )
		{
			$user = $this->getKuser();
			if ( ! $this->getKS() )// the one from the base class
				throw new KalturaAPIException ( APIErrors::MISSING_KS );

			$widget_partner_id = $widget->getPartnerId();
			$res = kSessionUtils::validateKSession2 ( 1 ,$widget_partner_id  , $user->getId() , $ks_str , $this->ks );
			
			if ( 0 >= $res )
			{
				// chaned this to be an exception rather than an error
				throw new KalturaAPIException ( APIErrors::INVALID_KS , $ks_str , $res , ks::getErrorStr( $res ));
			}			
		}
		else
		{*/
			// 	the session will be for NON admins and privileges of view only
			$result = kSessionUtils::createKSessionNoValidations ( $partnerId , $userId , $ksStr , $expiry , false , "" , $privileges );
		//}

		if ( $result >= 0 )
		{
			$response = new KalturaStartWidgetSessionResponse();
			$response->partnerId = $partnerId;
			$response->ks = $ksStr;
			$response->userId = $userId;
			return $response;
		}
		else
		{
			// TODO - see that there is a good error for when the invalid login count exceed s the max
			throw new  KalturaAPIException  ( APIErrors::START_WIDGET_SESSION_ERROR ,$widgetId );
		}		
	}
}