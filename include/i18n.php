<?php
/**
 * functions/i18n.php
 *
 * Copyright (c) 1999-2004 The Squirrelmail Project Team
 * Licensed under the GNU GPL. For full terms see the file COPYING.
 * Ported for use in XRMS by Brian Peterson
 *
 * This subsystem provides wrappers around the PHP standard gettext functions.
 *
 * See:
 * http://www.php.net/manual/en/function.gettext.php
 * http://www.php.net/manual/en/function.bindtextdomain.php
 * http://www.php.net/manual/en/function.setlocale.php
 *
 * This file contains various functions that are needed to do
 * internationalization of XRMS.
 *
 * Internally the output character set is used. Other characters are
 * encoded using Unicode entities according to HTML 4.0.
 *
 * @version $Id: i18n.php,v 1.11 2006/05/03 20:45:35 vanmer Exp $
 * @package xrms
 * @subpackage i18n
 */

if ( !defined('IN_XRMS') )
{
  die('Hacking attempt');
  exit;
}

/**
 * Converts string from given charset to charset that can be displayed by user translation.
 *
 * Function by default returns html encoded strings, if translation uses different encoding.
 * If Japanese translation is used - function returns string converted to euc-jp
 * If iconv or recode functions are enabled and translation uses utf-8 -
 * function returns utf-8 encoded string.
 * If $charset is not supported - function returns unconverted string.
 *
 * sanitizing of html tags is also done by this function.
 *
 * @param string $charset
 * @param string $string Text to be decoded
 * @return string decoded string
 */
function charset_decode ($charset, $string) {
    global $languages, $xrms_language, $default_charset;
    global $use_php_recode, $use_php_iconv, $aggressive_decoding;

    if (isset($languages[$xrms_language]['XTRA_CODE']) &&
        function_exists($languages[$xrms_language]['XTRA_CODE'])) {
        $string = $languages[$xrms_language]['XTRA_CODE']('decode', $string);
    }

    $charset = strtolower($charset);

    set_my_charset();

    // Variables that allow to use functions without function_exist() calls
    if (! isset($use_php_recode) || $use_php_recode=="" ) {
         $use_php_recode=false;
    }
    if (! isset($use_php_iconv) || $use_php_iconv=="" ) {
         $use_php_iconv=false;
    }

    // Don't do conversion if charset is the same.
    if ( $charset == strtolower($default_charset) )
          return htmlspecialchars($string);

    // catch iso-8859-8-i thing
    if ( $charset == "iso-8859-8-i" ) { $charset = "iso-8859-8"; }

    /*
     * Recode converts html special characters automatically if you use
     * 'charset..html' decoding. There is no documented way to put -d option
     * into php recode function call.
     */
    if ( $use_php_recode ) {
        if ( $default_charset == "utf-8" ) {
            // other charsets can be converted to utf-8 without loss.
            // and output string is smaller
            $string = recode_string($charset . "..utf-8",$string);
            return htmlspecialchars($string);
        } else {
            $string = recode_string($charset . "..html",$string);
            // recode does not convert single quote, htmlspecialchars does.
            $string = str_replace("'", '&#039;', $string);
            return $string;
        }
    }

    // iconv functions does not have html target and can be used only with utf-8
    if ( $use_php_iconv && $default_charset=='utf-8') {
        $string = iconv($charset,$default_charset,$string);
        return htmlspecialchars($string);
    }

    // If we don't use recode and iconv, we'll do it old way.

    /* All HTML special characters are 7 bit and can be replaced first */

    $string = htmlspecialchars ($string);

    /* controls cpu and memory intensive decoding cycles */
    if (! isset($aggressive_decoding) || $aggressive_decoding=="" ) {
         $aggressive_decoding=false;
    }

    $decode=fixcharset($charset);
    $decodefile=$xrms_file_root . 'include/decode/' . $decode . '.php';
    if (file_exists($decodefile)) {
        include_once($decodefile);
        $ret = call_user_func('charset_decode_'.$decode, $string);
    } else {
        $ret = $string;
    }
    return( $ret );
}

/**
 * Makes charset name suitable for decoding cycles
 *
 * @param string $charset Name of charset
 * @return string $charset Adjusted name of charset
 */
function fixcharset($charset) {
    // minus removed from function names
    $charset=str_replace('-','_',$charset);

    // windows-125x and cp125x charsets
    $charset=str_replace('windows_','cp',$charset);

    // ibm > cp
    $charset=str_replace('ibm','cp',$charset);

    // iso-8859-8-i -> iso-8859-8
    // use same cycle until I'll find differences
    $charset=str_replace('iso_8859_8_i','iso_8859_8',$charset);

    return $charset;
}

/**
 * Set up the language to be output
 * if $do_search is true, then scan the browser information
 * for a possible language that we know
 *
 * Function sets system locale environment (LC_ALL, LANG, LANGUAGE),
 * gettext translation bindings and html header information.
 *
 * Function returns error codes, if there is some fatal error.
 *  0 = no error,
 *  1 = mbstring support is not present,
 *  2 = mbstring support is not present, user's translation reverted to en_US.
 *
 * @param string $xrms_language translation used by user's interface
 * @param bool $do_search use browser's preferred language detection functions. Defaults to false.
 * @param bool $default set $xrms_language to $xrms_default_language if language detection fails or language is not set. Defaults to false.
 * @return int function execution error codes.
 */
