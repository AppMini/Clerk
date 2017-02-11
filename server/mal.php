<?php

if (php_sapi_name() == "cli") {
  $HISTORY_FILE = $_SERVER['HOME'] . "/.mal-history";
  function mal_readline($prompt) {
      global $HISTORY_FILE;
      static $history_loaded = false;

      // Load the history file
      if (! $history_loaded) {
          $history_loaded = true;
          if (is_readable($HISTORY_FILE)) {
              if ($file = fopen($HISTORY_FILE, "r")) {
                  while (!feof($file)) {
                      $line = fgets($file);
                      if ($line) { readline_add_history($line); }
                  }
                  fclose($file);
              }
          }
      }

      $line = readline($prompt);
      if ($line === false) { return NULL; }
      readline_add_history($line);

      // Append to the history file
      if (is_writable($HISTORY_FILE)) {
          if ($file = fopen($HISTORY_FILE, "a")) {
              fputs($file, $line . "\n");
              fclose($file);
          }
      }

      return $line;
  }
} else {
  function mal_readline($prompt) {}
}

?>
<?php


// Errors/Exceptions
class Error extends Exception {
    public $obj = null;
    public function __construct($obj) {
        parent::__construct("Mal Error", 0, null);
        $this->obj = $obj;
    }
}


// General functions

function _equal_Q($a, $b) {
    $ota = gettype($a) === "object" ? get_class($a) : gettype($a);
    $otb = gettype($b) === "object" ? get_class($b) : gettype($b);
    if (!($ota === $otb or (_sequential_Q($a) and _sequential_Q($b)))) {
        return false;
    } elseif (_symbol_Q($a)) {
        #print "ota: $ota, otb: $otb\n";
        return $a->value === $b->value;
    } elseif (_list_Q($a) or _vector_Q($a)) {
        if ($a->count() !== $b->count()) { return false; }
        for ($i=0; $i<$a->count(); $i++) {
            if (!_equal_Q($a[$i], $b[$i])) { return false; }
        }
        return true;
    } elseif (_hash_map_Q($a)) {
        if ($a->count() !== $b->count()) { return false; }
        $hm1 = $a->getArrayCopy();
        $hm2 = $b->getArrayCopy();
        foreach (array_keys($hm1) as $k) {
            if (!_equal_Q($hm1[$k], $hm2[$k])) { return false; }
        }
        return true;
    } else {
        return $a === $b;
    }
}

function _sequential_Q($seq) { return _list_Q($seq) or _vector_Q($seq); }


// Scalars
function _nil_Q($obj) { return $obj === NULL; }
function _true_Q($obj) { return $obj === true; }
function _false_Q($obj) { return $obj === false; }
function _string_Q($obj) {
    return is_string($obj) && strpos($obj, chr(0x7f)) !== 0;
}


// Symbols
class SymbolClass {
    public $value = NULL;
    public $meta = NULL;
    public function __construct($value) {
        $this->value = $value;
    }
}
function _symbol($name) { return new SymbolClass($name); }
function _symbol_Q($obj) { return ($obj instanceof SymbolClass); }

// Keywords
function _keyword($name) { return chr(0x7f).$name; }
function _keyword_Q($obj) {
    return is_string($obj) && strpos($obj, chr(0x7f)) === 0;
}



// Functions
class FunctionClass {
    public $func = NULL;
    public $type = 'native';   // 'native' or 'platform'
    public $meta = NULL;
    public $ast = NULL;
    public $env = NULL;
    public $params = NULL;
    public $ismacro = False;
    public function __construct($func, $type,
                                $ast, $env, $params, $ismacro=False) {
        $this->func = $func;
        $this->type = $type;
        $this->ast = $ast;
        #print_r($ast);
        $this->env = $env;
        $this->params = $params;
        $this->ismacro = $ismacro;
    }
    public function __invoke() {
        $args = func_get_args();
        if ($this->type === 'native') {
            $fn_env = new Env($this->env,
                              $this->params, $args);
            $evalf = $this->func;
            return $evalf($this->ast, $fn_env);
        } else {
            return call_user_func_array($this->func, $args);
        }
    }
    public function gen_env($args) {
        return new Env($this->env, $this->params, $args);
    }
    public function apply($args) {
        return call_user_func_array(array(&$this, '__invoke'),$args);
    }
}

