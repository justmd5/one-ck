<?php

namespace OneCk;

class Types
{
    /**
     * @var Write
     */
    protected $write;

    /**
     * @var Read
     */
    protected $read;

    protected $is_null = false;
    protected $is_null_data = [];
    protected $col_data = [];
    protected $arr_dp = [];
    protected $arr_type = '';

    const BASE_TYPE = [
        'int8'    => ['c', 1],
        'uint8'   => ['C', 1],
        'int16'   => ['s', 2],
        'uint16'  => ['S', 2],
        'int32'   => ['l', 4],
        'uint32'  => ['L', 4],
        'int64'   => ['q', 8],
        // 'uint64' => ['Q', 8], // handled separately
        'float32' => ['f', 4],
        'float64' => ['d', 8]
    ];

    const ALIAS_TYPES = [
        'decimal32' => 'float32',
        'decimal64' => 'float64',
        'date'      => 'uint16',
        'datetime'  => 'uint32',
        'ipv4'      => 'uint32',
        'ipv6'      => 'fixedstring(16)',
        'enum8'     => 'int8',
        'enum16'    => 'int16',
        'nothing'   => 'int8',
        'bool'      => 'uint8',
        'boolean'   => 'uint8',
        'json'      => 'string',
    ];

    public function __construct($write, $read)
    {
        $this->write = $write;
        $this->read = $read;
    }

    // ================== ENCODERS ==================

    public static function encodeIpv4($ip)
    {
        return ip2long($ip);
    }

    public static function encodeIpv6($ip)
    {
        $ar = explode(':', $ip);
        if (count($ar) < 8 || strpos($ip, '::')) {
            $r = [];
            foreach ($ar as $v) {
                if ($v === '') {
                    $r = array_merge($r, array_fill(0, 9 - count($ar), '0000'));
                    continue;
                }
                $r[] = str_pad($v, 4, '0', STR_PAD_LEFT);
            }
            $ar = $r;
        }
        return hex2bin(implode($ar));
    }

    public static function encodeFixedString($str, $n)
    {
        return str_pad($str, $n, chr(0));
    }

    public static function encodeDate($date)
    {
        return ceil(strtotime($date) / 86400);
    }

    public static function encodeDatetime($datetime)
    {
        return strtotime($datetime);
    }

    public static function encodeDatetime64($time, $n = 3)
    {
        $ar = explode('.', $time);
        $l = isset($ar[1]) ? strlen($ar[1]) : 0;
        $n = strtotime($ar[0]) . (isset($ar[1]) ? $ar[1] : '') . str_repeat('0', min(max($n - $l, 0), 9));
        return $n * 1;
    }

    public static function encodeUuid($data)
    {
        $s = str_replace('-', '', $data);
        return pack('L4',
            hexdec(substr($s, 8, 8)),
            hexdec(substr($s, 0, 8)),
            hexdec(substr($s, 24)),
            hexdec(substr($s, 16, 8))
        );
    }

    /**
     * Generalize int128 and int256 encoding
     */
    protected static function encodeIntX($n, $bytes, $unsigned = false)
    {
        if (!is_string($n)) $n = "$n";
        $is_neg = (!$unsigned && $n[0] === '-');
        if ($is_neg) $n = ltrim($n, '-');
        $r = '';
        for ($i = 0; $i < $bytes; $i++) {
            $byte = (int)bcmod($n, '256');
            $n = bcdiv($n, '256', 0);
            $r .= chr($is_neg ? (~$byte & 0xFF) : $byte);
        }
        if ($is_neg) {
            for ($i = 0; $i < $bytes; $i++) {
                $v = ord($r[$i]) + 1;
                $r[$i] = chr($v & 0xFF);
                if ($v <= 0xFF) break;
            }
        }
        return $r;
    }