function set_up_language($xrms_language_param, $do_search = false, $default = false, $override_setup=false) {
    static $SetupAlready = 0;
    
    global $use_gettext, $languages,
           $xrms_language, $xrms_default_language,
           $xrms_notAlias, $userid, $data_dir, $xrms_file_root;
    
    if ($xrms_language_param) $xrms_language=$xrms_language_param;
    
    if ($SetupAlready AND !$override_setup) {
        return;
    }
    
    $SetupAlready = TRUE;
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) { $accept_lang= $_SERVER['HTTP_ACCEPT_LANGUAGE']; };
    
    if ($do_search && ! $xrms_language && isset($accept_lang)) {
        $xrms_language = substr($accept_lang, 0, 2);
    }

    if ((!$xrms_language||$default) && isset($xrms_default_language)) {
        $xrms_language = $xrms_default_language;
    }

    //echo "XRMS Language = ".$xrms_language.'<br>';

    $xrms_notAlias = $xrms_language;

    // Catching removed translation
    // System reverts to English translation if user prefs contain translation
    // that is not available in $languages array
    if (!isset($languages[$xrms_notAlias])) {
      $xrms_notAlias="en_US";
    }

    while (isset($languages[$xrms_notAlias]['ALIAS'])) {
        $xrms_notAlias = $languages[$xrms_notAlias]['ALIAS'];
    }
    
    //echo "XRMS Language Alias = ".$xrms_notAlias.'<br>';

    // now do all the additional checks and set the appropriate variables for language display

    if ( isset($xrms_language) &&
         $use_gettext &&
         $xrms_language != '' &&
         isset($languages[$xrms_notAlias]['CHARSET']) )
    {
        bindtextdomain( 'xrms', $xrms_file_root.'/locale/' );
        textdomain( 'xrms' );
        if (function_exists('bind_textdomain_codeset')) {
            if ($xrms_notAlias == 'ja_JP') {
                bind_textdomain_codeset ("xrms", 'EUC-JP');
            } else {
                bind_textdomain_codeset ("xrms", $languages[$xrms_notAlias]['CHARSET'] );
            }
        }
        if (isset($languages[$xrms_notAlias]['LOCALE'])){
            $longlocale=$languages[$xrms_notAlias]['LOCALE'];
        } else {
            $longlocale=$xrms_notAlias;
        }
        if ( !ini_get('safe_mode') &&
            getenv( 'LC_ALL' ) != $longlocale ) {
            putenv( "LC_ALL=$longlocale" );
            putenv( "LANG=$longlocale" );
            putenv( "LANGUAGE=$longlocale" );
        }
        setlocale(LC_MESSAGES, $longlocale);

        // Set text direction/alignment variables
        if (isset($languages[$xrms_notAlias]['DIR']) &&
            $languages[$xrms_notAlias]['DIR'] == 'rtl') {            
            /**
            * Text direction
            * @global string $text_direction
            */
            $text_direction='rtl';
            $_SESSION['DIR']= 'rtl';
            /**
            * Left alignment
            * @global string $left_align
            */
            $left_align='right';
            /**
            * Right alignment
            * @global string $right_align
            */
            $right_align='left';                                   
        } else {
            $text_direction='ltr';
            $_SESSION['DIR']= 'ltr';
            $left_align='left';
            $right_align='right';
        }

        $xrms_language = $xrms_notAlias;
        if ($xrms_language == 'ja_JP') {
            header ('Content-Type: text/html; charset=EUC-JP');
            if (!function_exists('mb_internal_encoding')) {
                // Error messages can't be displayed here
                $error = 1;
                // Revert to English if possible.
                if (function_exists('setPref')  && $userid!='' && $data_dir!="") {
                    setPref($data_dir, $userid, 'language', "en_US");
                    $error = 2;
                }
                // stop further execution in order not to get php errors on mb_internal_encoding().
                return $error;
            }
            if (function_exists('mb_language')) {
                mb_language('Japanese');
            }
            mb_internal_encoding('EUC-JP');
            mb_http_output('pass');
        } else {
            header( 'Content-Type: text/html; charset=' . $languages[$xrms_notAlias]['CHARSET'] );
        }
    } //end big if check
    return 0;
}

/**
 * Sets default_charset variable according to the one that is used by user's translations.
 *
 * Function changes global $default_charset variable in order to be sure, that it
 * contains charset used by user's translation. Sanity of $xrms_default_language
 * and $default_charset combination provided in xrms config is also tested.
 *
 * There can be a $default_charset setting in the
 * config.php file, but the user may have a different language
 * selected for a user interface. This function checks the
 * language selected by the user and tags the outgoing messages
 * with the appropriate charset corresponding to the language
 * selection. This is "more right" (tm), than just stamping the
 * message blindly with the system-wide $default_charset.
 */
function set_my_charset(){
    global $data_dir, $userid, $default_charset, $languages, $xrms_default_language;

    $my_language = getPref($data_dir, $userid, 'language');
    //$my_language = 'es_ES';
    if (!$my_language) {
        $my_language = $xrms_default_language ;
    }
    // Catch removed translation
    if (!isset($languages[$my_language])) {
        $my_language="en_US";
    }
    while (isset($languages[$my_language]['ALIAS'])) {
        $my_language = $languages[$my_language]['ALIAS'];
    }
    $my_charset = $languages[$my_language]['CHARSET'];
    if ($my_charset) {
        $default_charset = $my_charset;
    }
}

