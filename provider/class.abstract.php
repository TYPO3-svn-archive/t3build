<?php
/**
 * Base class for providers - which extracts the CLI help
 * from the docBlocks of the class and the class vars which
 * have an @arg tag.
 * 
 * If you label an argument with @required it will be 
 * required and checked upfront - if it's missing, the 
 * execution will stop with an error.
 * 
 * If you need wildcard arguments (eg. to pass them to
 * another provider) you can label them with @mask:
 * @mask --clean-*
 * 
 * The type (@var) of the arguments will be considered and
 * CLI args will be casted accordingly before execution.
 * 
 * When there are setter methods for the arguments
 * (setArgument) they will be called instead of directly
 * setting the class vars.
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 */
abstract class tx_t3build_provider_abstract
{
    /**
     * Missing arguments
     * @var array
     */
    private $_missing = array();

    /**
     * Argument information array
     * @var array
     */
    private $_infos = array();

    /**
     * Required arguments
     * @var array
     */
    private $_requireds = array();

    /**
     * Reflection of $this class
     * @var ReflectionClass
     */
    private $_class;

    /**
     * Override this if you want the default action
     * to be another than that with the class name
     * + 'Action' as method name.
     * @var string
     */
    protected $defaultActionName;

    /**
     * Print debug information
     * @arg
     * @var boolean
     */
    protected $debug = false;

    /**
     * Print help information
     * @arg
     * @var boolean
     */
    protected $help = false;

    /**
     * The raw cli args as passed from TYPO3
     * @var array
     */
    protected $cliArgs = array();

	/**
	 * Initialization: Retrieve the information about
	 * the arguments and set the corresponding class
	 * vars accordingly or fail the execution when
	 * @required arguments are missing.
	 * 
	 * @param array $args
	 */
	public function init($args)
	{
	    $this->cliArgs = $args;
        $this->_class = new ReflectionClass($this);
        $masks = array();
        $modifiers = array();

        foreach ($this->_class->getProperties() as $i => $property) {
            if (preg_match_all('/^\s+\*\s+@([^\s]+)(.*)$/m', $property->getDocComment(), $matches)) {
                if (!in_array('arg', $matches[1])) {
                    continue;
                }
                $filteredName = ltrim($property->getName(), '_');
                $name = ucfirst($filteredName);
                preg_match_all('/[A-Z][a-z]*/', $name, $words);
                $shorthand = '';
                $switch = strtolower(implode('-', $words[0]));
                $shorthand = !array_key_exists('-'.$filteredName[0], $modifiers) ? $filteredName[0] : null;
                $info = array(
                    'setter' => method_exists($this, 'set'.$name) ? 'set'.$name : null,
                    'property' => $property->getName(),
                    'switch' => $switch,
                    'shorthand' => $shorthand,
                    'comment' => $property->getDocComment(),
                    'type' => null,
                    'mask' => null
                );

                $maskKey = array_search('mask', $matches[1]);
                if ($maskKey !== false && $matches[2][$maskKey]) {
                    $info['type'] = 'mask';
                    $info['mask'] = ltrim(trim($matches[2][$maskKey]), '-');
                    $info['shorthand'] = $shorthand = null;
                    $info['switch'] = $switch = null;
                    $masks[$i] = trim($info['mask'], '*');
                } else {
                    $varKey = array_search('var', $matches[1]);
                    if ($varKey !== false) {
                        $info['type'] = trim($matches[2][$varKey]);
                    }
                }
                $this->_infos[$i] = $info;
                $this->_requireds[$i] = in_array('required', $matches[1]);
                if ($shorthand) {
                    $modifiers['-'.$shorthand] = $i;
                }
                if ($switch) {
                    $modifiers['--'.$switch] = $i;
                }
            }
        }
	    $values = array();
        foreach ($args as $argument => $value) {
            if (!preg_match('/^(-{1,2})(.+)/', $argument, $parts)) {
                continue;
            }
            $realArgs = array($parts[2]);
            $argsCount = 1;
            for ($n = 0; $n < $argsCount; $n++) {
                $modifier = $parts[1].$realArgs[$n];
                if (!array_key_exists($modifier, $modifiers)) {
                    if ($argsCount == 1) {
                        foreach ($masks as $i => $mask) {
                            if (substr($parts[2], 0, $l = strlen($mask)) == $mask) {
                                if (!isset($values[$i])) {
                                    $values[$i] = (array) $this->{$this->_infos[$i]['property']};
                                }
                                $values[$i][$parts[1].substr($parts[2], $l)] = $value;
                                break 2;
                            }
                        }
                        if ($parts[1] == '-') {
                            $realArgs = str_split('0'.$parts[2]);
                            $argsCount = count($realArgs);
                            continue;
                        }
                    }
                    $this->_die('Unknown modifier "%s"', $modifier);
                }
                $i = $modifiers[$modifier];
                switch ($this->_infos[$i]['type']) {
                    case 'boolean':
                    case 'bool':
                        $value = !count($value) || !in_array($value[0], array('false', '0'), true) ? true : false;
                        break;
                    case 'string':
                        $value = implode(',', $value);
                        break;
                    case 'int':
                    case 'integer':
                        $value = (int) $value[0];
                        break;
                    case 'float':
                        $value = (float) $value[0];
                        break;
                    case 'array':
                        break;
                    default:
                        $value = $value[0];
                }
                if ($this->_infos[$i]['property'] == 'debug') {
                    $this->debug = $value;
                }
                $values[$i] = $value;
            }
        }
	    foreach ($values as $i => $value) {
            if ($this->_infos[$i]['setter']) {
                $this->_debug('Calling setter '.$this->_infos[$i]['setter'].' with ', $value);
                $this->{$this->_infos[$i]['setter']}($value);
            } else {
                $this->_debug('Setting property '.$this->_infos[$i]['property'].' to ', $value);
                $this->{$this->_infos[$i]['property']} = $value;
            }
        }
        foreach ($this->_requireds as $i => $required) {
            if ($required && !array_key_exists($i, $values)) {
                $this->_missing[] = '"'.$this->_infos[$i]['switch'].'"';
            }
        }
	}
    