function _function($func, $type='platform',
                   $ast=NULL, $env=NULL, $params=NULL, $ismacro=False) {
    return new FunctionClass($func, $type, $ast, $env, $params, $ismacro);
}
function _function_Q($obj) { return $obj instanceof FunctionClass; }


// Parent class of list, vector, hash-map
// http://www.php.net/manual/en/class.arrayobject.php
class SeqClass extends ArrayObject {
    public function slice($start, $length=NULL) {
        $sc = new $this();
        if ($start >= count($this)) {
            $arr = array();
        } else {
            $arr = array_slice($this->getArrayCopy(), $start, $length);
        }
        $sc->exchangeArray($arr);
        return $sc;
    }
}


// Lists
class ListClass extends SeqClass {
    public $meta = NULL;
}

function _list() {
    $v = new ListClass();
    $v->exchangeArray(func_get_args());
    return $v;
}
function _list_Q($obj) { return $obj instanceof ListClass; }


// Vectors
class VectorClass extends SeqClass {
    public $meta = NULL;
}

function _vector() {
    $v = new VectorClass();
    $v->exchangeArray(func_get_args());
    return $v;
}
function _vector_Q($obj) { return $obj instanceof VectorClass; }


// Hash Maps
class HashMapClass extends ArrayObject {
    public $meta = NULL;
}

function _hash_map() {
    $args = func_get_args();
    if (count($args) % 2 === 1) {
        throw new Exception("Odd number of hash map arguments");
    }
    $hm = new HashMapClass();
    array_unshift($args, $hm);
    return call_user_func_array('_assoc_BANG', $args);
}
function _hash_map_Q($obj) { return $obj instanceof HashMapClass; }

function _assoc_BANG($hm) {
    $args = func_get_args();
    if (count($args) % 2 !== 1) {
        throw new Exception("Odd number of assoc arguments");
    }
    for ($i=1; $i<count($args); $i+=2) {
        $ktoken = $args[$i];
        $vtoken = $args[$i+1];
        // TODO: support more than string keys
        if (gettype($ktoken) !== "string") {
            throw new Exception("expected hash-map key string, got: " . gettype($ktoken));
        }
        $hm[$ktoken] = $vtoken;
    }
    return $hm;
}

function _dissoc_BANG($hm) {
    $args = func_get_args();
    for ($i=1; $i<count($args); $i++) {
        $ktoken = $args[$i];
        if ($hm && $hm->offsetExists($ktoken)) {
            unset($hm[$ktoken]);
        }
    }
    return $hm;
}


// Atoms
class Atom {
    public $value = NULL;
    public $meta = NULL;
    public function __construct($value) {
        $this->value = $value;
    }
}
function _atom($val) { return new Atom($val); }
function _atom_Q($atm) { return $atm instanceof Atom; }

?>
<?php


class Reader {
    protected $tokens = array();
    protected $position = 0;
    public function __construct($tokens) {
        $this->tokens = $tokens;
        $this->position = 0;
    }
    public function next() {
        if ($this->position >= count($this->tokens)) { return null; }
        return $this->tokens[$this->position++];
    }
    public function peek() {
        if ($this->position >= count($this->tokens)) { return null; }
        return $this->tokens[$this->position];
    }
}

class BlankException extends Exception {
}

function _real_token($s) {
    return $s !== '' && $s[0] !== ';';
}

function tokenize($str) {
    $pat = "/[\s,]*(~@|[\[\]{}()'`~^@]|\"(?:\\\\.|[^\\\\\"])*\"|;.*|[^\s\[\]{}('\"`,;)]*)/";
    preg_match_all($pat, $str, $matches);
    return array_values(array_filter($matches[1], '_real_token'));
}

