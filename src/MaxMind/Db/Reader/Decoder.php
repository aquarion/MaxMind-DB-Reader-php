<?php

namespace MaxMind\Db\Reader;

use MaxMind\Db\Reader\InvalidDatabaseException;

class Decoder
{

    private $debug;
    private $fileStream;
    private $pointerBase;
    // FIXME
    public $POINTER_TEST_HACK;

    private $types = array(
        0  => 'extended',
        1  => 'pointer',
        2  => 'utf8_string',
        3  => 'double',
        4  => 'bytes',
        5  => 'uint16',
        6  => 'uint32',
        7  => 'map',
        8  => 'int32',
        9  => 'uint64',
        10 => 'uint128',
        11 => 'array',
        12 => 'container',
        13 => 'end_marker',
        14 => 'boolean',
        15 => 'float',
    );

    public function __construct(
        $fileStream,
        $pointerBase = 0
    ) {
        $this->fileStream = $fileStream;
        $this->pointerBase = $pointerBase;
        $this->debug = getenv('MAXMIND_DB_DECODER_DEBUG');
    }


    public function decode($offset)
    {
        list(, $ctrlByte) = unpack('C', $this->read($offset, 1));
        $offset++;

        $type = $this->types[$ctrlByte >> 5];

        if ($this->debug) {
            $this->log('Control Byte', $ctrlByte);
            $this->log('Type', $type);
        }
        // Pointers are a special case, we don't read the next $size bytes, we
        // use the size to determine the length of the pointer and then follow
        // it.
        if ($type == 'pointer') {
            list($pointer, $offset) = $this->decodePointer($ctrlByte, $offset);

            // for unit testing
            if ($this->POINTER_TEST_HACK) {
                return array($pointer);
            }

            $result = $this->decode($pointer);

            return array($result, $offset);
        }

        if ($type == 'extended') {
            list(, $nextByte) = unpack('C', $this->read($offset, 1));

            $typeNum = $nextByte + 7;

            if ($this->debug) {
                $this->log('Offset', $offset);
                $this->log('Next Byte', $nextByte);
                $this->log('Type', $this->types[$typeNum]);
            }

            if ($typeNum < 8) {
                throw new InvalidDatabaseException(
                    "Something went horribly wrong in the decoder. An extended type "
                    . "resolved to a type number < 8 ("
                    . $this->types[$typeNum]
                    . ")"
                );
            }

            $type = $this->types[$typeNum];
            $offset++;
        }

        list($size, $offset) = $this->sizeFromCtrlByte($ctrlByte, $offset);

        return $this->decodeByType($type, $offset, $size);
    }

    private function decodeByType($type, $offset, $size)
    {
        // MAP, ARRAY, and BOOLEAN do not use $newOffset as we don't read the
        // next <code>size</code> bytes. For all other types, we do.
        $newOffset = $offset + $size;
        $bytes = $this->read($offset, $size);
        if ($this->debug) {
            $this->logBytes('Bytes to Decode', $bytes);
        }
        switch ($type) {
            case 'map':
                return $this->decodeMap($size, $offset);
            case 'array':
                return $this->decodeArray($size, $offset);
            case 'boolean':
                return array($this->decodeBoolean($size), $offset);
            case 'utf8_string':
                return array($this->decodeString($bytes), $newOffset);
            case 'double':
                return array($this->decodeDouble($bytes), $newOffset);
            case 'float':
                return array($this->decodeFloat($bytes), $newOffset);
            case 'bytes':
                return array($bytes, $newOffset);
            case 'uint16':
                return array($this->decodeUint16($bytes), $newOffset);
            case 'uint32':
                return array($this->decodeUint32($bytes), $newOffset);
            case 'int32':
                return array($this->decodeInt32($bytes), $newOffset);
            case 'uint64':
                return array($this->decodeUint64($bytes), $newOffset);
            case 'uint128':
                return array($this->decodeUint128($bytes), $newOffset);
            default:
                throw new InvalidDatabaseException(
                    "Unknown or unexpected type: " + $type
                );
        }
    }

    private function decodeArray($size, $offset)
    {
        $array = array();

        for ($i = 0; $i < $size; $i++) {
            list($value, $offset) = $this->decode($offset);
            array_push($array, $value);
        }

        if ($this->debug) {
            $this->log("Array size", $size);
            $this->log("Decoded array", serialize($array));
        }

        return array($array, $offset);
    }

    private function decodeBoolean($size)
    {
        return $size == 0 ? false : true;
    }

    private function decodeDouble($bits)
    {
        // FIXME? - Assumes IEEE 754 double on platform
        list(, $double) = unpack('d', $this->maybeSwitchByteOrder($bits));
        return $double;
    }