/* ------------------------------ main --------------------------- */

global $xrms_language, $languages, $use_gettext;

if (! isset($xrms_language)) {
    $xrms_language = '';
}

/**
 * Array specifies the available translations.
 *
 * Structure of array:
 * $languages['language']['variable'] = 'value'
 *
 * Possible 'variable' names:
 *  NAME      - Translation name in English
 *  CHARSET   - Encoding used by translation
 *  ALIAS     - used when 'language' is only short name and 'value' should provide long language name
 *  ALTNAME   - Native translation name. Any 8bit symbols must be html encoded.
 *  LOCALE    - Full locale name (in xx_XX.charset format)
 *  DIR       - Text direction. Used to define Right-to-Left languages.
 *              Possible values 'rtl' or 'ltr'. If undefined - defaults to 'ltr'
 *  XTRA_CODE - translation uses special functions. 'value' provides name of that extra function
 *
 * Each 'language' definition requires NAME+CHARSET or ALIAS variables.
 *
 * @name $languages
 * @global array $languages
 */
$languages['bg_BG']['NAME']    = 'Bulgarian';
$languages['bg_BG']['ALTNAME'] = '&#1041;&#1098;&#1083;&#1075;&#1072;&#1088;&#1089;&#1082;&#1080;';
$languages['bg_BG']['CHARSET'] = 'windows-1251';
$languages['bg_BG']['LOCALE']  = 'bg_BG.CP1251';
$languages['bg']['ALIAS'] = 'bg_BG';

$languages['ca_ES']['NAME']    = 'Catalan';
$languages['ca_ES']['CHARSET'] = 'iso-8859-1';
$languages['ca_ES']['LOCALE']  = 'ca_ES.ISO8859-1';
$languages['ca']['ALIAS'] = 'ca_ES';

$languages['cs_CZ']['NAME']    = 'Czech';
$languages['cs_CZ']['ALTNAME'] = '&#268;e&scaron;tina';
$languages['cs_CZ']['CHARSET'] = 'iso-8859-2';
$languages['cs_CZ']['LOCALE']  = 'cs_CZ.ISO8859-2';
$languages['cs']['ALIAS']      = 'cs_CZ';

$languages['cy_GB']['NAME']    = 'Welsh';
$languages['cy_GB']['ALTNAME'] = 'Cymraeg';
$languages['cy_GB']['CHARSET'] = 'iso-8859-1';
$languages['cy_GB']['LOCALE']  = 'cy_GB.ISO8859-1';
$languages['cy']['ALIAS'] = 'cy_GB';

// Danish locale is da_DK.
$languages['da_DK']['NAME']    = 'Danish';
$languages['da_DK']['ALTNAME'] = 'Dansk';
$languages['da_DK']['CHARSET'] = 'iso-8859-1';
$languages['da_DK']['LOCALE']  = 'da_DK.ISO8859-1';
$languages['da']['ALIAS'] = 'da_DK';

$languages['de_DE']['NAME']    = 'German';
$languages['de_DE']['ALTNAME']    = 'Deutsch';
$languages['de_DE']['CHARSET'] = 'iso-8859-1';
$languages['de_DE']['LOCALE']  = 'de_DE.ISO8859-1';
$languages['de']['ALIAS'] = 'de_DE';

$languages['el_GR']['NAME']    = 'Greek';
$languages['el_GR']['ALTNAME'] = '&Epsilon;&lambda;&lambda;&eta;&nu;&iota;&kappa;&#940;';
$languages['el_GR']['CHARSET'] = 'iso-8859-7';
$languages['el_GR']['LOCALE']  = 'el_GR.ISO8859-7';
$languages['el']['ALIAS'] = 'el_GR';

$languages['en_GB']['NAME']    = 'British';
$languages['en_GB']['CHARSET'] = 'iso-8859-15';
$languages['en_GB']['LOCALE']  = 'en_GB.ISO8859-15';

$languages['en_US']['NAME']    = 'English';
$languages['en_US']['CHARSET'] = 'iso-8859-1';
$languages['en_US']['LOCALE']  = 'en_US.ISO8859-1';
$languages['en']['ALIAS'] = 'en_US';

$languages['es_ES']['NAME']    = 'Spanish';
$languages['es_ES']['ALTNAME'] = 'Espa&ntilde;ol';
$languages['es_ES']['CHARSET'] = 'iso-8859-1';
$languages['es_ES']['LOCALE']  = 'es_ES.ISO8859-1';
$languages['es']['ALIAS'] = 'es_ES';

$languages['et_EE']['NAME']    = 'Estonian';
$languages['et_EE']['CHARSET'] = 'iso-8859-15';
$languages['et_EE']['LOCALE']  = 'et_EE.ISO8859-15';
$languages['et']['ALIAS'] = 'et_EE';

$languages['fo_FO']['NAME']    = 'Faroese';
$languages['fo_FO']['CHARSET'] = 'iso-8859-1';
$languages['fo_FO']['LOCALE']  = 'fo_FO.ISO8859-1';
$languages['fo']['ALIAS'] = 'fo_FO';

