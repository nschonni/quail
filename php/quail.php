<?php

//namespace Quail;

//require_once __DIR__ . '/phpquery/phpQuery/phpQuery.php';
require_once __DIR__ . '/querypath/src/qp.php';
require_once 'quailTests.php';

class Quail {

  /**
   * An array of test object names that should be run against this content.
   */
  protected $guideline = array();

  /**
   *
   */
  protected $html_config = array('valid_xhtml' => 1,
                                 'schemes' => '*:*' );

  /**
   * An array of quail tests, usually loaded fromt he test.json file.
   */
  public $quail_tests;

  /**
   * The charset to use for the current document.
   */
  public $charset = 'utf-8';

  /**
   * The actual HTML document to check
   */
  protected $html;

  /**
   * An array of test objects
   */
  protected $tests;

  /**
   * An array of result objects
   */
  protected $results;

  /**
   * The current PHPQuery document object
   */
  protected $qp;

  /**
   * A string of CSS selectors
   */
  protected $CSSString;


  protected $attributeCssMapping = array(
    'bgcolor' => 'background-color',
    'text' => 'color',
    'width' => 'width',
    'height' => 'height'
  );
  /**
   * Class constructor.
   * @param string $contents The HTML contents to check for accessibility
   * @param mixed $guideline Either an array of test names, or a string indicating a guideline file
   *   should be loaded dynamically.
   */
  public function __construct($contents, $path = '', $guideline = 'test', $charset = 'utf-8', $section = FALSE) {
    if(!is_array($guideline)) {
      $guideline = json_decode(file_get_contents('../resources/guidelines/'. $guideline .'.json'));
      if(!$guideline) {
        throw new QuailConfigurationException('Guideline JSON file does not exist or is invalid.');
      }
    }
    $this->path = parse_url($path);
    $this->html = $contents;
    $this->guideline = $guideline;
    $this->charset = $charset;
    $contents = preg_replace_callback('/<([^ >]*)/', 'quail::lowercaseTags', $contents);
    if($section) {
      $this->qp = htmlqp($contents);
    }
    else {
      $this->qp = htmlqp($contents, $section);
    }
    $this->addCSS();
  }

  public function addCSS() {
    require_once __DIR__ .'/CSSParser/CSSParser.php';
    $that = $this;
    $this->cssString = file_get_contents(__DIR__ . '/default.css');
    return;
    $this->qp->find('style')->each(function() use ($that) {
      $this->cssString .= qp($style)->text();
    });
    $CssParser = new CSSParser($this->cssString);
    $CssDocument = $CssParser->parse();
    foreach($CssDocument->getAllRuleSets() as $ruleset) {
      foreach($ruleset->getSelector() as $selector) {
        $specificity = $selector->getSpecificity();
        foreach($this->qp->find($selector->getSelector()) as $el) {
          $existing = $el->data('_css');
          $ruleset->expandShorthands();
          foreach($ruleset->getRules() as $rule => $value) {
            if(!isset($existing[$rule]) || $existing[$rule]['specificity'] <= $specificity) {
              $value = $value->getValue();
              $value = (is_object($value))
                        ? $value->__toString()
                        : $value;
              $existing[$rule] = array('specificity' => $specificity,
                                       'value' => $value);
            }
          }
          qp($el)->data('_css', $existing);
          $this->bubbleCSS(qp($el));
        }
      }
    }
    foreach($this->qp->find('*') as $el) {
      $existing = qp($el)->data('_css');
      $style =  qp($el)->attr('style');
      $style = strlen($style) ? explode(';', $style) : array();
      foreach($this->attributeCssMapping as $map => $css_equivalent) {
        if(qp($el)->attr($map)) {
          $style[] = $css_equivalent .':'. pq($el)->attr($map);
        }
      }
      if(count($style)) {
        $CssParser = new CSSParser('#ruleset {'. implode(';', $style) .'}');
        $CssDocument = $CssParser->parse();
        $ruleset = $CssDocument->getAllRulesets();
        $ruleset = reset($ruleset);
        $ruleset->expandShorthands();
        foreach($ruleset->getRules() as $rule => $value) {
          if(!isset($existing[$rule]) || 1000 >= $existing[$rule]['specificity']) {
            $value = $value->getValue();
            $value = (is_object($value))
                      ? $value->__toString()
                      : $value;
            $existing[$rule] = array('specificity' => 1000,
                                     'value' => $value);
          }
        }
        phpQuery::pq($el)->data('_css', $existing);
        $this->bubbleCSS(qp($el));
      }
    }
  }

  protected function bubbleCSS($element) {
    $style = $element->data('_css');
    foreach($element->children() as $element_child) {
      $existing = qp($element_child)->data('_css');
      foreach($style as $rule => $value) {
        if(!isset($existing[$rule]) || $value['specificity'] > $existing[$rule]['specificity']) {
         $existing[$rule] = $value;
        }
      }
      qp($element_child)->data('_css', $existing);
      if(qp($element_child)->children()->length) {
        $this->bubbleCSS(qp($element_child));
      }
    }
  }

  protected function cleanupHTML($html) {
    $dom_document = new DOMDocument();
    @$dom_document->loadHTML($html);
    return preg_replace('|<([^> ]*)/>|i', '<$1 />', $dom_document->saveXML());
  }


  public function lowercaseTags($text) {
    if(strpos($text[0], '!') === FALSE && strpos($text[0], '?') === FALSE) {
      return strtolower($text[0]);
    }
    return $text[0];
  }

