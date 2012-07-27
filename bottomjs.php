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
	// create array to contain scripts
	private $scripts = array();
	 
	/**
	 * Method to catch the onAfterRender event.
	 *
	 * This is where we catch the document before it is output, strip the tags
	 * and then insert them at the specified point.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
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
		
		// string to store the document stripped of tags
		$newDoc = '';
		
		// loop through instances of script tags in the document
		while($s = strpos($doc, $this->scriptStartTag, $offset))
		{				
			// add the text before the script tag to the new document
			$newDoc .= substr($doc, $offset, $s - $offset);
			
			// set closing tag position
			$e = strpos($doc, $this->scriptEndTag, $offset) + strlen($this->scriptEndTag);
			
			// if end tag is not found stop looping
			if($e === false)				
				break;
			
			// add the script to the array
			$this->scripts[] = substr($doc, $s, $e - $s);
			
			// set $offset to script end point
			$offset = $e;
		}
		
		// add the rest of the document to the output string
		$newDoc .= substr($doc, $e);
		
		// insert the scripts at the specified position
		$newDoc = $this->insertScripts('</body>', $newDoc, $this->scripts);
		
		//echo '<pre>',print_r($newDoc),'</pre>';exit;
		
		// set the new document
		JResponse::setBody($newDoc);
		
		return true;
	}

	/**
	 * Method to catch the insert the scripts into a document.
	 *
	 * @param	string	$where the string at which to insert the scripts
	 * @param	string	$doc the string to insert the scripts into
	 * @param	string	$scripts the scripts to insert
	 * 
	 * @return  string  the processed document
	 *
	 * @since   2.5
	 */
	private function insertScripts($where, $doc='', $scripts=array())
	{
		// if the document or scripts are empty we can't do anything here
		if($doc == '' || empty($scripts))
			return $doc;
		
		// find the break point in the document
		$break = strpos($doc, $where);
		
		// split the string into its left and right components
		$l = substr($doc, 0, $break-1);
		$r = substr($doc, $break);
		
		// loop the scripts and add them to the left string
		foreach($scripts as $s)
			$l .= $s."\r\n";
		
		// return the left and right strings combined
		return $l.$r;
	}
}