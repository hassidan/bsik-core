<?php
/******************************************************************************/
// Created by: Shlomi Hassid.
// Release Version : 1.0.1
// Creation Date: date
// Copyright 2020, Shlomi Hassid.
/******************************************************************************/
/*****************************      Changelog       ****************************
1.0.1:
    ->creation - initial
*******************************************************************************/
namespace Siktec\Bsik\StdLib;

/**********************************************************************************************************
* Array Methods:
**********************************************************************************************************/
class Std_Array {

    /**
     * is_assoc
     * check if array is associative
     * @param  mixed $array
     * @return void
     */
    public static function is_assoc(array $array) : bool {
        $keys = array_keys($array);
        return $keys !== array_keys($keys);
    }

    /**
     * rename_key
     * renames an array key if it exists and the new one is not set
     * @param  mixed $key
     * @param  mixed $new
     * @param  mixed $arr
     * @return void
     */
    final public static function rename_key(string $old, string $new, array &$arr) : bool {
        if (array_key_exists($old, $arr) && !array_key_exists($new, $arr) ) {
            $arr[$new] = $arr[$old];
            unset($arr[$old]);
            return true;
        }
        return false;
    }

    /**
     * get_from
     * return only required keys if defined else a default value
     * @param  array $data - the array with all the data
     * @param  array $keys - keys to return
     * @param  mixed $default - default value if not set
     * @return array - matching keys and there value
     */
    final public static function get_from(array $data, array $keys, $default = null) : array {
        $filter = array_fill_keys($keys, $default);
        $merged = array_intersect_key($data, $filter) + $filter;
        ksort($merged);
        return $merged;
    }
    
    /**
     * filter_out - copies an array without excluded keys
     *
     * @param  array $input - input array
     * @param  array $exclude - excluded keys
     * @return array
     */
    final public static function filter_out(array $input, array $exclude = []) : array {
        return array_diff_key($input, array_flip($exclude));
    }

    /**
     * extend
     * Merge two arrays - ignores keys that start with $ e.x $key => finall value.
     * @param array $def
     * @param array $ext
     *
     * @return array
     */
    final public static function extend(array $def, array $ext) : array {
        foreach ($ext as $key => $value) {
            if (is_string($key) && $key[0] === '$')
                continue;
            if (is_string($key) || is_int($key)) {
                if (array_key_exists('$'.$key, $def)) {
                    continue;
                } elseif (!array_key_exists($key, $def)) {
                    $def[$key] = $value;
                } else if (is_array($value) && is_array($def[$key])) {
                    $def[$key] = self::extend($def[$key], $value);
                } else {
                    $def[$key] = $value;
                }
            }
        }
        return $def;
    }
    // final public static function extend(array $def, array $ext) : array {
    //     if (empty($def)) {
    //         return $ext;
    //     } else if (empty($ext)) {
    //         return $def;
    //     }
    //     foreach ($ext as $key => $value) {
    //         if (is_string($key) && $key[0] === '$')
    //             continue;
    //         if (is_int($key)) {
    //             $def[] = $value;
    //         } elseif (is_array($ext[$key])) {
    //             if (!isset($def[$key])) {
    //                 $def[$key] = array();
    //             }
    //             if (is_int($key)) {
    //                 $def[] = self::extend($def[$key], $value);
    //             } else {
    //                 $def[$key] = self::extend($def[$key], $value);
    //             }
    //         } else {
    //             $def[$key] = $value;
    //         }
    //     }
    //     return $def;
    // }

