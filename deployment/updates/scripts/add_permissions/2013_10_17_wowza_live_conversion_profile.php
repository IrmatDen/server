<?php
/**
 * @package deployment
 * @subpackage falcon.roles_and_permissions
 * 
 * Add permissions to caption asset
 */

$script = realpath(dirname(__FILE__) . '/../../../../') . '/alpha/scripts/utils/permissions/addPermissionsAndItems.php';
$config = realpath(dirname(__FILE__)) . '/../../../permissions/service.wowza.liveconversionprofile.ini';
passthru("php $script $config");