function read_atom($reader) {
    $token = $reader->next();
    if (preg_match("/^-?[0-9]+$/", $token)) {
        return intval($token, 10);
    } elseif ($token[0] === "\"") {
        $str = substr($token, 1, -1);
        $str = preg_replace('/\\\\"/', '"', $str);
        $str = preg_replace('/\\\\n/', "\n", $str);
        $str = preg_replace('/\\\\\\\\/', "\\", $str);
        return $str;
    } elseif ($token[0] === ":") {
        return _keyword(substr($token,1));
    } elseif ($token === "nil") {
        return NULL;
    } elseif ($token === "true") {
        return true;
    } elseif ($token === "false") {
        return false;
    } else {
        return _symbol($token);
    }
}

function read_list($reader, $constr='_list', $start='(', $end=')') {
    $ast = $constr();
    $token = $reader->next();
    if ($token !== $start) {
        throw new Exception("expected '" . $start . "'");
    }
    while (($token = $reader->peek()) !== $end) {
        if ($token === "" || $token === null) {
            throw new Exception("expected '" . $end . "', got EOF");
        }
        $ast[] = read_form($reader);
    }
    $reader->next();
    return $ast;
}

function read_hash_map($reader) {
    $lst = read_list($reader, '_list', '{', '}');
    return call_user_func_array('_hash_map', $lst->getArrayCopy());
}

function read_form($reader) {
    $token = $reader->peek();
    switch ($token) {
    case '\'': $reader->next();
               return _list(_symbol('quote'),
                               read_form($reader));
    case '`':  $reader->next();
               return _list(_symbol('quasiquote'),
                               read_form($reader));
    case '~':  $reader->next();
               return _list(_symbol('unquote'),
                               read_form($reader));
    case '~@': $reader->next();
               return _list(_symbol('splice-unquote'),
                               read_form($reader));
    case '^':  $reader->next();
               $meta = read_form($reader);
               return _list(_symbol('with-meta'),
                               read_form($reader),
                               $meta);

    case '@':  $reader->next();
               return _list(_symbol('deref'),
                               read_form($reader));

    case ')': throw new Exception("unexpected ')'");
    case '(': return read_list($reader);
    case ']': throw new Exception("unexpected ']'");
    case '[': return read_list($reader, '_vector', '[', ']');
    case '}': throw new Exception("unexpected '}'");
    case '{': return read_hash_map($reader);

    default:  return read_atom($reader);
    }
}

function read_str($str) {
    $tokens = tokenize($str);
    if (count($tokens) === 0) { throw new BlankException(); }
    return read_form(new Reader($tokens));
}

?>
<?php


function _pr_str($obj, $print_readably=True) {
    if (_list_Q($obj)) {
        $ret = array();
        foreach ($obj as $e) {
            array_push($ret, _pr_str($e, $print_readably));
        }
        return "(" . implode(" ", $ret) . ")";
    } elseif (_vector_Q($obj)) {
        $ret = array();
        foreach ($obj as $e) {
            array_push($ret, _pr_str($e, $print_readably));
        }
        return "[" . implode(" ", $ret) . "]";
    } elseif (_hash_map_Q($obj)) {
        $ret = array();
        foreach (array_keys($obj->getArrayCopy()) as $k) {
            $ret[] = _pr_str($k, $print_readably);
            $ret[] = _pr_str($obj[$k], $print_readably);
        }
        return "{" . implode(" ", $ret) . "}";
    } elseif (is_string($obj)) {
        if (strpos($obj, chr(0x7f)) === 0) {
            return ":".substr($obj,1);
        } elseif ($print_readably) {
            $obj = preg_replace('/\n/', '\\n', preg_replace('/"/', '\\"', preg_replace('/\\\\/', '\\\\\\\\', $obj)));
            return '"' . $obj . '"';
        } else {
            return $obj;
        }
    } elseif (is_double($obj)) {
        return $obj;
    } elseif (is_integer($obj)) {
        return $obj;
    } elseif ($obj === NULL) {
        return "nil";
    } elseif ($obj === true) {
        return "true";
    } elseif ($obj === false) {
        return "false";
    } elseif (_symbol_Q($obj)) {
        return $obj->value;
    } elseif (_atom_Q($obj)) {
        return "(atom " . _pr_str($obj->value, $print_readably) . ")";
    } elseif (_function_Q($obj)) {
        return "(fn* [...] ...)";
    } elseif (is_callable($obj)) {  // only step4 and below
        return "#<function ...>";
    } elseif (is_object($obj)) {
        return "#<object ...>";
    } elseif (is_array($obj)) {
        return "#<array ...>";
    } else {
        throw new Exception("_pr_str unknown type: " . gettype($obj));
    }
}

