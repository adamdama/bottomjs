<?php
/**
 * @copyright	Copyright (C) 2012 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 * 
 * TODO combine inine scripts
 * TODO minify css
 * TODO ignore css already in top
 * TODO ignore empty css hrefs
 * TODO single jquery or moo tools
 * TODO no moo tools front end
 * TODO expose minify settings using fopen
 * TODO minify tags and external, or just tags
 * TODO script empty should check for whitespace
 * TODO make sure cross domain files are not minified
 * TODO get local absolute urls and make them rlative
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
	private $cssStartTag = '<link';
	private $cssEndTag = '/>';
	private $commentStartTag = '<!--';
	private $commentEndTag = '-->';
	// create array to contain scripts
	private $scripts = array();
	// create array to contain scripts
	private $css = array();
	// 
	private $preMinify = array();
	// create string to contain the original document
	private $doc = '';
	// create string to contain the original document
	private $newDoc = '';
	
	private $minifyURL = '/libraries/minify/?f=';
	// references for document and application
	private $application = null;
	private $document = null; 
	
	/**
	 * Constructs the plugin object
	 * 
	 * Link types are defined as constants
	 * References to application and document are stored
	 * 
	 * @constructor
	 * @param subject
	 * @param {array} config array of configuration variables
	 */
	function __construct(&$subject, $config = array())
	{
		define('TYPE_EXTERNAL', 2);
		define('TYPE_INTERNAL', 1);
		define('TYPE_INLINE', 0);
		
		$this->application =& JFactory::getApplication();
		$this->document =& JFactory::getDocument();
		
		parent::__construct($subject, $config);
	}
	
	/**
	 * Method to catch the onBeforeRender event.
	 *
	 * This is where we catch the document before it is output, strip the tags
	 * and then insert them at the specified point.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	function onBeforeRender()
	{	
		// quit if in application is admin
		if($this->application->isAdmin())
			return;
		
		// get the document		
		$params = array('template' => $this->application->getTemplate(), 'file' => 'index.php', 'directory' => JPATH_THEMES);
		$caching = ($this->application->getCfg('caching') >= 2) ? true : false;
		
		$this->doc = $this->document->render($caching, $params);
		
		// if the document is empty there is nothing to do here
		if($this->doc == '')
			return;
		
		// strip the document of tags
		if((int) $this->params->get('move_js') && $this->stripScripts())
		{
			if((int) $this->params->get('minify_js'))
				$this->minify('scripts');
			
			// insert the scripts at the specified position
			if(!$this->insert('scripts'))
				return;
		}
		
		// move css if set
		if((int) $this->params->get('move_css') && $this->stripCSS())
		{
			if((int) $this->params->get('minify_css'))
				$this->minify('css');
			
			// insert the scripts at the specified position
			if(!$this->insert('css', 'bh'))
				return;
		}
	}
	/**
	 * Method to catch the onAfterRender event.
	 *
	 * This is where we catch the document before it is output, strip the tags
	 * and then insert them at the specified point.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.5
	 */
	function onAfterRender()
	{
		// quit if in application is admin
		if($this->application->isAdmin())
			return;
		
		JResponse::setBody($this->newDoc);
	}

	/**
	 * Method to remove scripts from a document.
	 * 
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	private function stripScripts()
	{		
		$this->newDoc = '';
		
		// set the string offest
		$offset = 0;
		
		// flag for ignored scripts
		$addPrev = -1;
		
		// loop through instances of script tags in the document
		while($s = strpos($this->doc, $this->scriptStartTag, $offset))
		{
			// if a script was ignored add it to string
			if($addPrev != -1)			
				$this->newDoc .= substr($this->doc, $addPrev, $offset - $addPrev);
							
			// add the text before the script tag to the new document
			$this->newDoc .= substr($this->doc, $offset, $s - $offset);
			
			// set closing tag position TODO use getEndOfTag
			$e = $this->getEndOfTag($s, $this->doc, $this->scriptEndTag);
			//$e = strpos($this->doc, $this->scriptEndTag, $offset) + strlen($this->scriptEndTag);
			
			// if end tag is not found stop looping
			if($e === false)				
				break;
			
			if(((int) $this->params->get('ignore_empty') && $this->scriptEmpty($s, $e)) || $this->inIgnoreList($s) || $this->inComment($s, $e, $this->doc))
			{
				$addPrev = $s;
			}
			else
			{
				$string = substr($this->doc, $s, $e - $s);
				// set the scripts source type
				$type = ($this->scriptEmpty($s, $e, true) ? ($this->isExternal($string, 'script') ? TYPE_EXTERNAL : TYPE_INTERNAL) : TYPE_INLINE);
				
				// if type is external check that it is not local
				if($type == TYPE_EXTERNAL)
				{
					$string = $this->resolveLocalURL($string);
					$type = ($this->scriptEmpty($s, $e, true) ? ($this->isExternal($string, 'script') ? TYPE_EXTERNAL : TYPE_INTERNAL) : TYPE_INLINE);
				}
				
				if((int) $this->params->get('resolve_duplicates', 1))
				{
					//check to see if the script is already in the array
					$found = $this->resolveDuplicates($string);
					
					if(!$found)
						$this->scripts[] = array('string' => $string, 'type' => $type);
					
					$addPrev = -1;
				}
				else
				{
					// add the script to the array
					$this->scripts[] = array('string' => $string, 'type' => $type);
					$addPrev = -1;
				}				
			}
							
			// set $offset to script end point
			$offset = $e;
		}
		
		// if there was nothing to remove then we might not need to continue
		if(empty($this->scripts))
			return false;
		
		// if a script was ignored add it to string
		if($addPrev != -1)			
			$this->newDoc .= substr($this->doc, $addPrev, $offset - $addPrev);
		
		// add the rest of the document to the output string
		$this->newDoc .= substr($this->doc, $e);
		
		// set the doc
		$this->doc = $this->newDoc;
		
		$this->document->_scripts = array();
		
		return true;
	}
	
	/**
	 * Method to check if a script has already been found
	 * 
	 * @param {string} string the string to be checked against the stored scripts
	 */
	private function resolveDuplicates($string)
	{		
		// assume the script is not a duplicate until it is found
		$found = false;
		
		// if there are no scripts in the array the string passed cannot be a duplicate
		if(!count($this->scripts))
			return $found;
		
		// try an exact match first
		if($this->inScripts($string))
			$found = true;
		
		// check for same external scripts source address, tag attributes could be in a different order
		if(!$found)
		{
			$strSrc = $this->getHTMLAttribute('src', 0, $string);
			//if the string doesnt have a source we cant checck against it
			if($strSrc !== false)
			{						
				foreach($this->scripts as $script)
				{
					$src = $this->getHTMLAttribute('src', 0, $script['string']);
					
					if($src == $strSrc)
					{
						$found = true;
						break;
					}
				}
			}
		}
		
		return $found;
	}
	
	/**
	 * Method to resolve a local absolute URL into a relative URL
	 * 
	 * @param {string} string the script tag that needs to be checked for resolution
	 */
	 private function resolveLocalURL($string)
	 {
	 	// get the source attribute so we can check it
	 	$url = $this->getHTMLAttribute('src', 0, $string);
		
		// get the local domain and check for a direct match
		$uri = JURI::getInstance();
		$host = $uri->getScheme().'://'.$uri->getHost();
		
		// if the host matches the first part of the url then replace it with nothing and modify the string
		if(strpos($url, $host) === 0)
			$string = preg_replace('['.preg_quote($url).']', preg_replace('['.preg_quote($host).']', '', $url), $string);
		
	 	return $string;
	 }

	/**
	 * Method to catch the remove scripts from a document.
	 * 
	 * @return  boolean  True on success
	 *
	 * @since   2.5
	 */
	private function stripCSS()
	{
		$this->newDoc = '';
				
		// set the string offest
		$offset = 0;
		
		// flag for ignored css
		$addPrev = -1;
		
		// loop through instances of script tags in the document
		while($s = strpos($this->doc, $this->cssStartTag, $offset))
		{
			// if a css was ignored add it to string
			if($addPrev != -1)			
				$this->newDoc .= substr($this->doc, $addPrev, $offset - $addPrev);
							
			// add the text before the css tag to the new document
			$this->newDoc .= substr($this->doc, $offset, $s - $offset);
			
			// set closing tag position TODO use getEndOfTag
			$e = $this->getEndOfTag($s, $this->doc);
			
			// if end tag is not found stop looping
			if($e === false)				
				break;
			
			// add the css to the array
			if($this->getHTMLAttribute('rel', $s, $this->doc) == 'stylesheet' && !$this->inComment($s, $e, $this->doc))
			{
				$string = substr($this->doc, $s, $e - $s);
				$type = $this->isExternal($string, 'css') ? TYPE_EXTERNAL : TYPE_INTERNAL;
				
				$this->css[] = array('string' => $string, 'type' => $type);	
				$addPrev = -1;
			}
			elseif($this->inComment($s, $e, $this->doc))
			{
				$addPrev = $s;
			}
						
			// set $offset to css end point
			$offset = $e;
		}
		//	exit;
		
		// if there was nothing to remove then we might not need to continue
		if(empty($this->css))
			return false;
		
		// if a script was ignored add it to string
		if($addPrev != -1)			
			$this->newDoc .= substr($this->doc, $addPrev, $offset - $addPrev);
		
		// add the rest of the document to the output string
		$this->newDoc .= substr($this->doc, $e);
		
		// set the doc
		$this->doc = $this->newDoc;
		
		return true;
	}

	/**
	 * Method to catch the insert the scripts into a document.
	 * 
	 * @return  string  the processed document
	 *
	 * @since   2.5
	 */
	private function insert($assets, $insertAt=null)
	{
		// set the break point
		$break = $this->getInsertAt($insertAt);
				
		// if break point is less then 0 then something is wrong
		if($break < 0)
			return false;
		
		$getString = create_function('$value', 'return $value[\'string\'];');
		
		// split the string into its left and right components implode the scripts and add them to the left string set the new document to the left and right strings combined
		$this->newDoc = substr($this->newDoc, 0, $break) . implode("\r\n", array_map($getString, $this->$assets)) . substr($this->newDoc, $break);
		
		// set the doc
		$this->doc = $this->newDoc;
		
		return true;
	}
	
	private function getInsertAt($pos=null)
	{
		$order = $pos == null ? 1 : null;
		$pos = $pos == null ? (string) $this->params->get('insert_at') : $pos;
		
		//string to hold the translated parameter
		$where = '';
		
		switch($pos)
		{
			case 'bh':
				$o = strpos($this->newDoc, '<head');
				$where = substr($this->newDoc, $o, $this->getEndOfTag($o, $this->newDoc) - $o);
				break;
			case 'eh':
				$where = '</head>';
				break;
			case 'bb':
				$o = strpos($this->newDoc, '<body');
				$where = substr($this->newDoc, $o, $this->getEndOfTag($o, $this->newDoc) - $o);			
				break;
			case 'eb':
			default:				
				$where = '</body>';
				break;
		}
				
		// find the break point in the document
		$break = !$order || (int) $this->params->get('order') > 0 ? strpos($this->newDoc, $where) + strlen($where) : strpos($this->newDoc, $where);
		
		return $break;
	}
	
	private function scriptEmpty($start, $end, $ignoreSource = false)
	{
		if($end == $this->getEndOfTag($start, $this->doc) + strlen($this->scriptEndTag))
			return $ignoreSource ? true : $this->getHTMLAttribute('src', $start, $this->doc) == '';
			
		return false;
	}
	
	/*
	 * TODO Account for escaped ""
	 * TODO Account for attribute with ''
	 */
	private function getHTMLAttribute($attr, $start, $doc)
	{
		$attrPos = strpos($doc, $attr, $start);
		
		// check property exists
		if($attrPos === false || $attrPos > $this->getEndOfTag($start, $doc))
			return false;

		// get value position
		$valPos = strpos($doc, '="', $attrPos) + 2;
		
		// check for attribute with no =
		if($attrPos + strlen($attr) - $valPos > 1)
			return false;		
		
		//return the attributes value
		return substr($doc, $valPos, strpos($doc,'"', $valPos) - $valPos);
	}
	
	/*
	 * TODO Account for escaped >
	 */
	private function getEndOfTag($start, $doc, $endtag='>')
	{
		return (int) strpos($doc, $endtag, $start) + strlen($endtag);
	}
	
	private function inIgnoreList($s)
	{
		// set the ignore list
		$ignoreList = explode("\n", (string) $this->params->get('ignore_list'));
		
		// get the script src
		$src = $this->getHTMLAttribute('src',$s,$this->doc);
		
		// check for the src in the ignore list
		if($src !== false && in_array($src, $ignoreList))
			return true;
		
		return false;
	}
	
	private function inScripts($string)
	{
		$found = false;
		
		// loop over scripts to see if the string property of the element arrays matches the string passed
		foreach($this->scripts as $script)
		{
			if($script['string'] == $string)
			{
				$found = true;
				break;
			}
		}
		
		return $found;
	}
	
	private function minify($assets)
	{
		$list =& $this->$assets;
		
		$this->preMinify[$assets] = $list;
		
		$addScript = false;
		$url = JURI::base(true) . $this->minifyURL;
		
		foreach($list as $key => $string)
		{
			if($string['type'] == TYPE_INTERNAL)
			{
				$addScript = true;
				$url .= $this->getHTMLAttribute($assets == 'scripts' ? 'src' : 'href', 0, $string['string']).',';
				unset($list[$key]);
			}
		}
		
		if(!$addScript)
			return;
		
		$url = substr($url, 0, strlen($url) -1);
		
		$string = $assets == 'scripts' ? '<script type="text/javascript" src="'.$url.'" defer="defer"></script>' : '<link rel="stylesheet" type="text/css" href="'.$url.'" />';
			
		array_unshift($list, array('string' => $string, 'type' => TYPE_INTERNAL));
	}
	
	private function isExternal($string, $type)
	{
		$attr = $type == 'script' ? 'src' : 'href';
		
		$val = $this->getHTMLAttribute($attr, 0, $string);
		
		$prots = array('http','https','//');
		$external = false;
		
		foreach($prots as $prot)
		{
			if(strpos($val, $prot) === 0)
			{
				$external = true;
				break;
			}
		}
		
		return $external;
	}
	
	private function inComment($s, $e, $string)
	{
		$before = substr($string, 0, $s);
		$open = strrpos($before, $this->commentStartTag);
		
		if($open === false)
			return false;
		
		$close = strpos($before, $this->commentEndTag, $open);
		
		if($close !== false)
			return false;
		
		/*$after = substr($string, $e);		
		$close = strpos($after, $this->commentEndTag);
		
		if($close !== false)
			return false;
			
		$open = strrpos($after, $this->commentStartTag, $close);
		if($open !== false)
			return false;*/
		
		return true;
	}
}