    public static function encodeInt128($n)
    {
        return self::encodeIntX($n, 16, false);
    }
    public static function encodeInt256($n)
    {
        return self::encodeIntX($n, 32, false);
    }
    public static function encodeUint128($n)
    {
        return self::encodeIntX($n, 16, true);
    }
    public static function encodeUint256($n)
    {
        return self::encodeIntX($n, 32, true);
    }

    protected function encodeUint64($str)
    {
        $str = "{$str}";
        return pack('L2', intval(bcmod($str, '4294967296')), intval(bcdiv($str, '4294967296')));
    }

    // ================== DECODERS ==================

    protected function decodeUint64()
    {
        $str = $this->read->getChar(8);
        $r = unpack('L*', $str);
        return bcadd(bcmul($r[2], bcpow(2, 32)), $r[1]);
    }

    protected function decodeUuid()
    {
        $s = bin2hex($this->read->getChar(8));
        $r = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $r .= $s[$i] . $s[$i + 1];
        }
        $r = substr($r, 0, 8) . '-' . substr($r, 8, 4) . '-' . substr($r, 12);

        $s = bin2hex($this->read->getChar(8));
        $r1 = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $r1 .= $s[$i] . $s[$i + 1];
        }
        $r .= '-' . substr($r1, 0, 4) . '-' . substr($r1, 4);
        return $r;
    }

    protected function decodeIntX($bytes, $unsigned = false)
    {
        $str = $this->read->getChar($bytes);
        $is_neg = (!$unsigned && (ord($str[$bytes - 1]) & 0x80) !== 0);
        $r = '0';
        for ($i = 0; $i < $bytes; $i++) {
            $b = ord($str[$i]);
            if ($is_neg) {
                $b = (~$b) & 0xFF;
                if ($i === 0) $b = ($b + 1) & 0xFF;
            }
            if ($b !== 0) {
                $r = bcadd($r, bcmul("$b", bcpow('256', "$i")));
            }
        }
        return $is_neg ? ('-' . $r) : $r;
    }

    protected function decodeInt128()
    {
        return $this->decodeIntX(16, false);
    }
    protected function decodeInt256()
    {
        return $this->decodeIntX(32, false);
    }
    protected function decodeUint128()
    {
        return $this->decodeIntX(16, true);
    }
    protected function decodeUint256()
    {
        return $this->decodeIntX(32, true);
    }

    protected function decodeIpv6($data)
    {
        $s = bin2hex($data);
        $r = [];
        $a = '';
        for ($i = 0; $i < 32; $i++) {
            $a .= $s[$i];
            if ($i < 31 && $i % 4 === 3) {
                $r[] = ltrim($a, '0') ?: '0';
                $a = '';
            }
        }
        $r[] = ltrim($a, '0') ?: '0';
        $r = implode(':', $r);
        while (strpos($r, ':::') !== false) {
            $r = str_replace(':::', '::', $r);
        }
        return $r;
    }

    // ================== TYPE CHECKERS ==================

    public static function isDecimal($str)
    {
        return preg_match('/^decimal(32|64|128|256)?\(/i', $str) === 1;
    }

    public static function isDatetime64($str)
    {
        return strpos(strtolower($str), 'datetime64(') === 0;
    }

    public static function isArray($str)
    {
        return strpos($str, 'array(') === 0;
    }

    public static function isNullable($str)
    {
        return strpos($str, 'nullable(') === 0;
    }

    public static function isFixedString($str)
    {
        return strpos($str, 'fixedstring(') === 0;
    }

    public static function isSimpleAggregateFunction($str)
    {
        return strpos($str, 'simpleaggregatefunction(') === 0;
    }

    public static function isTuple($str)
    {
        return strpos(strtolower($str), 'tuple(') === 0;
    }

    public static function isEnum($str)
    {
        return preg_match('/^enum(8|16)\(/i', $str) === 1;
    }

    // ================== STRUCT PARSER ==================

    public static function parseStructFields($def)
    {
        $fields = [];
        $level = 0;
        $start = 0;
        $len = strlen($def);
        $name = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $def[$i];
            if ($c === '(') $level++;
            elseif ($c === ')') $level--;
            elseif ($c === ',' && $level === 0) {
                $part = trim(substr($def, $start, $i - $start));
                if ($name === null) {
                    $pos = strspn($part, 'abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
                    $name = substr($part, 0, $pos);
                    $t = trim(substr($part, $pos));
                    $fields[$name] = $t;
                    $name = null;
                } else {
                    throw new CkException("Malformed struct: $def");
                }
                $start = $i + 1;
            }
        }
        $part = trim(substr($def, $start));
        if ($name === null) {
            $pos = strspn($part, 'abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
            $name = substr($part, 0, $pos);
            $t = trim(substr($part, $pos));
            $fields[$name] = $t;
        } else {
            $fields[] = $part;
        }
        return $fields;
    }

    // ================== ALIAS RESOLVER ==================

    protected function alias(&$tp)
    {
        $type = strtolower(trim($tp));

        if (isset(self::BASE_TYPE[$type]) || $type === 'string' || self::isFixedString($type)) {
            return $type;
        }

        if (isset(self::ALIAS_TYPES[$type])) {
            return self::ALIAS_TYPES[$type];
        }

        if (self::isNullable($type)) {
            $this->is_null = true;
            $tp = substr($type, 9, -1);
            return $this->alias($tp);
        }

        if (self::isDecimal($type)) {
            preg_match('/^decimal(\d+)\((\d+),\s*(\d+)\)/i', $type, $m);
            if (!$m) throw new CkException("Invalid decimal type: $type");
            $bits = (int)$m[1];
            switch ($bits) {
                case 32: return 'int32';
                case 64: return 'int64';
                case 128: return 'int128';
                case 256: return 'int256';
                default:
                    throw new CkException("Unsupported decimal size: $bits");
            }
        }

        if (self::isDatetime64($type)) {
            return 'uint64';
        }

        if (self::isSimpleAggregateFunction($type)) {
            $inner = trim(strstr($type, ','), ' ,');
            $tp = substr($inner, 0, -1);
            return $this->alias($tp);
        }

        if (self::isTuple($type)) {
            $content = trim(substr($type, 6, -1));
            $fields = self::parseStructFields($content);
            $this->arr_type = ['__tuple', $fields];
            return 'string'; // placeholder
        }

        $is_arr = false;
        while (self::isArray($type)) {
            $this->arr_dp[] = 'array';
            $type = substr($type, 6, -1);
            $is_arr = true;
        }
        if ($is_arr) {
            $this->arr_type = $type;
            return $this->alias($type);
        }

        return $type;
    }

    // ================== SINGLE ENCODE/DECODE ==================

    protected function singleDecode($type)
    {
        if (isset(self::BASE_TYPE[$type])) {
            $size = self::BASE_TYPE[$type][1];
            $data = $this->read->getChar($size);
            $unpacked = unpack(self::BASE_TYPE[$type][0], $data);
            return reset($unpacked);
        }
        if ($type === 'string') {
            return $this->read->string();
        }
        if ($type === 'uint64') {
            return $this->decodeUint64();
        }
        if (self::isFixedString($type)) {
            $n = (int)substr($type, 12, -1);
            return $this->read->getChar($n);
        }
        if ($type === 'int128') {
            return $this->decodeInt128();
        }
        if ($type === 'int256') {
            return $this->decodeInt256();
        }
        if ($type === 'uint128') {
            return $this->decodeUint128();
        }
        if ($type === 'uint256') {
            return $this->decodeUint256();
        }
        if ($type === 'uuid') {
            return $this->decodeUuid();
        }
        throw new CkException("Unsupported field type in tuple/array: $type");
    }

    protected function singleEncode($value, $type, $real_type)
    {
        if ($value === null) $value = 0;

        if (isset(self::BASE_TYPE[$real_type])) {
            $this->write->addBuf(pack(self::BASE_TYPE[$real_type][0], $value));
            return;
        }
        if ($real_type === 'string') {
            $this->write->string($value);
            return;
        }
        if ($real_type === 'uint64') {
            $this->write->addBuf($this->encodeUint64($value));
            return;
        }
        if (self::isFixedString($real_type)) {
            $n = (int)substr($real_type, 12, -1);
            $this->write->addBuf(self::encodeFixedString($value, $n));
            return;
        }
        if ($real_type === 'int128') {
            $this->write->addBuf(self::encodeInt128($value));
            return;
        }
        if ($real_type === 'int256') {
            $this->write->addBuf(self::encodeInt256($value));
            return;
        }
        if ($real_type === 'uint128') {
            $this->write->addBuf(self::encodeUint128($value));
            return;
        }
        if ($real_type === 'uint256') {
            $this->write->addBuf(self::encodeUint256($value));
            return;
        }
        if ($real_type === 'uuid') {
            $this->write->addBuf(self::encodeUuid($value));
            return;
        }
        throw new CkException("Cannot encode value: unsupported type $real_type");
    }

    // ================== ARRAY HANDLING ==================

    protected function getArrData($row_count, $real_type)
    {
        $deep = count($this->arr_dp);
        $data = array_fill(0, $row_count, []);
        $arr = [];
        $els = [];
        $first = true;

        while ($deep--) {
            $del = [];
            $l = count($data);
            $p = 0;
            foreach ($data as $i => &$val) {
                if ($first) {
                    $arr[] = &$val;
                }
                $num = unpack('Q', $this->read->getChar(8))[1];
                $val = array_fill(0, $num - $p, []);
                $p = $num;
                foreach ($val as &$v) {
                    if ($deep > 0) {
                        $data[] = &$v;
                    } else {
                        $els[] = &$v;
                    }
                }
                $del[] = $i;
                $l--;
                if ($l === 0) break;
            }
            foreach ($del as $i) unset($data[$i]);
            $first = false;
        }

        $row_count = count($els);
        $this->getNull($row_count);
        $this->decode($real_type, $row_count);
        foreach ($this->is_null_data as $i => $v) {
            $this->col_data[$i] = null;
        }
        $this->unFormat($this->arr_type);
        foreach ($els as $i => &$v) {
            $v = $this->col_data[$i];
        }
        $this->col_data = [];
        $this->arr_dp = [];

        return isset($els[0]) ? $arr : [];
    }

    protected function setArrData($in_da, $type, $real_type)
    {
        $data = [];
        $index = [$in_da];
        $r = [];
        $arr_dp = 0;
        while (!empty($index)) {
            $del = [];
            $j = 0;
            foreach ($index as $i => $val) {
                $j += count($val);
                $r[] = $j;
                $del[] = $i;
                if (isset($val[0]) && is_array($val[0])) {
                    foreach ($val as $v) $index[] = $v;
                } else {
                    $data = array_merge($data, $val);
                }
            }
            foreach ($del as $i) unset($index[$i]);
            $arr_dp++;
        }

        if (count($this->arr_dp) !== $arr_dp) {
            throw new CkException('array deep err', CkException::CODE_ARR_ERR);
        }
        array_shift($r);
        $this->write->addBuf(pack('Q*', ...$r));
        $this->setNull($data);
        $this->format($data, $this->arr_type);
        $this->encode($data, $type, $real_type);
        $this->arr_dp = [];
    }

    // ================== NULL HANDLING ==================

    protected function getNull($row_count)
    {
        $this->is_null_data = [];
        if ($this->is_null) {
            for ($i = 0; $i < $row_count; $i++) {
                $n = $this->read->number();
                if ($n === 1) {
                    $this->is_null_data[$i] = 1;
                }
            }
        }
    }

    protected function setNull(&$data)
    {
        if ($this->is_null) {
            foreach ($data as $i => &$v) {
                if ($v === null) {
                    $this->is_null_data[$i] = 1;
                    $v = 0;
                } else {
                    $this->is_null_data[$i] = 0;
                }
            }
            $this->write->addBuf(pack('C*', ...$this->is_null_data));
        }
        $this->is_null_data = [];
    }

    // ================== FORMAT/UNFORMAT ==================

    protected function format(&$data, $type)
    {
        if (isset(self::BASE_TYPE[$type]) || $type === 'string' || self::isFixedString($type)) {
            return;
        }
        $call = [
            'date'     => fn($v) => self::encodeDate($v),
            'datetime' => fn($v) => self::encodeDatetime($v),
            'ipv4'     => fn($v) => self::encodeIpv4($v),
            'ipv6'     => fn($v) => self::encodeIpv6($v),
        ];

        $fn = null;
        if (isset($call[$type])) {
            $fn = $call[$type];
        } elseif (self::isDecimal($type)) {
            preg_match('/^decimal\d+\(\d+,\s*(\d+)\)/i', $type, $m);
            $scale = $m[1] ?? 0;
            $fn = fn($v) => $v !== null ? bcmul($v, bcpow('10', $scale, 0), 0) : null;
        } elseif (self::isDatetime64($type)) {
            preg_match('/^datetime64\((\d+)/', $type, $m);
            $precision = $m[1] ?? 0;
            $fn = fn($v) => self::encodeDatetime64($v, $precision);
        }

        if ($fn) {
            foreach ($data as &$el) $el = $fn($el);
        }
    }

    protected function unFormat($type)
    {
        if (isset(self::BASE_TYPE[$type]) || $type === 'string' || $type === 'uuid' || self::isFixedString($type) || $type === 'nothing') {
            return;
        }
        $call = [
            'date'     => fn($v) => date('Y-m-d', $v * 86400),
            'datetime' => fn($v) => date('Y-m-d H:i:s', $v),
            'ipv4'     => fn($v) => long2ip($v),
            'ipv6'     => fn($v) => $this->decodeIpv6($v),
        ];

        $fn = null;
        if (isset($call[$type])) {
            $fn = $call[$type];
        } elseif (self::isDecimal($type)) {
            preg_match('/^decimal\d+\(\d+,\s*(\d+)\)/i', $type, $m);
            $scale = $m[1] ?? 0;
            $fn = fn($v) => $v !== null ? bcdiv($v, bcpow('10', $scale, 0), $scale) : null;
        } elseif (self::isDatetime64($type)) {
            $fn = fn($v) => date('Y-m-d H:i:s', substr($v, 0, 10)) . '.' . substr($v, 10);
        }

        if ($fn) {
            foreach ($this->col_data as &$el) {
                if ($el !== null) $el = $fn($el);
            }
        }
    }

    // ================== DECODE/ENCODE BLOCKS ==================

    protected function decode($type, $row_count)
    {
        if ($row_count === 0) return;

        if (is_array($type) && $type[0] === '__tuple') {
            $fields = $type[1];
            $names = array_keys($fields);
            $types = array_values($fields);

            $this->col_data = [];
            for ($i = 0; $i < $row_count; $i++) {
                $tuple = [];
                foreach ($types as $idx => $fieldType) {
                    $subReal = $this->alias($fieldType);
                    $value = $this->singleDecode($subReal);
                    $key = $names[$idx] ?? $idx;
                    $tuple[$key] = $value;
                }
                $this->col_data[] = $tuple;
            }
            return;
        }

        if (isset(self::BASE_TYPE[$type])) {
            $size = self::BASE_TYPE[$type][1];
            $raw = $this->read->getChar($size * $row_count);
            $this->col_data = array_values(unpack(self::BASE_TYPE[$type][0] . '*', $raw));
            return;
        }

        $fn = null;
        switch ($type) {
            case 'string':
                $fn = fn() => $this->read->string();
                break;
            case 'uint64':
                $fn = fn() => $this->decodeUint64();
                break;
            case 'int128':
                $fn = fn() => $this->decodeInt128();
                break;
            case 'int256':
                $fn = fn() => $this->decodeInt256();
                break;
            case 'uint128':
                $fn = fn() => $this->decodeUint128();
                break;
            case 'uint256':
                $fn = fn() => $this->decodeUint256();
                break;
            case 'uuid':
                $fn = fn() => $this->decodeUuid();
                break;
            default:
                if (self::isFixedString($type)) {
                    $n = (int)substr($type, 12, -1);
                    $fn = fn() => $this->read->getChar($n);
                } else {
                    throw new CkException("Not supported type: $type", CkException::CODE_NOT_SUPPORTED_TYPE);
                }
        }

        $this->col_data = [];
        for ($i = 0; $i < $row_count; $i++) {
            $this->col_data[] = $fn();
        }
    }

    protected function encode($data, $type, $real_type)
    {
        if (isset(self::BASE_TYPE[$real_type])) {
            $this->write->addBuf(pack(self::BASE_TYPE[$real_type][0] . '*', ...$data));
            return;
        }

        if (is_array($real_type) && $real_type[0] === '__tuple') {
            $fields = $real_type[1];
            $names = array_keys($fields);
            $types = array_values($fields);

            foreach ($data as $tuple) {
                foreach ($types as $idx => $fieldType) {
                    $key = $names[$idx] ?? $idx;
                    $val = $tuple[$key] ?? null;
                    $subReal = $this->alias($fieldType);
                    $this->singleEncode($val, $fieldType, $subReal);
                }
            }
            return;
        }

        $fn = null;
        switch ($real_type) {
            case 'string':
                $fn = fn($v) => $this->write->string($v);
                break;
            case 'uint64':
                $fn = fn($v) => $this->write->addBuf($this->encodeUint64($v));
                break;
            case 'int128':
                $fn = fn($v) => $this->write->addBuf(self::encodeInt128($v));
                break;
            case 'int256':
                $fn = fn($v) => $this->write->addBuf(self::encodeInt256($v));
                break;
            case 'uint128':
                $fn = fn($v) => $this->write->addBuf(self::encodeUint128($v));
                break;
            case 'uint256':
                $fn = fn($v) => $this->write->addBuf(self::encodeUint256($v));
                break;
            case 'uuid':
                $fn = fn($v) => $this->write->addBuf(self::encodeUuid($v));
                break;
            default:
                if (self::isFixedString($real_type)) {
                    $n = (int)substr($real_type, 12, -1);
                    $fn = fn($v) => $this->write->addBuf(self::encodeFixedString($v, $n));
                } else {
                    throw new CkException("Unset type: $type", CkException::CODE_UNSET_TYPE);
                }
        }

        foreach ($data as $el) $fn($el);
    }

    // ================== PUBLIC API ==================

    /**
     * @param string $type
     * @param int $row_count
     * @return array
     */
    public function unpack($type, $row_count)
    {
        $type = strtolower($type);
        $this->is_null = false;
        $real_type = $this->alias($type);

        if (!empty($this->arr_dp)) {
            return $this->getArrData($row_count, $real_type);
        } else {
            $this->getNull($row_count);
            $this->decode($real_type, $row_count);
            foreach ($this->is_null_data as $i => $v) {
                $this->col_data[$i] = null;
            }
            $this->unFormat($type);
            return $this->col_data;
        }
    }

    /**
     * @param array $data
     * @param string $type
     */
    public function pack($data, $type)
    {
        $type = strtolower($type);
        $this->is_null = false;
        $real_type = $this->alias($type);
        $this->format($data, $type);
        if (!empty($this->arr_dp)) {
            $this->setArrData($data, $type, $real_type);
        } else {
            $this->setNull($data);
            $this->encode($data, $type, $real_type);
        }
    }
}