?>
<?php


function _to_php($obj) {
    if (_list_Q($obj) || _vector_Q($obj) || _hash_map_Q($obj)) {
        $ret = array();
        foreach ($obj as $k => $v) {
            $ret[_to_php($k)] = _to_php($v);
        }
        return $ret;
    } elseif (is_string($obj)) {
        if (strpos($obj, chr(0x7f)) === 0) {
            return ":".substr($obj,1);
        } else {
            return $obj;
        }
    } elseif (_symbol_Q($obj)) {
        return ${$obj->value};
    } elseif (_atom_Q($obj)) {
        return $obj->value;
    } else {
        return $obj;
    }
}

function _to_mal($obj) {
    switch (gettype($obj)) {
    case "object":
      return _to_mal(get_object_vars($obj));
    case "array":
        $obj_conv = array();
        foreach ($obj as $k => $v) {
            $obj_conv[_to_mal($k)] = _to_mal($v);
        }
        if ($obj_conv !== array_values($obj_conv)) {
            $new_obj = _hash_map();
            $new_obj->exchangeArray($obj_conv);
            return $new_obj;
        } else {
            return call_user_func_array('_list', $obj_conv);
        }
    default:
        return $obj;
    }
}

?>
<?php


class Env {
    public $data = array();
    public $outer = NULL;
    public function __construct($outer, $binds=NULL, $exprs=NULL) {
        $this->outer = $outer;
        if ($binds) {
            if (_sequential_Q($exprs)) {
                $exprs = $exprs->getArrayCopy();
            }
            for ($i=0; $i<count($binds); $i++) {
                if ($binds[$i]->value === "&") {
                    if ($exprs !== NULL && $i < count($exprs)) {
                        $lst = call_user_func_array('_list', array_slice($exprs, $i));
                    } else {
                        $lst = _list();
                    }
                    $this->data[$binds[$i+1]->value] = $lst;
                    break;
                } else {
                    if ($exprs !== NULL && $i < count($exprs)) {
                        $this->data[$binds[$i]->value] = $exprs[$i];
                    } else {
                        $this->data[$binds[$i]->value] = NULL;
                    }
                }
            }
        }
    }
    public function find($key) {
        if (array_key_exists($key->value, $this->data)) {
            return $this;
        } elseif ($this->outer) {
            return $this->outer->find($key);
        } else {
            return NULL;
        }
    }
    public function set($key, $value) {
        $this->data[$key->value] = $value;
        return $value;
    }
    public function get($key) {
        $env = $this->find($key);
        if (!$env) {
            throw new Exception("'" . $key->value . "' not found");
        } else {
            return $env->data[$key->value];
        }
    }
}

?>
<?php


// Error/Exception functions
function mal_throw($obj) { throw new Error($obj); }


// String functions
function pr_str() {
    $ps = array_map(function ($obj) { return _pr_str($obj, True); },
                    func_get_args());
    return implode(" ", $ps);
}

function str() {
    $ps = array_map(function ($obj) { return _pr_str($obj, False); },
                    func_get_args());
    return implode("", $ps);
}

function prn() {
    $ps = array_map(function ($obj) { return _pr_str($obj, True); },
                    func_get_args());
    print implode(" ", $ps) . "\n";
    return null;
}

function println() {
    $ps = array_map(function ($obj) { return _pr_str($obj, False); },
                    func_get_args());
    print implode(" ", $ps) . "\n";
    return null;
}


// Number functions
function time_ms() {
    return intval(microtime(1) * 1000);
}