  public function runTests() {
    $this->getQuailTests();
    foreach($this->guideline as $test_name) {
      $test_description = (array)$this->quail_tests[$test_name];
      $test = false;
      if($test_description['type'] == 'custom') {
          $test = new $test_description['callback']($test_description, $this->qp, $this->path);
      }
      elseif($test_description['type'] == 'selector') {
        $test = new QuailSelectorTest($test_description['selector'], $this->qp, $this->path);
      }
      else {
        $test_class_name = 'Quail'. ucfirst($test_description['type']) .'Test';
        $test = new $test_class_name($test_description, $this->qp, $this->path);
      }
      if($test) {
        $this->results[$test_name] = $test->getResults();
      }
    }
  }

  protected function getQuailTests() {
    if(!$this->quail_tests) {
      $this->quail_tests = (array)json_decode(file_get_contents('../../resources/tests.json'));
    }
  }

  public function getRawResults($testname = false) {
    if($testname) {
      return $this->results[$testname];
    }
    return $this->results;
  }

  public function getReport($reporter) {
    $reporter->html = $this->html;
    $reporter->results = $this->results;
    $reporter->qp = $this->qp;
    return $reporter->getReport();
  }

}

class QuailReport {

  public $results;

  public $html;

  public $qp;

  public function getReport() {

  }
}

class QuailHTMLReporter extends QuailReport {

  public function getReport() {
    if(!is_array($this->results)) {
      return $this->qp->html();
    }
    foreach($this->results as $test => $objects) {
      foreach($objects as $node) {
        $node->addClass('quail-problem')
             ->addClass('quail-'. $test);
      }
    }
    return $this->qp->html();
  }

}

class QuailTest {

  protected $objects = array();

  protected $status = TRUE;

  protected $qp;

  protected $path;

  protected $requiresTextAnalysis = false;

  function __construct($qp, $path) {
    $this->qp = $qp;
    $this->path = $path;
    if(!method_exists($this, 'run') && $this->selector) {
      $this->reportSingleSelector($this->selector);
      return;
    }
    if($this->requiresTextAnalysis) {
      require_once __DIR__ .'/php-text-statistics/TextStatistics.php';
    }
    $this->run();
  }

  function q($selector) {
    if(is_object($this->qp)) {
      return $this->qp->find($selector);
    }
    return FALSE;
  }

  function reportSingleSelector($selector) {
    foreach($this->q($selector) as $object) {
      $this->objects[] = qp($object);
    }
  }

  function getResults() {
    return $this->objects;
  }

  /**
   * Helper function that returns the last filename of a path.
   */
  protected function getFilename($path) {
    return array_pop(explode('/', $path));
  }

  /**
   * Utility function to sanity-check URLs
   */
  protected function validURL($url) {
    $parsed = parse_url($url);
    if(isset($parsed['scheme']) && !$parsed['host']) {
      return FALSE;
    }
    return (strpos($url, ' ') === FALSE);
  }

  /**
   * Utility function to remove non-readable elemnts from a string
   * indicating that for practical purposes it's empty.
   */
  protected function isUnreadable($string) {
    $string = trim(strip_tags($string));
    return (strlen($string) > 0) ? FALSE : TRUE;
  }

  /**
   * Returns if the element or any children are readable
   */
  function containsReadableText($element, $children = TRUE) {
		if(!$this->isUnreadable($element->text())) {
		  return TRUE;
		}
		if(!$this->isUnreadable($element->attr('alt'))) {
		  return TRUE;
		}
		if($children) {
		  foreach($element->children() as $child) {
		    if($this->containsReadableText(qp($child), $child)) {
		      return TRUE;
		    }
		  }
		}
		return FALSE;
	}

  /**
	*	Retrieves the full path for a file.
	*	@param string $file The path to a file
	*	@return string The absolute path to the file.
	*/
	function getPath($file) {
	  $url = parse_url($file);
		if(isset($url['scheme'])) {
			return $file;
		}
		$path = $this->path;
    if(substr($file, 0, 1) == '/') {
      $path['path'] = $file;
    }
    elseif(substr($path['path'], -1, 1) == '/') {
      $path['path'] .= $file;
    }
    else {
      $path['path'] = explode('/', $path['path']);
      array_pop($path['path']);
      $path['path'] = implode('/', $path['path']);
      $path['path'] .= '/'. $file;
    }
    $port = ($path['port']) ? ':'. $path['port'] : '';
    return $path['scheme'] . '://'. $path['host'] . $port . $path['path'];
	}

	/**
	 * Helper function where we guess if a provided table
	 * is used for data or layout
	 */
	 function isDataTable($table) {
	   return ($table->find('th')->length && $table->find('tr')->length > 2) ? TRUE : FALSE;
	 }

	 function convertFontSize($size) {
	   if(strpos($size, 'px') !== false) {
	     return floatval(str_replace('px', '', $size));
	   }
	   if(strpos($size, 'em') !== false) {
	     return floatval(str_replace('em', '', $size)) * 16;
	   }
	 }

}


class QuailCustomTest extends QuailTest{

  protected $default_options = array();

  function __construct($options, $qp, $path) {
    $this->options = $options + $this->default_options;
    $this->qp = $qp;
    $this->path = $path;
    if($this->requiresTextAnalysis) {
      require_once __DIR__ .'/php-text-statistics/TextStatistics.php';
    }
    $this->run();
  }
}

/**
 * Exception for when the configuration of a Quail instance is wrong.
 */
class QuailConfigurationException extends Exception {

}