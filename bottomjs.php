<?php
/**
 * @copyright	Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Joomla! System Logging Plugin
 *
 * @package		Joomla.Plugin
 * @subpackage	System.log
 */
class  plgSystemBottomjs extends JPlugin
{
	function onAfterRender()
	{
		$app = JFactory::getApplication();
		
		if($app->isAdmin())
			return;
		
		// Get the document
		$doc = JResponse::getBody();
		
		
		
		// Return the new document
		JResponse::setBody($doc);
	}
}