$languages['fi_FI']['NAME']    = 'Finnish';
$languages['fi_FI']['ALTNAME'] = 'Suomi';
$languages['fi_FI']['CHARSET'] = 'iso-8859-1';
$languages['fi_FI']['LOCALE']  = 'fi_FI.ISO8859-1';
$languages['fi']['ALIAS'] = 'fi_FI';

$languages['fr_FR']['NAME']    = 'French';
$languages['fr_FR']['ALTNAME'] = 'Fran&#231;ais';
$languages['fr_FR']['CHARSET'] = 'iso-8859-1';
$languages['fr_FR']['LOCALE']  = 'fr_FR.ISO8859-1';
$languages['fr']['ALIAS'] = 'fr_FR';

$languages['hr_HR']['NAME']    = 'Croatian';
$languages['hr_HR']['CHARSET'] = 'iso-8859-2';
$languages['hr_HR']['LOCALE']  = 'hr_HR.ISO8859-2';
$languages['hr']['ALIAS'] = 'hr_HR';

$languages['hu_HU']['NAME']    = 'Hungarian';
$languages['hu_HU']['ALTNAME'] = 'Magyar';
$languages['hu_HU']['CHARSET'] = 'iso-8859-2';
$languages['hu_HU']['LOCALE']  = 'hu_HU.ISO8859-2';
$languages['hu']['ALIAS'] = 'hu_HU';

$languages['id_ID']['NAME']    = 'Indonesian';
$languages['id_ID']['ALTNAME'] = 'Bahasa Indonesia';
$languages['id_ID']['CHARSET'] = 'iso-8859-1';
$languages['id_ID']['LOCALE']  = 'id_ID.ISO8859-1';
$languages['id']['ALIAS'] = 'id_ID';

$languages['is_IS']['NAME']    = 'Icelandic';
$languages['is_IS']['ALTNAME'] = '&Iacute;slenska';
$languages['is_IS']['CHARSET'] = 'iso-8859-1';
$languages['is_IS']['LOCALE']  = 'is_IS.ISO8859-1';
$languages['is']['ALIAS'] = 'is_IS';

$languages['it_IT']['NAME']    = 'Italian';
$languages['it_IT']['CHARSET'] = 'iso-8859-1';
#$languages['it_IT']['LOCALE']  = 'it_IT.ISO8859-1';
$languages['it']['ALIAS'] = 'it_IT';

$languages['ja_JP']['NAME']    = 'Japanese';
$languages['ja_JP']['ALTNAME'] = '&#26085;&#26412;&#35486;';
$languages['ja_JP']['CHARSET'] = 'iso-2022-jp';
$languages['ja_JP']['LOCALE'] = 'ja_JP.EUC-JP';
$languages['ja_JP']['XTRA_CODE'] = 'japanese_charset_xtra';
$languages['ja']['ALIAS'] = 'ja_JP';

$languages['ko_KR']['NAME']    = 'Korean';
$languages['ko_KR']['CHARSET'] = 'euc-KR';
$languages['ko_KR']['LOCALE']  = 'ko_KR.EUC-KR';
$languages['ko_KR']['XTRA_CODE'] = 'korean_charset_xtra';
$languages['ko']['ALIAS'] = 'ko_KR';

$languages['lt_LT']['NAME']    = 'Lithuanian';
$languages['lt_LT']['ALTNAME'] = 'Lietuvi&#371;';
$languages['lt_LT']['CHARSET'] = 'utf-8';
$languages['lt_LT']['LOCALE'] = 'lt_LT.UTF-8';
$languages['lt']['ALIAS'] = 'lt_LT';

$languages['nl_NL']['NAME']    = 'Dutch';
$languages['nl_NL']['ALTNAME'] = 'Nederlands';
$languages['nl_NL']['CHARSET'] = 'iso-8859-1';
$languages['nl_NL']['LOCALE']  = 'nl_NL.ISO8859-1';
$languages['nl']['ALIAS'] = 'nl_NL';

$languages['ms_MY']['NAME']    = 'Malay';
$languages['ms_MY']['ALTNAME'] = 'Bahasa Melayu';
$languages['ms_MY']['CHARSET'] = 'iso-8859-1';
$languages['ms_MY']['LOCALE']  = 'ms_MY.ISO8859-1';
$languages['my']['ALIAS'] = 'ms_MY';

$languages['nb_NO']['NAME']    = 'Norwegian (Bokm&aring;l)';
$languages['nb_NO']['ALTNAME'] = 'Norsk (Bokm&aring;l)';
$languages['nb_NO']['CHARSET'] = 'iso-8859-1';
$languages['nb_NO']['LOCALE']  = 'nb_NO.ISO8859-1';
$languages['nb']['ALIAS'] = 'nb_NO';

$languages['nn_NO']['NAME']    = 'Norwegian (Nynorsk)';
$languages['nn_NO']['ALTNAME'] = 'Norsk (Nynorsk)';
$languages['nn_NO']['CHARSET'] = 'iso-8859-1';
$languages['nn_NO']['LOCALE']  = 'nn_NO.ISO8859-1';

$languages['pl_PL']['NAME']    = 'Polish';
$languages['pl_PL']['ALTNAME'] = 'Polski';
$languages['pl_PL']['CHARSET'] = 'iso-8859-2';
$languages['pl_PL']['LOCALE']  = 'pl_PL.ISO8859-2';
$languages['pl']['ALIAS'] = 'pl_PL';

