<?php
// A simplified version of the PHPGangsta GoogleAuthenticator class for standalone projects
class GoogleAuthenticator {
    protected $_codeLength = 6;

    public function createSecret($secretLength = 16) {
        $validChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $secretLength; $i++) {
            $secret .= $validChars[array_rand(str_split($validChars))];
        }
        return $secret;
    }

    public function getQRCodeGoogleUrl($name, $secret, $title = null) {
        $urlencoded = urlencode('otpauth://totp/'.$name.'?secret='.$secret.'&issuer='.urlencode($title));
        return 'https://api.qrserver.com/v1/create-qr-code/?data='.$urlencoded.'&size=200x200&ecc=M';
    }

    public function verifyCode($secret, $code, $discrepancy = 1, $currentTimeSlice = null) {
        if ($currentTimeSlice === null) {
            $currentTimeSlice = floor(time() / 30);
        }
        if (strlen($code) != 6) return false;
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->getCode($secret, $currentTimeSlice + $i);
            if ($calculatedCode == $code) return true;
        }
        return false;
    }

    public function getCode($secret, $timeSlice = null) {
        if ($timeSlice === null) $timeSlice = floor(time() / 30);
        $secretkey = $this->_base32Decode($secret);
        $time = chr(0).chr(0).chr(0).chr(0).pack('N*', $timeSlice);
        $hm = hash_hmac('SHA1', $time, $secretkey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack('N', $hashpart);
        $value = $value[1];
        $value = $value & 0x7FFFFFFF;
        $modulo = pow(10, $this->_codeLength);
        return str_pad($value % $modulo, $this->_codeLength, '0', STR_PAD_LEFT);
    }

    protected function _base32Decode($secret) {
        if (empty($secret)) return '';
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) return false;
        for ($i = 0; $i < 4; $i++) {
            if ($paddingCharCount == $allowedValues[$i] && substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) return false;
        }
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], str_split($base32chars))) return false;
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= ( ($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48 ) ? $y : '';
            }
        }
        return $binaryString;
    }
}
?>