// Hash Map functions
function assoc($src_hm) {
    $args = func_get_args();
    $hm = clone $src_hm;
    $args[0] = $hm;
    return call_user_func_array('_assoc_BANG', $args);
}

function dissoc($src_hm) {
    $args = func_get_args();
    $hm = clone $src_hm;
    $args[0] = $hm;
    return call_user_func_array('_dissoc_BANG', $args);
}

function get($hm, $k) {
    if ($hm && $hm->offsetExists($k)) {
        return $hm[$k];
    } else {
        return NULL;
    }
}

function contains_Q($hm, $k) { return array_key_exists($k, $hm); }

function keys($hm) {
    return call_user_func_array('_list', array_keys($hm->getArrayCopy()));
}
function vals($hm) {
    return call_user_func_array('_list', array_values($hm->getArrayCopy()));
}


// Sequence functions
function cons($a, $b) {
    $tmp = $b->getArrayCopy();
    array_unshift($tmp, $a);
    $l = new ListClass();
    $l->exchangeArray($tmp);
    return $l;
}

function concat() {
    $args = func_get_args();
    $tmp = array();
    foreach ($args as $arg) {
        $tmp = array_merge($tmp, $arg->getArrayCopy());
    }
    $l = new ListClass();
    $l->exchangeArray($tmp);
    return $l;
}

function nth($seq, $idx) {
    if ($idx < $seq->count()) {
        return $seq[$idx];
    } else {
        throw new Exception("nth: index out of range");
    }
}

function first($seq) {
    if ($seq === NULL || count($seq) === 0) {
        return NULL;
    } else {
        return $seq[0];
    }
}

function rest($seq) {
    if ($seq === NULL) {
        return new ListClass();
    } else {
        $l = new ListClass();
        $l->exchangeArray(array_slice($seq->getArrayCopy(), 1));
        return $l;
    }
}

function empty_Q($seq) { return $seq->count() === 0; }

function scount($seq) { return ($seq === NULL ? 0 : $seq->count()); }

function apply($f) {
    $args = array_slice(func_get_args(), 1);
    $last_arg = array_pop($args)->getArrayCopy();
    return $f->apply(array_merge($args, $last_arg));
}

function map($f, $seq) {
    $l = new ListClass();
    # @ to surpress warning if $f throws an exception
    @$l->exchangeArray(array_map($f, $seq->getArrayCopy()));
    return $l;
}

function conj($src) {
    $args = array_slice(func_get_args(), 1);
    $tmp = $src->getArrayCopy();
    if (_list_Q($src)) {
        foreach ($args as $arg) { array_unshift($tmp, $arg); }
        $s = new ListClass();
    } else {
        foreach ($args as $arg) { $tmp[] = $arg; }
        $s = new VectorClass();
    }
    $s->exchangeArray($tmp);
    return $s;
}

function seq($src) {
    if (_list_Q($src)) {
        if (count($src) == 0) { return NULL; }
        return $src;
    } elseif (_vector_Q($src)) {
        if (count($src) == 0) { return NULL; }
        $tmp = $src->getArrayCopy();
        $s = new ListClass();
        $s->exchangeArray($tmp);
        return $s;
    } elseif (_string_Q($src)) {
        if (strlen($src) == 0) { return NULL; }
        $tmp = str_split($src);
        $s = new ListClass();
        $s->exchangeArray($tmp);
        return $s;
    } elseif (_nil_Q($src)) {
        return NULL;
    } else {
        throw new Exception("seq: called on non-sequence");
    }
    return $s;
}



// Metadata functions
function with_meta($obj, $m) {
    $new_obj = clone $obj;
    $new_obj->meta = $m;
    return $new_obj;
}

function meta($obj) {
    return $obj->meta;
}


// Atom functions
function deref($atm) { return $atm->value; }
function reset_BANG($atm, $val) { return $atm->value = $val; }
function swap_BANG($atm, $f) {
    $args = array_slice(func_get_args(),2);
    array_unshift($args, $atm->value);
    $atm->value = call_user_func_array($f, $args);
    return $atm->value;
}