    private function decodeFloat($bits)
    {
        // FIXME? - Assumes IEEE 754 floats on platform
        list(, $float) = unpack('f', $this->maybeSwitchByteOrder($bits));
        return $float;
    }

    private function decodeInt32($bytes)
    {
        $bytes = $this->zeroPadLeft($bytes, 4);
        // FIXME - this only works on little endian machines.
        // PHP doesn't have a big-endian signed-long unpack.
        list(, $int) = unpack('l', $this->maybeSwitchByteOrder($bytes));
        return $int;
    }

    private function decodeMap($size, $offset)
    {

        $map = array();

        for ($i = 0; $i < $size; $i++) {
            list($key, $offset) = $this->decode($offset);
            list($value, $offset) = $this->decode($offset);
            $map[$key] = $value;
        }

        if ($this->debug) {
            $this->debug("Map size", $size);
            $this->debug("Decoded map", serialize($map));
        }
        return array($map, $offset);
    }

    private $pointerValueOffset = array(
        1 => 0,
        2 => 2048,
        3 => 526336,
        4 => 0,
    );

    private function decodePointer($ctrlByte, $offset)
    {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

        // FIXME - need to update $offset
        $buffer = $this->read($offset, $pointerSize);

        $packed = $pointerSize == 4
            ? $buffer
            : ( pack('C', $ctrlByte & 0x7) ) . $buffer;

        $unpacked = $this->decodeUint32($packed);
        $pointer = $unpacked + $this->pointerBase + $this->pointerValueOffset[$pointerSize];

        if ($this->debug) {
            $this->log('Control Byte', $ctrlByte);
            $this->log('Pointer Offset', $offset);
            $this->log('Pointer Size', $pointerSize);
            $this->logBytes('Packed', $packed);
            $this->log('Unpacked', $unpacked);
            $this->log('Pointer', $pointer);
        }
        return array($pointer, $offset);
    }


    private function decodeUint16($bytes)
    {
        // No big-endian unsigned short format
        return $this->decodeUint32($bytes);
    }

    private function decodeUint32($bytes)
    {
        list(, $int) = unpack('N', $this->zeroPadLeft($bytes, 4));
        return $int;
    }

    private function decodeUint64($bytes)
    {
        return $this->decodeBigUint($bytes, 8);
    }

    private function decodeUint128($bytes)
    {
        return $this->decodeBigUint($bytes, 16);
    }

    private function decodeBigUint($bytes, $size)
    {
        $numberOfLongs = $size/4;
        $integer = 0;
        $bytes = $this->zeroPadLeft($bytes, $size);
        $unpacked = array_merge(unpack("N$numberOfLongs", $bytes));
        foreach ($unpacked as $part) {
            // No bitwise operators with bcmath :'-(
            $integer = bcadd(bcmul($integer, bcpow(2, 32)), $part);
        }
        return $integer;
    }

    private function decodeString($bytes)
    {
        // XXX - NOOP. As far as I know, the end user has to explicitly set the
        // encoding in PHP. Strings are just bytes.
        return $bytes;
    }

    private function read($offset, $numberOfBytes)
    {
        if ($numberOfBytes == 0) {
            return '';
        }
        fseek($this->fileStream, $offset);
        return fread($this->fileStream, $numberOfBytes);
    }

    private function sizeFromCtrlByte($ctrlByte, $offset)
    {
        $size = $ctrlByte & 0x1f;
        $bytesToRead = $size < 29 ? 0 : $size - 28;
        $bytes = $this->read($offset, $size);
        $decoded = $this->decodeUint32($bytes);

        if ($size == 29) {
            $size = 29 + $decoded;
        } elseif ($size == 30) {
            $size = 285 + $decoded;
        } elseif ($size > 30) {
            $size = $decoded & (0x0FFFFFFF >> (32 - (8 * $bytesToRead)))
                + 65821;
        }
        return array($size, $offset + $bytesToRead);
    }

    private function zeroPadLeft($content, $desiredLength)
    {
        return str_pad($content, $desiredLength, "\x00", STR_PAD_LEFT);
    }

    private function log($name, $message)
    {
        print("$name: $message\n");
    }

    private function logBytes($name, $bytes)
    {
        $message = implode(',', array_map('dechex', unpack('C*', $bytes)));
        $this->log($name, $message);
    }

    private function maybeSwitchByteOrder($bytes)
    {
        // FIXME - Right now it _always_ switches byte order. When the object
        // is created, it should detect endianness and that should be used
        // here.
        return strrev($bytes);
    }
}
