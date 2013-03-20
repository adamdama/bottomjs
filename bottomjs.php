<?php
/**
 * @copyright	Copyright (C) 2012 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 * 
 * TODO ignore empty css hrefs
 * TODO single jquery or moo tools
 * TODO expose minify settings using fopen
 * TODO minify tags and external, or just tags
 * TODO script empty should check for whitespace
 * TODO make sure cross domain files are not minified
 * TODO minify script tag contents
 * TODO remove empty src and href attributes
 * TODO account for beginning and end tags in quotes
 * 
 * TODO use PHP xml parser to parse document - dont look up src so often
 * TODO change minifier to uglifier
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
	
	// backup of original document
	private $origDoc = null;
	
	// list of scripts to ignore
	private $ignoreList = null;
	
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
		
		// if the document is empty there is nothing to do here
		if(($docStr = $this->document->render($caching, $params)) === '')
			return;
		
		// take a backup of original document
		$this->origDoc = $docStr;
		
		// create the dom doc object
		$this->doc = new DOMDocument;
		$this->doc->preserveWhiteSpace = false;
		$this->doc->loadHTML($docStr);
		
		// strip the document of tags
		if((int) $this->params->get('move_js') && $this->stripScripts())
		{
			// if((int) $this->params->get('minify_js'))
				// $this->minify('scripts');
// 			
			// // insert the scripts at the specified position
			// if(!$this->insert('scripts'))
				// return;
		}
		/*
		// move css if set
		if((int) $this->params->get('move_css') && $this->stripCSS())
		{
			if((int) $this->params->get('minify_css'))
				$this->minify('css');
			
			// insert the scripts at the specified position
			if(!$this->insert('css', 'bh'))
				return;
		}*/
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
		
		JResponse::setBody($this->newDoc == '' ? $this->doc->saveHTML() : $this->newDoc);
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
		$scripts = $this->doc->getElementsByTagName('script');
		
		// convert the dom node list to an array
		$tmp = array();
		for($i = 0; $i < $scripts->length; $i++)
			$tmp[] = $scripts->item($i);
		$scripts = $tmp;
			
		// loop through instances of script tags in the document
		while($element = array_shift($scripts))
		{
			$attr = 'src';
			$src = $element->getAttribute($attr);
			//echo 'x';
			// set the scripts source type
			$type = ($this->scriptEmpty($element, true) ? ($this->isExternal($element, $attr) ? TYPE_EXTERNAL : TYPE_INTERNAL) : TYPE_INLINE);
			$empty = $this->scriptEmpty($element);
																
			if(((int) $this->params->get('ignore_empty') && $empty) || ($type != TYPE_INLINE && $this->inIgnoreList($src)))
			{
				continue;
			}
			else
			{				
				if((int) $this->params->get('resolve_duplicates', 1))
				{
					//check to see if the script is already in the array
					$found = $this->resolveDuplicates($src);
					
					if(!$found)
					{
						$this->scripts[] = array('element' => $element->cloneNode(true), 'type' => $type);
						$element->parentNode->removeChild($element);
					}					
				}
				else
				{
					// add the script to the array
					$this->scripts[] = array('element' => $element->cloneNode(true), 'type' => $type);
					$element->parentNode->removeChild($element);
				}				
			}
		}

		// if there was nothing to remove then we might not need to continue
		//if(empty($this->scripts))
			//return false;
		
		// remove all scripts from the document object
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
	 private function resolveLocalURL(DOMElement &$element, $attr)
	 {
	 	// get the source attribute so we can check it
	 	$url = $element->getAttribute($attr);
		$host = JURI::base();
		
		// if the host matches the first part of the url then replace it with nothing and modify the string
		if($resolved = (strpos($url, $host) === 0))
			$element->setAttribute($attr, preg_replace('['.preg_quote($host).']', JURI::base(true), $url));
		
		return $resolved;
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
				
				// if type is external check that it is not local
				if($type == TYPE_EXTERNAL)
				{
					$string = $this->resolveLocalURL($string, 'href');
					$type = $this->isExternal($string, 'css') ? TYPE_EXTERNAL : TYPE_INTERNAL;
				}
				
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
	 */
	private function insert($assets, $insertAt=null)
	{
		// set the break point
		$insertElement = $this->doc->getElementsByTagName($insertAt == null ? 'body' : $insertAt)->item(0);
		
		// setup variables for tracking output
		$middle = '';
		
		// loop assets and combine concurrent javascript tags
		foreach ($this->$assets as $asset)
		{
			$element = $asset['element'];
			
			if($assets == 'scripts')
			{
				if($asset['type'] == TYPE_INLINE)
				{
					$middle .= $element->nodeValue;
				}
				else
				{
					if($middle !== '')
					{
						$inline = new DOMElement;
						$inline->nodeValue = $middle;
						$this->doc->appendChild($inline);
					}
					
					if(!(int) $this->params->get('remove_mootools') || !$this->isMootools($element->getAttribute('src')))
						$this->doc->appendChild($element);
				}
			}
			else
			{
				//$this->doc->appendChild($element);
			}
		}
		
		//$this->newDoc = $this->doc->saveHTML();
		
		return true;
	}
	
	/**
	 * Returns true if a script tag has no content and the src attribute is blank
	 * The src attribute can be ignored by setting ignoreSource to true
	 * 
	 * @param DOMElement $element the element to check
	 * @param bool $ignoreSource[optional] if true the srce attribute will not be checked
	 * 
	 * @return bool
	 * 
	 * TODO check if the element is actually a script tag
	 */
	private function scriptEmpty(DOMElement $element, $ignoreSource = false)
	{
		if(preg_match('/^$|^\s+$/', $element->nodeValue))
			return $ignoreSource ? true : preg_match('/^$|^\s+$/', $element->getAttribute('src')) ? true : false;
			
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
	
	/**
	 * Checks script src attribute against ignore list
	 */
	private function inIgnoreList($src)
	{
		if($this->ignoreList === false)
			return false;
		
		//has the ignore list been created already
		if($this->ignoreList === null)
		{
			// set the ignore list
			$this->ignoreList = explode(",", (string) preg_replace('/\s/', '', $this->params->get('ignore_list')));
			if($this->ignoreList[0] === '')
			{
				$this->ignoreList = false;
				return false;
			}
		}
		
		$found = false;
		foreach ($this->ignoreList as $ignore)
		{
			if($src == $ignore)
			{
				$found = true;
				break;
			}
		}
		
		// check for the src in the ignore list
		return $found;
	}
	
	/**
	 * Checks to see if a script src has already been added to the scripts array
	 * Used for resolving duplicates
	 */
	private function inScripts($src)
	{
		$found = false;
		
		// loop over scripts to see if the string property of the element arrays matches the string passed
		foreach($this->scripts as $script)
		{
			if($script['element']->getAttribute('src') == $src)
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
		
		$script = '';
		
		// position at which to insert the minified script
		$insertAt = 0;
		
		foreach($list as $key => $string)
		{
			switch($string['type'])
			{
				case TYPE_INTERNAL:
					$addScript = true;
					$url .= $this->getHTMLAttribute($assets == 'scripts' ? 'src' : 'href', 0, $string['string']).',';
					
					if($insertAt == 0)
						$insertAt = $key;
					
					unset($list[$key]);
				default:
					break;
			}
		}
		
		if(!$addScript)
			return;
		
		$url = substr($url, 0, strlen($url) -1);
		
		$string = $assets == 'scripts' ? '<script type="text/javascript" src="'.$url.'"></script>' : '<link rel="stylesheet" type="text/css" href="'.$url.'" />';
		
		// inserts the minified at the top of the document
		//array_unshift($list, array('string' => $string, 'type' => TYPE_INTERNAL));
		
		// store the index of the last external script so we can insert after it
		// $lind = 0;
// 		
		// // what we need to do is insert the scripts after the last external script
		// foreach($list as $key => $item)
		// {
			// if($item['type'] == TYPE_EXTERNAL)
			// {
				// $lind = $key;
			// }
		// }

		
		// insert the minify source into the scripts array
		$list = $this->array_insert_at($insertAt, array('string' => $string, 'type' => TYPE_INTERNAL), $list);
	}
	
	/**
	 * Checks an element to see if its src/href is external or not
	 */
	private function isExternal(DOMElement $element, $attr)
	{	
		$src = $element->getAttribute($attr);
		
		$prots = array('http','https','//');
		$external = false;
		
		foreach($prots as $prot)
		{
			if(strpos($src, $prot) === 0)
			{
				$external = true;
				break;
			}
		}
		
		//TODO set as an option
		// resolve local absolute urls into relative urls
		if($external)
			$external = $this->resolveLocalURL($element, $attr);
		
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
	/**
	 * Checks a script source for evidence of mootools
	 */
	private function isMootools($src)
	{		
		return strpos($src, '/mootools') !== false;			
	}
	
	private function array_insert_at($index, $insert, $array)
	{
		return array_merge(array_slice($array, 0, $index), array($insert), array_slice($array, $index));
	}
}