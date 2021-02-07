<?php

function getClientIP()
{
	if (isset($_SERVER["HTTP_CF_CONNECTING_IP"]))
		return $_SERVER["HTTP_CF_CONNECTING_IP"];

	if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
		return $_SERVER['HTTP_X_FORWARDED_FOR'];

	if (isset($_SERVER['REMOTE_ADDR']))
		return $_SERVER['REMOTE_ADDR'];

	return "";
}

function slugify($text)
{
	$text = strtolower($text);

	$text = str_replace(['ü', 'ö', 'ä'], ['ue', 'oe', 'ae'], $text);

	// replace non letter or digits by -
	$text = preg_replace('~[^\\pL\d]+~u', '-', $text);
	// trim
	$text = trim($text, '-');
	// transliterate
	$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
	// lowercase
	$text = strtolower($text);
	// remove unwanted characters
	$text = preg_replace('~[^-\w]+~', '', $text);

	if (empty($text)) {
		return 'n-a';
	}
	return $text;
}

function guid()
{
	if (function_exists('com_create_guid') === true) {
		return strtolower(trim(com_create_guid(), '{}'));
	}
	//return strtolower(sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)));
	$data = openssl_random_pseudo_bytes(16);
	$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
	$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function is_guid($str)
{
	return preg_match('/^\{?[A-Za-z0-9]{8}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{12}\}?$/', $str);
}

function extractGuid($str)
{
	preg_match("/[A-Za-z0-9]{8}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{12}/", $str, $matches);
	if (isset($matches[0])) {
		return $matches[0];
	}
	return false;
}