$languages['pt_PT']['NAME'] = 'Portuguese (Portugal)';
$languages['pt_PT']['CHARSET'] = 'iso-8859-1';
$languages['pt_PT']['LOCALE']  = 'pt_PT.ISO8859-1';
$languages['pt']['ALIAS'] = 'pt_PT';

$languages['pt_BR']['NAME']    = 'Portuguese (Brazil)';
$languages['pt_BR']['ALTNAME'] = 'Portugu&ecirc;s do Brasil';
$languages['pt_BR']['CHARSET'] = 'iso-8859-1';
$languages['pt_BR']['LOCALE']  = 'pt_BR.ISO8859-1';

$languages['ro_RO']['NAME']    = 'Romanian';
$languages['ro_RO']['ALTNAME'] = 'Rom&acirc;n&#259;';
$languages['ro_RO']['CHARSET'] = 'iso-8859-2';
$languages['ro_RO']['LOCALE']  = 'ro_RO.ISO8859-2';
$languages['ro']['ALIAS'] = 'ro_RO';

$languages['ru_RU']['NAME']    = 'Russian';
$languages['ru_RU']['ALTNAME'] = '&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;';
$languages['ru_RU']['CHARSET'] = 'utf-8';
$languages['ru_RU']['LOCALE']  = 'ru_RU.UTF-8';
$languages['ru']['ALIAS'] = 'ru_RU';

$languages['sk_SK']['NAME']    = 'Slovak';
$languages['sk_SK']['CHARSET'] = 'iso-8859-2';
$languages['sk_SK']['LOCALE']  = 'sk_SK.ISO8859-2';
$languages['sk']['ALIAS'] = 'sk_SK';

$languages['sl_SI']['NAME']    = 'Slovenian';
$languages['sl_SI']['ALTNAME'] = 'Sloven&scaron;&#269;ina';
$languages['sl_SI']['CHARSET'] = 'iso-8859-2';
$languages['sl_SI']['LOCALE']  = 'sl_SI.ISO8859-2';
$languages['sl']['ALIAS'] = 'sl_SI';

$languages['sr_YU']['NAME']    = 'Serbian';
$languages['sr_YU']['ALTNAME'] = 'Srpski';
$languages['sr_YU']['CHARSET'] = 'iso-8859-2';
$languages['sr_YU']['LOCALE']  = 'sr_YU.ISO8859-2';
$languages['sr']['ALIAS'] = 'sr_YU';

$languages['sv_SE']['NAME']    = 'Swedish';
$languages['sv_SE']['ALTNAME'] = 'Svenska';
$languages['sv_SE']['CHARSET'] = 'iso-8859-1';
$languages['sv_SE']['LOCALE']  = 'sv_SE.ISO8859-1';
$languages['sv']['ALIAS'] = 'sv_SE';

$languages['th_TH']['NAME']    = 'Thai';
$languages['th_TH']['CHARSET'] = 'tis-620';
$languages['th_TH']['LOCALE']  = 'th_TH.TIS-620';
$languages['th']['ALIAS'] = 'th_TH';

$languages['tl_PH']['NAME']    = 'Tagalog';
$languages['tl_PH']['CHARSET'] = 'iso-8859-1';
$languages['tl_PH']['LOCALE']  = 'tl_PH.ISO8859-1';
$languages['tl']['ALIAS'] = 'tl_PH';

$languages['tr_TR']['NAME']    = 'Turkish';
$languages['tr_TR']['CHARSET'] = 'iso-8859-9';
$languages['tr_TR']['LOCALE']  = 'tr_TR.ISO8859-9';
$languages['tr']['ALIAS'] = 'tr_TR';

$languages['zh_TW']['NAME']    = 'Chinese Trad';
$languages['zh_TW']['CHARSET'] = 'big5';
$languages['zh_TW']['LOCALE']  = 'zh_TW.BIG5';
$languages['tw']['ALIAS'] = 'zh_TW';

$languages['zh_CN']['NAME']    = 'Chinese Simp';
$languages['zh_CN']['CHARSET'] = 'gb2312';
$languages['zh_CN']['LOCALE']  = 'zh_CN.GB2312';
$languages['cn']['ALIAS'] = 'zh_CN';

$languages['uk_UA']['NAME']    = 'Ukrainian';
$languages['uk_UA']['CHARSET'] = 'koi8-u';
$languages['uk_UA']['LOCALE']  = 'uk_UA.KOI8-U';
$languages['uk']['ALIAS'] = 'uk_UA';

$languages['ru_UA']['NAME']    = 'Russian (Ukrainian)';
$languages['ru_UA']['CHARSET'] = 'koi8-r';
$languages['ru_UA']['LOCALE']  = 'ru_UA.KOI8-R';

/*
$languages['vi_VN']['NAME']    = 'Vietnamese';
$languages['vi_VN']['CHARSET'] = 'utf-8';
$languages['vi']['ALIAS'] = 'vi_VN';
*/

// Right to left languages
$languages['ar']['NAME']    = 'Arabic';
$languages['ar']['CHARSET'] = 'windows-1256';
$languages['ar']['DIR']     = 'rtl';

$languages['fa_IR']['NAME']    = 'Farsi';
$languages['fa_IR']['CHARSET'] = 'utf-8';
$languages['fa_IR']['DIR']     = 'rtl';
$languages['fa_IR']['LOCALE']  = 'fa_IR.UTF-8';
$languages['fa']['ALIAS']      = 'fa_IR';