// core_ns is namespace of type functions
$core_ns = array(
    '='=>      function ($a, $b) { return _equal_Q($a, $b); },
    'throw'=>  function ($a) { return mal_throw($a); },
    'nil?'=>   function ($a) { return _nil_Q($a); },
    'true?'=>  function ($a) { return _true_Q($a); },
    'false?'=> function ($a) { return _false_Q($a); },
    'symbol'=> function () { return call_user_func_array('_symbol', func_get_args()); },
    'symbol?'=> function ($a) { return _symbol_Q($a); },
    'keyword'=> function () { return call_user_func_array('_keyword', func_get_args()); },
    'keyword?'=> function ($a) { return _keyword_Q($a); },

    'string?'=> function ($a) { return _string_Q($a); },
    'pr-str'=> function () { return call_user_func_array('pr_str', func_get_args()); },
    'str'=>    function () { return call_user_func_array('str', func_get_args()); },
    'prn'=>    function () { return call_user_func_array('prn', func_get_args()); },
    'println'=>function () { return call_user_func_array('println', func_get_args()); },
    'readline'=>function ($a) { return mal_readline($a); },
    'read-string'=>function ($a) { return read_str($a); },
    'slurp'=>  function ($a) { return file_get_contents($a); },
    '<'=>      function ($a, $b) { return $a < $b; },
    '<='=>     function ($a, $b) { return $a <= $b; },
    '>'=>      function ($a, $b) { return $a > $b; },
    '>='=>     function ($a, $b) { return $a >= $b; },
    '+'=>      function ($a, $b) { return intval($a + $b,10); },
    '-'=>      function ($a, $b) { return intval($a - $b,10); },
    '*'=>      function ($a, $b) { return intval($a * $b,10); },
    '/'=>      function ($a, $b) { return intval($a / $b,10); },
    'time-ms'=>function () { return time_ms(); },

    'list'=>   function () { return call_user_func_array('_list', func_get_args()); },
    'list?'=>  function ($a) { return _list_Q($a); },
    'vector'=> function () { return call_user_func_array('_vector', func_get_args()); },
    'vector?'=> function ($a) { return _vector_Q($a); },
    'hash-map' => function () { return call_user_func_array('_hash_map', func_get_args()); },
    'map?'=>   function ($a) { return _hash_map_Q($a); },
    'assoc' => function () { return call_user_func_array('assoc', func_get_args()); },
    'dissoc' => function () { return call_user_func_array('dissoc', func_get_args()); },
    'get' =>   function ($a, $b) { return get($a, $b); },
    'contains?' => function ($a, $b) { return contains_Q($a, $b); },
    'keys' =>  function ($a) { return keys($a); },
    'vals' =>  function ($a) { return vals($a); },

    'sequential?'=> function ($a) { return _sequential_Q($a); },
    'cons'=>   function ($a, $b) { return cons($a, $b); },
    'concat'=> function () { return call_user_func_array('concat', func_get_args()); },
    'nth'=>    function ($a, $b) { return nth($a, $b); },
    'first'=>  function ($a) { return first($a); },
    'rest'=>   function ($a) { return rest($a); },
    'empty?'=> function ($a) { return empty_Q($a); },
    'count'=>  function ($a) { return scount($a); },
    'apply'=>  function () { return call_user_func_array('apply', func_get_args()); },
    'map'=>    function ($a, $b) { return map($a, $b); },

    'conj'=>   function () { return call_user_func_array('conj', func_get_args()); },
    'seq'=>    function ($a) { return seq($a); },

    'with-meta'=> function ($a, $b) { return with_meta($a, $b); },
    'meta'=>   function ($a) { return meta($a); },
    'atom'=>   function ($a) { return _atom($a); },
    'atom?'=>  function ($a) { return _atom_Q($a); },
    'deref'=>  function ($a) { return deref($a); },
    'reset!'=> function ($a, $b) { return reset_BANG($a, $b); },
    'swap!'=>  function () { return call_user_func_array('swap_BANG', func_get_args()); },
);


?>
<?php


// read
function READ($str) {
    return read_str($str);
}