    /**
     * Render the help from the argument information
     * @return string
     */
    protected function renderHelp()
    {
        preg_match_all('/^\s+\* ([^@\/].*)$/m', $this->_class->getDocComment(), $lines);
        $help = implode("\n", $lines[1])."\n\n";
        $help .= 'php '.$_SERVER['PHP_SELF'];
        foreach ($this->_requireds as $i => $required) {
            if ($required) {
                $help .= ' -'.$this->_infos[$i]['shorthand'].' "'.$this->_infos[$i]['switch'].'"';
            }
        }

        $longest = 0;
        $order = array();
        foreach ($this->_infos as $i => $info) {
            // Help stuff
            preg_match_all('/^\s+\* ([^@\/].*)$/m', $info['comment'], $lines);
            $this->_infos[$i]['desc'] = $lines[1];
            $this->_infos[$i]['default'] = $this->{$info['property']};
            if ($this->_infos[$i]['mask']) {
                $this->_infos[$i]['switchDesc'] = '-'.$this->_infos[$i]['mask'].', --'.$this->_infos[$i]['mask'];
            } else {
                $this->_infos[$i]['switchDesc'] = '--'.$info['switch'];
                if ($info['shorthand']) {
                    $this->_infos[$i]['switchDesc'] = '-'.$info['shorthand'].' ['.$this->_infos[$i]['switchDesc'].']';
                }
            }
            $length = strlen($this->_infos[$i]['switchDesc']);
            if ($length > $longest) {
                $longest = $length;
            }
            $order[$i] = $info['switch'];
        }
        
        asort($order);

        $help .= PHP_EOL.PHP_EOL;
        $pre = str_repeat(' ', $longest+1);
        foreach (array_keys($order) as $i) {
            $info = $this->_infos[$i];
            $length = strlen($info['switchDesc']);
            $default = $info['default'];
            if ($default !== '' && $default !== null) {
                if ($default === true) {
                    $default = 'true';
                } elseif ($default === false) {
                    $default = 'false';
                } elseif ($info['type'] == 'array') {
                    $default = implode(', ', (array) $default);
                }
                $info['desc'][] .= '(defaults to "'.$default.'")';
            }
            $help .= $info['switchDesc'].str_repeat(' ', $longest - $length + 1).':'.' ';
            $help .= implode(PHP_EOL.str_repeat(' ', $longest+3), $info['desc']);
            $help .= PHP_EOL;
        }
        
        return $help;
    }

	/**
	 * Output help
	 */
	public function helpAction()
	{
        $this->_echo($this->renderHelp());
	}

    /**
     * Run the provider
     * 
     * @param string|null $action
     * @return mixed|void
     */
    public function run($action = null)
    {
        if ($this->help) {
            $action = 'help';
        }
        if (!$action) {
            if ($this->defaultActionName) {
                $action = $this->defaultActionName;
            } else {
                $methods = $this->_class->getMethods();
                $actions = array();
                foreach ($methods as $method) {
                    /* @var $method ReflectionMethod */
                    if ($method->name != 'helpAction' && substr($method->name, -6) == 'Action') {
                        $actions[$name = substr($method->name, 0, -6)] = $name;
                    }
                }
                if (count($actions) == 1) {
                    $action = array_shift($actions);
                }
            }
        }
        if (!$action) {
            $this->_echo('No action provided');
            $action = 'help';
        }
        if (!is_callable(array($this, $action.'Action'))) {
            $this->_echo('Invalid action "'.$action.'"');
            $action = 'help';
        }
        if (count($this->_missing) && $action != 'help') {
            $this->_echo('Missing argument'.(count($this->_missing) > 1 ? 's' : '').' %s', $this->_missing);
            $action = 'help';
        }
        return call_user_func(array($this, $action.'Action'));
    }