$languages['he_IL']['NAME']    = 'Hebrew';
$languages['he_IL']['CHARSET'] = 'windows-1255';
$languages['he_IL']['LOCALE']  = 'he_IL.CP1255';
$languages['he_IL']['DIR']     = 'rtl';
$languages['he']['ALIAS']      = 'he_IL';

/* Detect whether gettext is installed. */
$gettext_flags = 0;
if (function_exists('_')) {
    $gettext_flags += 1;
}
if (function_exists('bindtextdomain')) {
    $gettext_flags += 2;
}
if (function_exists('textdomain')) {
    $gettext_flags += 4;
}

/* If gettext is fully loaded, cool */
if ($gettext_flags == 7) {
    $use_gettext = true;
}
/* If we can fake gettext, try that */
elseif ($gettext_flags == 0) {
    $use_gettext = true;
    include_once($include_directory . 'gettext.php');
} else {
    /* Uh-ho.  A weird install */
    if (! $gettext_flags & 1) {
        function _($str) {
            return $str;
        }
    }
    if (! $gettext_flags & 2) {
        function bindtextdomain() {
            return;
        }
    }
    if (! $gettext_flags & 4) {
        function textdomain() {
            return;
        }
    }
}


/**
 * Japanese charset extra function
 *
 * Action performed by function is defined by first argument.
 * Default return value is defined by second argument.
 * Use of third argument depends on action.
 *
 * @param string $action action performed by this function.
 *    possible values:
 *    decode - convert returned string to euc-jp. third argument unused
 *    encode - convert returned string to jis. third argument unused
 *    strimwidth - third argument=$width. trims string to $width symbols.
 *    encodeheader - create base64 encoded header in iso-2022-jp. third argument unused
 *    decodeheader - return human readable string from mime header. string is returned in euc-jp. third argument unused
 *    downloadfilename - third argument $useragent.
 *            Arguments provide browser info. Returns shift-jis or euc-jp encoded file name
 *    wordwrap - third argument=$wrap. wraps text at $wrap symbols
 *    utf7-imap_encode - returns string converted from euc-jp to utf7-imap. third argument unused
 *    utf7-imap_decode - returns string converted from utf7-imap to euc-jp. third argument unused
 * @param string $ret default return value
 */