// eval
function is_pair($x) {
    return _sequential_Q($x) and count($x) > 0;
}

function quasiquote($ast) {
    if (!is_pair($ast)) {
        return _list(_symbol("quote"), $ast);
    } elseif (_symbol_Q($ast[0]) && $ast[0]->value === 'unquote') {
        return $ast[1];
    } elseif (is_pair($ast[0]) && _symbol_Q($ast[0][0]) &&
              $ast[0][0]->value === 'splice-unquote') {
        return _list(_symbol("concat"), $ast[0][1],
                     quasiquote($ast->slice(1)));
    } else {
        return _list(_symbol("cons"), quasiquote($ast[0]),
                     quasiquote($ast->slice(1)));
    }
}

function is_macro_call($ast, $env) {
    return is_pair($ast) &&
           _symbol_Q($ast[0]) &&
           $env->find($ast[0]) &&
           $env->get($ast[0])->ismacro;
}

function macroexpand($ast, $env) {
    while (is_macro_call($ast, $env)) {
        $mac = $env->get($ast[0]);
        $args = array_slice($ast->getArrayCopy(),1);
        $ast = $mac->apply($args);
    }
    return $ast;
}

function eval_ast($ast, $env) {
    if (_symbol_Q($ast)) {
        return $env->get($ast);
    } elseif (_sequential_Q($ast)) {
        if (_list_Q($ast)) {
            $el = _list();
        } else {
            $el = _vector();
        }
        foreach ($ast as $a) { $el[] = MAL_EVAL($a, $env); }
        return $el;
    } elseif (_hash_map_Q($ast)) {
        $new_hm = _hash_map();
        foreach (array_keys($ast->getArrayCopy()) as $key) {
            $new_hm[$key] = MAL_EVAL($ast[$key], $env);
        }
        return $new_hm;
    } else {
        return $ast;
    }
}

function MAL_EVAL($ast, $env) {
    $_SUPERGLOBALS = ["_SERVER", "_GET", "_POST", "_FILES", "_REQUEST", "_SESSION", "_ENV", "_COOKIE"];
    while (true) {

    #echo "MAL_EVAL: " . _pr_str($ast) . "\n";
    if (!_list_Q($ast)) {
        return eval_ast($ast, $env);
    }

    // apply list
    $ast = macroexpand($ast, $env);
    if (!_list_Q($ast)) {
        return eval_ast($ast, $env);
    }
    if ($ast->count() === 0) {
        return $ast;
    }

    $a0 = $ast[0];
    $a0v = (_symbol_Q($a0) ? $a0->value : $a0);
    switch ($a0v) {
    case "def!":
        $res = MAL_EVAL($ast[2], $env);
        return $env->set($ast[1], $res);
    case "let*":
        $a1 = $ast[1];
        $let_env = new Env($env);
        for ($i=0; $i < count($a1); $i+=2) {
            $let_env->set($a1[$i], MAL_EVAL($a1[$i+1], $let_env));
        }
        $ast = $ast[2];
        $env = $let_env;
        break; // Continue loop (TCO)
    case "quote":
        return $ast[1];
    case "quasiquote":
        $ast = quasiquote($ast[1]);
        break; // Continue loop (TCO)
    case "defmacro!":
        $func = MAL_EVAL($ast[2], $env);
        $func->ismacro = true;
        return $env->set($ast[1], $func);
    case "macroexpand":
        return macroexpand($ast[1], $env);
    case "php*":
        $res = eval($ast[1]);
        return _to_mal($res);
    case "try*":
        $a1 = $ast[1];
        $a2 = $ast[2];
        if ($a2[0]->value === "catch*") {
            try {
                return MAL_EVAL($a1, $env);
            } catch (Error $e) {
                $catch_env = new Env($env, array($a2[1]),
                                            array($e->obj));
                return MAL_EVAL($a2[2], $catch_env);
            } catch (Exception $e) {
                $catch_env = new Env($env, array($a2[1]),
                                            array($e->getMessage()));
                return MAL_EVAL($a2[2], $catch_env);
            }
        } else {
            return MAL_EVAL($a1, $env);
        }
    case "do":
        eval_ast($ast->slice(1, -1), $env);
        $ast = $ast[count($ast)-1];
        break; // Continue loop (TCO)
    case "if":
        $cond = MAL_EVAL($ast[1], $env);
        if ($cond === NULL || $cond === false) {
            if (count($ast) === 4) { $ast = $ast[3]; }
            else                   { $ast = NULL; }
        } else {
            $ast = $ast[2];
        }
        break; // Continue loop (TCO)
    case "fn*":
        return _function('MAL_EVAL', 'native',
                         $ast[2], $env, $ast[1]);
    case "$":
        $var = MAL_EVAL($ast[1], $env);
        if (_symbol_Q($var)) {
          $varname = $var->value;
        } elseif (gettype($var) === "string") {
          $varname = $var;
        } else {
          throw new Exception("$ arg unknown type: " . gettype($var));
        }
        if (in_array($varname, $_SUPERGLOBALS)) {
            $val = $GLOBALS[$varname];
        } else {
            $val = ${$varname};
        }
        return _to_mal($val);
    case "!":
        $fn = $ast[1]->value;
        $el = eval_ast($ast->slice(2), $env);
        $args = _to_php($el);
        $res = call_user_func_array($fn, $args);
        return _to_mal($res);
    default:
        $el = eval_ast($ast, $env);
        $f = $el[0];
        $args = array_slice($el->getArrayCopy(), 1);
        if ($f->type === 'native') {
            $ast = $f->ast;
            $env = $f->gen_env($args);
            // Continue loop (TCO)
        } else {
            return $f->apply($args);
        }
    }

    }
}

