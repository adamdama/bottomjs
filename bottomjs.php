<?php
/**
 * @copyright	Copyright (C) 2012 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Joomla! System JS Load Manipulation Plugin
 *
 * @package		Joomla.Plugin
 * @subpackage	System.bottomjs
 */
class  plgSystemBottomjs extends JPlugin
{
	private $scriptStartTag = '<script';
	private $scriptEndTag = '</script>';
	
	function onAfterRender()
	{
		$app = JFactory::getApplication();
		// quit if in application is admin
		if($app->isAdmin())
			return;
		
		// get the document
		$doc = JResponse::getBody();
		
		// set the string offest
		$offset = 0;
		
		// create array to contain scripts
		$scripts = array();
		
		// loop through instances of script tags in the document
		while($s = strpos($doc, $this->scriptStartTag, $offset))
		{			
			// set closing tag position
			$e = strpos($doc, $this->scriptEndTag, $offset) + strlen($this->scriptEndTag);
			
			// if end tag is not found stop looping
			if($e === false)
				break;

			// add the script to the array
			$scripts[] = substr($doc, $s, $e - $s);
			
			// set $offset to script end point
			$offset = $e;
		}
		
		echo '<pre>',print_r($scripts),'</pre>';exit;
		
		// return the new document
		JResponse::setBody($doc);
	}
}

ini_set('display_errors', 1);