function japanese_charset_xtra() {
    $ret = func_get_arg(1);  /* default return value */
    if (function_exists('mb_detect_encoding')) {
        switch (func_get_arg(0)) { /* action */
        case 'decode':
            $detect_encoding = @mb_detect_encoding($ret);
            if ($detect_encoding == 'JIS' ||
                $detect_encoding == 'EUC-JP' ||
                $detect_encoding == 'SJIS' ||
                $detect_encoding == 'UTF-8') {

                $ret = mb_convert_kana(mb_convert_encoding($ret, 'EUC-JP', 'AUTO'), "KV");
            }
            break;
        case 'encode':
            $detect_encoding = @mb_detect_encoding($ret);
            if ($detect_encoding == 'JIS' ||
                $detect_encoding == 'EUC-JP' ||
                $detect_encoding == 'SJIS' ||
                $detect_encoding == 'UTF-8') {

                $ret = mb_convert_encoding(mb_convert_kana($ret, "KV"), 'JIS', 'AUTO');
            }
            break;
        case 'strimwidth':
            $width = func_get_arg(2);
            $ret = mb_strimwidth($ret, 0, $width, '...');
            break;
        case 'encodeheader':
            $result = '';
            if (strlen($ret) > 0) {
                $tmpstr = mb_substr($ret, 0, 1);
                $prevcsize = strlen($tmpstr);
                for ($i = 1; $i < mb_strlen($ret); $i++) {
                    $tmp = mb_substr($ret, $i, 1);
                    if (strlen($tmp) == $prevcsize) {
                        $tmpstr .= $tmp;
                    } else {
                        if ($prevcsize == 1) {
                            $result .= $tmpstr;
                        } else {
                            $result .= str_replace(' ', '',
                                                   mb_encode_mimeheader($tmpstr,'iso-2022-jp','B',''));
                        }
                        $tmpstr = $tmp;
                        $prevcsize = strlen($tmp);
                    }
                }
                if (strlen($tmpstr)) {
                    if (strlen(mb_substr($tmpstr, 0, 1)) == 1)
                        $result .= $tmpstr;
                    else
                        $result .= str_replace(' ', '',
                                               mb_encode_mimeheader($tmpstr,'iso-2022-jp','B',''));
                }
            }
            $ret = $result;
            break;
        case 'decodeheader':
            $ret = str_replace("\t", "", $ret);
            if (eregi('=\\?([^?]+)\\?(q|b)\\?([^?]+)\\?=', $ret))
                $ret = @mb_decode_mimeheader($ret);
            $ret = @mb_convert_encoding($ret, 'EUC-JP', 'AUTO');
            break;
        case 'downloadfilename':
            $useragent = func_get_arg(2);
            if (strstr($useragent, 'Windows') !== false ||
                strstr($useragent, 'Mac_') !== false) {
                $ret = mb_convert_encoding($ret, 'SJIS', 'AUTO');
            } else {
                $ret = mb_convert_encoding($ret, 'EUC-JP', 'AUTO');
}
            break;
        case 'wordwrap':
            $no_begin = "\x21\x25\x29\x2c\x2e\x3a\x3b\x3f\x5d\x7d\xa1\xf1\xa1\xeb\xa1" .
                "\xc7\xa1\xc9\xa2\xf3\xa1\xec\xa1\xed\xa1\xee\xa1\xa2\xa1\xa3\xa1\xb9" .
                "\xa1\xd3\xa1\xd5\xa1\xd7\xa1\xd9\xa1\xdb\xa1\xcd\xa4\xa1\xa4\xa3\xa4" .
                "\xa5\xa4\xa7\xa4\xa9\xa4\xc3\xa4\xe3\xa4\xe5\xa4\xe7\xa4\xee\xa1\xab" .
                "\xa1\xac\xa1\xb5\xa1\xb6\xa5\xa1\xa5\xa3\xa5\xa5\xa5\xa7\xa5\xa9\xa5" .
                "\xc3\xa5\xe3\xa5\xe5\xa5\xe7\xa5\xee\xa5\xf5\xa5\xf6\xa1\xa6\xa1\xbc" .
                "\xa1\xb3\xa1\xb4\xa1\xaa\xa1\xf3\xa1\xcb\xa1\xa4\xa1\xa5\xa1\xa7\xa1" .
                "\xa8\xa1\xa9\xa1\xcf\xa1\xd1";
            $no_end = "\x5c\x24\x28\x5b\x7b\xa1\xf2\x5c\xa1\xc6\xa1\xc8\xa1\xd2\xa1" .
                "\xd4\xa1\xd6\xa1\xd8\xa1\xda\xa1\xcc\xa1\xf0\xa1\xca\xa1\xce\xa1\xd0\xa1\xef";
            $wrap = func_get_arg(2);

            if (strlen($ret) >= $wrap &&
                substr($ret, 0, 1) != '>' &&
                strpos($ret, 'http://') === FALSE &&
                strpos($ret, 'https://') === FALSE &&
                strpos($ret, 'ftp://') === FALSE) {

                $ret = mb_convert_kana($ret, "KV");

                $line_new = '';
                $ptr = 0;

                while ($ptr < strlen($ret) - 1) {
                    $l = mb_strcut($ret, $ptr, $wrap);
                    $ptr += strlen($l);
                    $tmp = $l;

                    $l = mb_strcut($ret, $ptr, 2);
                    while (strlen($l) != 0 && mb_strpos($no_begin, $l) !== FALSE ) {
                        $tmp .= $l;
                        $ptr += strlen($l);
                        $l = mb_strcut($ret, $ptr, 1);
                    }
                    $line_new .= $tmp;
                    if ($ptr < strlen($ret) - 1)
                        $line_new .= "\n";
                }
                $ret = $line_new;
            }
            break;
        case 'utf7-imap_encode':
            $ret = mb_convert_encoding($ret, 'UTF7-IMAP', 'EUC-JP');
            break;
        case 'utf7-imap_decode':
            $ret = mb_convert_encoding($ret, 'EUC-JP', 'UTF7-IMAP');
            break;
        }
    }
    return $ret;
}


/**
 * Korean charset extra functions
 *
 * Action performed by function is defined by first argument.
 * Default return value is defined by second argument.
 *
 * @param string action performed by this function.
 *    possible values:
 *    downloadfilename - Hangul(Korean Character) Attached File Name Fix.
 * @param string default return value
 */
function korean_charset_xtra() {

    $ret = func_get_arg(1);  /* default return value */
    if (func_get_arg(0) == 'downloadfilename') { /* action */
        $ret = str_replace("\x0D\x0A", '', $ret);  /* Hanmail's CR/LF Clear */
        for ($i=0;$i<strlen($ret);$i++) {
            if ($ret[$i] >= "\xA1" && $ret[$i] <= "\xFE") {   /* 0xA1 - 0XFE are Valid */
                $i++;
                continue;
            } else if (($ret[$i] >= 'a' && $ret[$i] <= 'z') || /* From Original ereg_replace in download.php */
                       ($ret[$i] >= 'A' && $ret[$i] <= 'Z') ||
                       ($ret[$i] == '.') || ($ret[$i] == '-')) {
                continue;
            } else {
                $ret[$i] = '_';
            }
        }

    }
    return $ret;
}

/**
 * Replaces non-braking spaces inserted by some browsers with regular space
 *
 * This function can be used to replace non-breaking space symbols
 * that are inserted in forms by some browsers instead of normal
 * space symbol.
 *
 * @param string $string Text that needs to be cleaned
 * @param string $charset Charset used in text
 * @return string Cleaned text
 */
function cleanup_nbsp($string,$charset) {

    // reduce number of case statements
    if (stristr('iso-8859-',substr($charset,0,9))){
        $output_charset="iso-8859-x";
    }
    if (stristr('windows-125',substr($charset,0,11))){
        $output_charset="cp125x";
    }
    if (stristr('koi8',substr($charset,0,4))){
        $output_charset="koi8-x";
    }
    if (! isset($output_charset)){
        $output_charset=strtolower($charset);
    }

    // where is non-breaking space symbol
    switch($output_charset):
        case "iso-8859-x":
        case "cp125x":
        case "iso-2022-jp":
            $nbsp="\xA0";
            break;
        case "koi8-x":
            $nbsp="\x9A";
            break;
        case "utf-8":
            $nbsp="\xC2\xA0";
            break;
        default:
            // don't change string if charset is unmatched
            return $string;
    endswitch;

    // return space instead of non-breaking space.
    return str_replace($nbsp,' ',$string);
}