// print
function MAL_PRINT($exp) {
    return _pr_str($exp, True);
}

// repl
$repl_env = new Env(NULL);
function rep($str) {
    global $repl_env;
    return MAL_PRINT(MAL_EVAL(READ($str), $repl_env));
}

// core.php: defined using PHP
foreach ($core_ns as $k=>$v) {
    $repl_env->set(_symbol($k), _function($v));
}
$repl_env->set(_symbol('eval'), _function(function($ast) {
    global $repl_env; return MAL_EVAL($ast, $repl_env);
}));
$_argv = _list();
if (isset($argv)) {
  for ($i=2; $i < count($argv); $i++) {
      $_argv->append($argv[$i]);
  }
}
$repl_env->set(_symbol('*ARGV*'), $_argv);

// core.mal: defined using the language itself
rep("(def! *host-language* \"php\")");
rep("(def! not (fn* (a) (if a false true)))");
rep("(def! load-file (fn* (f) (eval (read-string (str \"(do \" (slurp f) \")\")))))");
rep("(defmacro! cond (fn* (& xs) (if (> (count xs) 0) (list 'if (first xs) (if (> (count xs) 1) (nth xs 1) (throw \"odd number of forms to cond\")) (cons 'cond (rest (rest xs)))))))");
rep("(def! *gensym-counter* (atom 0))");
rep("(def! gensym (fn* [] (symbol (str \"G__\" (swap! *gensym-counter* (fn* [x] (+ 1 x)))))))");
rep("(defmacro! or (fn* (& xs) (if (empty? xs) nil (if (= 1 (count xs)) (first xs) (let* (condvar (gensym)) `(let* (~condvar ~(first xs)) (if ~condvar ~condvar (or ~@(rest xs)))))))))");

// if we're called in a webserver context, auto-resolve to mal file
if (php_sapi_name() != "cli") {
    $malfile = str_replace(".php", ".mal", $_SERVER['SCRIPT_FILENAME']);
    rep('(load-file "' . $malfile . '")');
    exit(0);
}

if (count($argv) > 1) {
    rep('(load-file "' . $argv[1] . '")');
    exit(0);
}

// repl loop
rep("(println (str \"Mal [\" *host-language* \"]\"))");
do {
    try {
        $line = mal_readline("user> ");
        if ($line === NULL) { break; }
        if ($line !== "") {
            print(rep($line) . "\n");
        }
    } catch (BlankException $e) {
        continue;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
} while (true);

?>