    /**
     * Echo vsprintfed string
     * 
     * @param string $msg (can contain sprintf format)
     * @param mixed $arg
     * @param ...
     */
    protected function _echo($msg)
    {
        $args = func_get_args();
        array_shift($args);
        foreach ($args as $i => $arg) {
            if (is_array($arg)) {
                $and = is_numeric($i) ? 'and' : $i;
                $last = array_pop($arg);
                $args[$i] = count($arg) ? implode(', ', $arg).' '.$and.' '.$last : $last;
            }
        }
        echo vsprintf((string) $msg, $args)."\n";
    }
    
    /**
     * Echo vsprintfed string and exit with error
     * 
     * @param string $msg (can contain sprintf format)
     * @param mixed $arg
     * @param ...
     */
    protected function _die($msg)
    {
        $args = func_get_args();
        call_user_func_array(array($this, '_echo'), $args);
        exit(1);
    }

    /**
     * Dump vars only if --debug is on
     * 
     * @param string $msg
     * @param mixed $var
     * @param ...
     */
    protected function _debug($msg)
    {
        if (!$this->debug) {
            return;
        }
        $args = func_get_args();
        echo '[Debug] '.trim(array_shift($args));
        if (count($args)) {
            echo ' ';
            call_user_func_array('var_dump', $args);
        } else {
            echo PHP_EOL;
        }
    }

    /**
     * Write config to extConf
     * 
     * @param string $extKey
     * @param array $update
     */
    protected function writeExtConf($extKey, array $update)
    {
        global $TYPO3_CONF_VARS;

        $absPath = t3lib_extMgm::extPath($extKey);
        $relPath = t3lib_extMgm::extRelPath($extKey);

        /* @var $tsStyleConfig t3lib_tsStyleConfig */
    	$tsStyleConfig = t3lib_div::makeInstance('t3lib_tsStyleConfig');
		$theConstants = $tsStyleConfig->ext_initTSstyleConfig(
			t3lib_div::getUrl($absPath . 'ext_conf_template.txt'),
			$absPath,
			$relPath,
			''
		);

		$arr = @unserialize($TYPO3_CONF_VARS['EXT']['extConf'][$extKey]);
		$arr = is_array($arr) ? $arr : array();

			// Call processing function for constants config and data before write and form rendering:
		if (is_array($TYPO3_CONF_VARS['SC_OPTIONS']['typo3/mod/tools/em/index.php']['tsStyleConfigForm'])) {
			$_params = array('fields' => &$theConstants, 'data' => &$arr, 'extKey' => $extKey);
			foreach ($TYPO3_CONF_VARS['SC_OPTIONS']['typo3/mod/tools/em/index.php']['tsStyleConfigForm'] as $_funcRef) {
				t3lib_div::callUserFunction($_funcRef, $_params, $this);
			}
			unset($_params);
		}


        $arr = t3lib_div::array_merge_recursive_overrule($arr, $update);

		/* @var $instObj t3lib_install */
		$instObj = t3lib_div::makeInstance('t3lib_install');
		$instObj->allowUpdateLocalConf = 1;
		$instObj->updateIdentity = 'TYPO3 Extension Manager';

		// Get lines from localconf file
		$lines = $instObj->writeToLocalconf_control();
		$instObj->setValueInLocalconfFile($lines, '$TYPO3_CONF_VARS[\'EXT\'][\'extConf\'][\'' . $extKey . '\']', serialize($arr)); // This will be saved only if there are no linebreaks in it !
		$instObj->writeToLocalconf_control($lines);

		t3lib_extMgm::removeCacheFiles();
    }

    /**
     * Parses $vars into a path mask and makes it FS-safe
     *
     * @param string $mask
     * @param array $vars
     * @param string $renameMode
     * @param boolean $absolute
     * @return string
     */
    protected function getPath($mask, $vars, $renameMode = 'camelCase', $absolute = false)
    {
        $replace = array();
        foreach ($vars as $key => $value) {
            $replace[] = '${'.$key.'}';
        }
        $path = str_replace($replace, $vars, $mask);
        if (preg_match('/\$\{([^\}]*)\}/', $path, $res)) {
            $this->_die('Unknown var "'.$res[1].'" in path mask');
        }

        $pre = '';
        if ($absolute) {
            $parts = preg_split('#\s*[\\/]+\s*#', $path);
            $rest = array();
            while (count($parts)) {
                $file = implode('/', $parts);
                if (file_exists($file)) {
                    if (!count($rest) && is_file($file)) {
                        return $file;
                    }
                    $pre = $file.'/';
                    $path = implode('/', $rest);
                    break;
                }
                array_unshift($rest, array_pop($parts));
            }
        }

        $path = strtolower($path);
        $path = str_replace(':', '-', $path);
        $path = preg_replace('#[^A-Za-z0-9/\-_\.]+#', ' ', $path);
        $path = preg_replace('#\s*/+\s*#', '/', $path);
        $parts = explode(' ', $path);
        if ($renameMode == 'underscore') {
            $path = implode('_', $parts);
        } else {
            $path = '';
            $uc = false;
            foreach ($parts as $part) {
                $ucPart = ucfirst($part);
                $path .= ($uc || $renameMode === 'CamelCase') ? $ucPart : $part;
                $uc = $ucPart != $part;
            }
        }
        return $pre.$path;
    }
}