/**
 * Function informs if it is safe to convert given charset to the one that is used by user.
 *
 * It is safe to use conversion only if user uses utf-8 encoding and when
 * converted charset is similar to the one that is used by user.
 *
 * @param string $input_charset Charset of text that needs to be converted
 * @return bool is it possible to convert to user's charset
 */
function is_conversion_safe($input_charset) {
   global $languages, $xrms_notAlias, $default_charset;

   // convert to lower case
   $input_charset = strtolower($input_charset);

   // Is user's locale Unicode based ?
   if ( $default_charset == "utf-8" ) {
       return true;
   }

   // Charsets that are similar
   switch ($default_charset):
        case "windows-1251":
            if ( $input_charset == "iso-8859-5" ||
                $input_charset == "koi8-r" ||
                $input_charset == "koi8-u" ) {
                return true;
            } else {
                return false;
            }
        case "windows-1257":
            if ( $input_charset == "iso-8859-13" ||
                $input_charset == "iso-8859-4" ) {
                return true;
            } else {
                return false;
            }
        case "iso-8859-4":
            if ( $input_charset == "iso-8859-13" ||
                $input_charset == "windows-1257" ) {
                return true;
            } else {
                return false;
            }
        case "iso-8859-5":
            if ( $input_charset == "windows-1251" ||
                $input_charset == "koi8-r" ||
                $input_charset == "koi8-u" ) {
                return true;
            } else {
                return false;
            }
        case "iso-8859-13":
            if ( $input_charset == "iso-8859-4" ||
                $input_charset == "windows-1257" ) {
                return true;
            } else {
                return false;
            }
        case "koi8-r":
            if ( $input_charset == "windows-1251" ||
                $input_charset == "iso-8859-5" ||
                $input_charset == "koi8-u" ) {
                return true;
            } else {
                return false;
            }
        case "koi8-u":
            if ( $input_charset == "windows-1251" ||
                $input_charset == "iso-8859-5" ||
                $input_charset == "koi8-r" ) {
                return true;
            } else {
                return false;
            }
        default:
            return false;
    endswitch;
} //end fn is_conversion_safe

/* Function that applies gettext to the visible part of a select
   menu.
   Extracts the text between <option> and </option> using a 
   perl regular expression. The translation is done within
   the callback function translate_menu_item().

   @param $menu is the untranslated select menu in html
   @return string translated menu
*/

function translate_menu($menu)
{
	return preg_replace_callback('/(<option[^>]*>)(.*)(<\/option>)/U',
		"translate_menu_item",$menu);
}

/* Callback function used in select menu translation
   The value passed is an array of matches from the perl regular
   expression match in translate_menu().
   
   @param $matches array of regular expression matches. 
          Element [1] is the <option> part
		  Element [2] is the text to be translated
		  Element [3] is the </option> part
   @return string the three elements concatenated with the
          text translated via a call to gettext _()
*/

function translate_menu_item($matches)
{
	return $matches[1].($matches[2]=='' ? '' : _($matches[2])).$matches[3];
}

/* Function used to translate data items that contain html tags
   @param $s string to translate
   @return string containing html and translation of enclosed text 
*/

function translate_data_item($s)
{
    $bare_text = tag_remove($s);
    return str_replace($bare_text,_($bare_text),$s);
}

/* Function to strip <x> tags
   @param $s string to be stripped
   @return string without <x> tags
*/

function tag_remove($s)
{
    return preg_replace('/<[^>]*>/U',"",$s);
}

/**
 * $Log: i18n.php,v $
 * Revision 1.11  2006/05/03 20:45:35  vanmer
 * - change LC_ALL to LC_MESSAGES when setting locale, in order to only change messages, to avoid issues translating database calls
 *
 * Revision 1.10  2005/05/25 05:42:37  alanbach
 * Automatic RTL/LTR patch
 *
 * Revision 1.9  2005/05/06 00:42:16  vanmer
 * - changed parameter passed into setup_language to allow it to override global language already set
 *
 * Revision 1.8  2004/08/19 19:59:54  johnfawcett
 * - function added which can translate only text within html tags leaving the
 *   tags intact
 *
 * Revision 1.7  2004/08/17 10:48:43  johnfawcett
 * - added functions for translating select menus
 *
 * Revision 1.6  2004/08/12 21:17:55  johnfawcett
 * - updated set_up_language function to add $xrms_file_root as global, add
 *   slash to end of it when it is used
 * - commented out $language['it_IT']['LOCALE'] so that the code will use
 *   the default value
 *
 * Revision 1.5  2004/08/12 11:02:55  braverock
 * - add comments and some more debug code
 *
 * Revision 1.4  2004/08/06 14:47:07  braverock
 * - push in changes to turn on i18n gettext
 *
 * Revision 1.3  2004/07/14 11:50:50  cpsource
 * - Added security feature IN_XRMS
 *
 * Revision 1.2  2004/06/21 15:40:31  braverock
 * - modified i18n files to better integrate with XRMS
 *
 * Revision 1.1  2004/05/14 11:07:30  braverock
 * - initial checkin of i18n files -- not yet working, doesn't break anything
 *
 */
?>