    /**
     * validate - walks an array and validate specific key values.
     * use this structure for rules:
     *   - path => rule "{types|}:{func[args]:}"
     *   ex. ["key1" => "string:empty", "key2.key22" => "integer|bool:customFn"]
     * 
     * @param array $rules - all the rules to apply
     * @param array $array - the array to validate
     * @param array $fn    - assoc array with functions to use. 
     * @param array &$error - error messages wil be added to this array. 
     * @return bool true for valid
     * 
     */
    final public static function validate(array $rules, array $array, array $fn = [], array &$errors = []) : bool {
        $initial = count($errors);
        $data = []; 
        self::flatten_to_paths($data, $array);
        foreach ($rules as $path => $rule) {
            $cbs    = explode(":", $rule);
            $types  = explode("|", array_shift($cbs) ?? "");
            $cond = array_map(
                function ($c) {
                    $c = str_replace("'", "\"", $c);
                    return [
                        "cb"   =>  preg_replace('/\[.*\]/m', '', $c),
                        "args" =>  json_decode(preg_replace('/^[^\[]*/m', '', $c), true) ?? []
                    ];
                },
                $cbs
            );
            //Is type declaration used?
            if (empty($types)) {
                $errors[$path] = ["validation rule is missing type declaration."];
                continue;
            }
            //Get values:
            $values = self::path_get($path, $data, null, true);
            if (is_null($values)) {
                $errors[$path] = ["missing value"];
                continue;
            }
            //Validate values:
            foreach ($values as $value) {
                $verr = [];
                $mytype = gettype($value);
                if (!in_array("any", $types, true) && !in_array($mytype, $types, true)) {
                    $verr[] = "invalid type - {$mytype}";
                } else {
                    foreach ($cond as $k => $cnd) {
                        /** @var array $cnd */
                        if ((is_callable($fn[$cnd['cb']] ?? null))) {
                            $test = call_user_func_array(
                                $fn[$cnd['cb']], 
                                [$value, $path, ...$cnd["args"]]
                            );
                            if ($test !== true) {
                                $verr[] = is_string($test) ? $test : "failed rule - {$cnd['cb']}";
                            }
                        } else {
                            $verr[] = "undefined rule - {$cnd['cb']}";
                        }
                    }
                }
                if (!empty($verr)) {
                    $errors[$path] = array_merge(is_array($errors[$path] ?? false) ? $errors[$path] : [], $verr);
                }
            }
        }
        return $initial - count($errors) === 0;
    }

    /**
     * flatten_arr - fill an array with all key "paths" of a given array
     * uses '.' for keys traversal - a path is 'key1.key2 => value'
     * gist: https://gist.github.com/siktec-lab/dc2e7185011a30641d2e3d10db95a20c
     * @param  array& $result = will be filled with all the found paths and their values
     * @param  mixed  $arr    = the array to flatten
     * @param  mixed  $key    = used internally to pass teh current traversable path
     * @return void
     */
    final public static function flatten_to_paths(array &$result, mixed $arr, mixed $key = "") : void {
        if ($key !== "") 
            $result[$key] = $arr;
        if (is_array($arr)) 
            foreach ($arr as $k => $el) 
                self::flatten_to_paths($result, $el, ($key !== "" ? $key.".".$k : $k));
    }
    
    /**
     * in_array_path - check if a key path is valid given an array traverse patterm
     * -> *.num == one.two.num.
     * use '.' for keys traversal
     * use '*' for wildcard traversal
     * use '~' for level ignore.
     * @param  mixed $pattern
     * @param  mixed $path
     * @return void
     */
    final public static function in_array_path(string $pattern, string $path) {
        $keys   = explode('.', $path);
        $steps  = explode('.', $pattern);
        $wild = false;
        foreach ($steps as $step) {
            switch ($step) {
                case "*":  
                    $wild = true; 
                    break;
                case "~":
                    array_shift($keys);
                    break;
                default: {
                    if ($wild) {
                        while (!empty($keys))
                            if (array_shift($keys) === $step)
                                continue 3;
                        return false;
                    } else {
                        $key = array_shift($keys);
                        if ($step !== $key)
                            return false;
                    }
                } break;
            }
        }
        return empty($keys);
    }

    /**
     * path_get
     * walks an array given a string of keys with '.' notation to get inner value or default return
     * Using a wildcard "*" will search intermediate arrays and return an array.
     * ex *.num == one.two.num.
     * use '.' for keys traversal
     * use '*' for wildcard traversal
     * use '~' for level ignore.
     * @param  string $path - example "key1.key2" | 'theme.*.color'
     * @param  array  $arr
     * @param  mixed  $notfound - default value to return - null by default if nothing was found
     * @param  bool   $already_flatten - if the data is allready flatten, This is usefull to prevent repeatedly flattening of the data 
     * @return mixed
     */
    final public static function path_get(string $path, array $data = [], mixed $notfound = null, bool $already_flatten = false) : mixed {
        //create a combined key path:
        $keys   = [];
        $return = [];
        if ($already_flatten) {
            $keys = $data;
        } else {
            self::flatten_to_paths($keys, $data);
        }
        foreach ($keys as $key => $value) {
            if (self::in_array_path($path, $key)) {
                $return[] = $value;
            }
        }
        return empty($return) ? $notfound : $return;
    }
    
    /**
     * values_are_not
     * check if an array don't have values - that means that if any of the values are strictly equals
     * to one of the given values the function will return false
     * @param array $arr
     * @param array $not = ["", null]
     * @return bool
     */
    final public static function values_are_not(array $arr, array $not = ["", null]) : bool {
        foreach ($arr as $v) {
            if (in_array($v, $not, true)) {
                return false;
            }
        }
        return true;
    }

}
