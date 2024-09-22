<?php

set_include_path(SG_LIB_PATH . 'phpseclib');
if (!class_exists('Crypt_AES')) {
    include_once('Crypt/AES.php');
}
if (!function_exists('crypt_random_string')) {
    include_once('Crypt/Random.php');
}

class SGEncrypt
{
    public static function encrypt($data, $key)
    {
        $iv     = self::generateIV();
        $cipher = self::getCipher($iv, $key);
        $data   = $cipher->encrypt($data);

        return $iv . $data;
    }

    public static function decrypt($encrypted, $key)
    {
        list($iv, $data) = self::extractIV($encrypted);

        $cipher = self::getCipher($iv, $key);
        return $cipher->decrypt($data);
    }

    private static function getCipher($iv, $key)
    {
        $cipher = new Crypt_AES('cbc');
        $cipher->setKeyLength(256);
        $cipher->setKey(hex2bin(hash('sha256', $key)));
        $cipher->setIV($iv);

        return $cipher;
    }

    private static function generateIV($length = 32)
    {
        return crypt_random_string($length);
    }

    private static function extractIV($data, $length = 32)
    {
        if (strlen($data) <= $length) {
            $ftpPassword = SGConfig::get('SG_FTP_PASSWORD');
            $data        = self::encrypt($ftpPassword, NONCE_SALT);
            SGConfig::set('SG_FTP_PASSWORD', base64_encode($data));
        }

        $iv   = substr($data, 0, $length);
        $data = substr($data, $length);

        return [$iv, $data];
    }
}
