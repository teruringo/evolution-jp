<?php
ini_set('display_errors',1);
function setOption($fieldName,$value='') {
    $_SESSION[$fieldName] = $value;
    return $value;
}

function getOption($fieldName) {
    if (isset($_POST[$fieldName]) && $_POST[$fieldName]!=='') {
        return $_POST[$fieldName];
    }

    if (isset($_SESSION[$fieldName]) && $_SESSION[$fieldName]!=='') {
        return $_SESSION[$fieldName];
    }

    if(isset($GLOBALS[$fieldName])  && $GLOBALS[$fieldName]!=='') {
        return $GLOBALS[$fieldName];
    }

    return false;
}

function autoDetectLang() {
    if(!isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        return 'english';
    }
    $lc = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    if ($lc === 'ja') {
        return 'japanese-utf8';
    }
    if ($lc === 'ru') {
        return 'russian-utf8';
    }
    return 'english';
}

function includeLang($lang_name, $dir='langs/') {
    global $_lang;
    
    $_lang = array ();
    $lang_name = str_replace('\\','/',$lang_name);
    if(strpos($lang_name,'/')!==false) {
         require_once(MODX_SETUP_PATH . 'langs/english.inc.php');
    }
    elseif(is_file(MODX_SETUP_PATH . $dir . $lang_name . '.inc.php')) {
         require_once(MODX_SETUP_PATH . $dir . $lang_name . '.inc.php');
    }
    else {
        require_once(MODX_SETUP_PATH . $dir . 'english.inc.php');
    }
}

function key_field($category='') {
    if($category==='template') {
        return 'templatename';
    }
    return 'name';
}
function table_name($category='') {
    if ($category === 'template') {
        return 'site_templates';
    }
    if ($category === 'tv') {
        return 'site_tmplvars';
    }
    if ($category === 'chunk') {
        return 'site_htmlsnippets';
    }
    if ($category === 'snippet') {
        return 'site_snippets';
    }
    if ($category === 'plugin') {
        return 'site_plugins';
    }
    if ($category === 'module') {
        return 'site_modules';
    }
    return '';
}

function mode($category) {
    if ($category === 'template') {
        return 'desc_compare';
    }
    if ($category === 'tv') {
        return 'desc_compare';
    }
    if ($category === 'chunk') {
        return 'name_compare';
    }
    return 'version_compare';
}

function compare_check($params) {
    $where = array(
        sprintf("`%s`='%s'", key_field($params['category']), $params['name'])
    );
    if($params['category'] === 'plugin') {
        $where[] = " AND `disabled`='0'";
    }
    
    $rs = db()->select('*', "[+prefix+]" . table_name($params['category']), $where);
    if(!$rs) {
        return 'no exists';
    }

    if (mode($params['category']) === 'name_compare') {
        return 'same';
    }

    $row = db()->getRow($rs);

    if (mode($params['category']) === 'version_compare') {
        $old_version = strip_tags(
            substr($row['description'],0,strpos($row['description'],'</strong>'))
        );
        if ($params['version'] === $old_version) {
            return 'same';
        }
        return 'diff';
    }

    if ($params['version']) {
        $new_desc = sprintf('<strong>%s</strong> ', $params['version']). $params['description'];
    } else {
        $new_desc = $params['description'];
    }

    if($row['description'] === $new_desc) {
        return 'same';
    }

    return 'diff';
}

function parse_docblock($fullpath) {
    $params = array();
    if(!is_readable($fullpath)) {
        return false;
    }
    
    $tpl = @fopen($fullpath, 'r');
    if(!$tpl) {
        return false;
    }
    
    $docblock_start_found = false;
    $name_found           = false;
    $description_found    = false;
    
    while(!feof($tpl)) {
        $line = fgets($tpl);
        if (!$docblock_start_found) {    // find docblock start
            if(strpos($line, '/**') !== false) $docblock_start_found = true;
            continue;
        }

        if (!$name_found) {    // find name
            $ma = null;
            if(preg_match("/^\s+\*\s+(.+)/", $line, $ma)) {
                $params['name'] = trim($ma[1]);
                $name_found = !empty($params['name']);
            }
            continue;
        }

        if(!$description_found) {    // find description
            $ma = null;
            if(preg_match("/^\s+\*\s+(.+)/", $line, $ma)) {
                $params['description'] = trim($ma[1]);
                $description_found = !empty($params['description']);
            }
            continue;
        }

        $ma = null;
        if(preg_match("/^\s+\*\s+@([^\s]+)\s+(.+)/", $line, $ma)) {
            $param = trim($ma[1]);
            $val   = trim($ma[2]);
            if(!empty($param) && !empty($val)) {
                if($param === 'internal') {
                    $ma = null;
                    if(preg_match("/@([^\s]+)\s+(.+)/", $val, $ma)) {
                        $param = trim($ma[1]);
                        $val = trim($ma[2]);
                    }
                    if(empty($param)) {
                        continue;
                    }
                }
                $params[$param] = $val;
            }
        } elseif(preg_match("/^\s*\*\/\s*$/", $line)) {
            break;
        }
    }
    @fclose($tpl);
    return $params;
}

function clean_up($sqlParser) {
    $ids = array();

    $table_prefix = $sqlParser->prefix;
    
    // secure web documents - privateweb
    db()->query("UPDATE `" . $table_prefix . "site_content` SET privateweb = 0 WHERE privateweb = 1");
    $sql = "SELECT DISTINCT sc.id 
             FROM `" . $table_prefix . "site_content` sc
             LEFT JOIN `" . $table_prefix . "document_groups` dg ON dg.document = sc.id
             LEFT JOIN `" . $table_prefix . "webgroup_access` wga ON wga.documentgroup = dg.document_group
             WHERE wga.id>0";
    $rs = db()->query($sql);
    if(!$rs) {
        echo sprintf(
            'An error occurred while executing a query: <div>%s</div><div>%s</div>'
            , $sql
            , db()->getLastError()
        );
    } else {
        while($row = db()->getRow($rs)) $ids[]=$row["id"];
        if(count($ids)>0) {
            db()->query(
                sprintf(
                    'UPDATE `%ssite_content` SET privateweb = 1 WHERE id IN (%s)'
                    , $table_prefix
                    , implode(', ', $ids)
                )
            );
            unset($ids);
        }
    }
    
    // secure manager documents privatemgr
    db()->query(sprintf('UPDATE `%ssite_content` SET privatemgr = 0 WHERE privatemgr = 1', $table_prefix));
    $sql = sprintf(
        'SELECT DISTINCT sc.id 
             FROM `%ssite_content` sc
             LEFT JOIN `%sdocument_groups` dg ON dg.document = sc.id
             LEFT JOIN `%smembergroup_access` mga ON mga.documentgroup = dg.document_group
             WHERE mga.id>0'
        , $table_prefix
        , $table_prefix
        , $table_prefix
    );
    $rs = db()->query($sql);
    if(!$rs) {
        echo sprintf(
            'An error occurred while executing a query: <div>%s</div><div>%s</div>'
            , $sql
            , db()->getLastError()
        );
    } else {
        while($row = db()->getRow($rs)) {
            $ids[] = $row['id'];
        }
        
        if(count($ids)>0) {
            $ids = implode(', ',$ids);
            db()->query(
                sprintf(
                    'UPDATE `%ssite_content` SET privatemgr = 1 WHERE id IN (%s)'
                    , $table_prefix
                    , $ids
                )
            );
            unset($ids);
        }
    }
}

// Property Update function
function propUpdate($new,$old) {
    // Split properties up into arrays
    $returnArr = array();
    $newArr = explode('&',$new);
    $oldArr = explode('&',$old);
    
    foreach ($newArr as $k => $v) {
        if($v) {
            $tempArr = explode('=',trim($v));
            $returnArr[$tempArr[0]] = $tempArr[1];
        }
    }
    foreach ($oldArr as $k => $v) {
        if(!empty($v)) {
            $tempArr = explode('=',trim($v));
            $returnArr[$tempArr[0]] = $tempArr[1];
        }
    }
    
    // Make unique array
    $returnArr = array_unique($returnArr);
    
    // Build new string for new properties value
    $return = '';
    foreach ($returnArr as $k => $v) {
        $return .= sprintf('&%s=%s ', $k, $v);
    }
    return db()->escape($return);
}

function getCreateDbCategory($category) {
    if(!$category) {
        return 0;
    }

    $category = db()->escape($category);
    $dbv_category = db()->getObject('categories', "category='" . $category . "'");
    if ($dbv_category) {
        return $dbv_category->id;
    }

    $category_id = db()->insert(array('category' => $category), '[+prefix+]categories');
    if (!$category_id) {
        exit('Get category id error');
    }
    return $category_id;
}

function is_webmatrix() {
    return isset($_SERVER['WEBMATRIXMODE']) ? true : false;
}

function is_iis(){
    return strpos($_SERVER['SERVER_SOFTWARE'],'IIS') ? true : false;
}

function isUpGrade() {
    global $base_path;
    
    $conf_path = $base_path . 'manager/includes/config.inc.php';
    if (!is_file($conf_path)) {
        return 0;
    }
    
    include($conf_path);
    error_reporting(E_ALL & ~E_NOTICE);
    
    if(!isset($dbase) || empty($dbase)) {
        return 0;
    }
    
    db()->hostname     = $database_server;
    db()->username     = $database_user;
    db()->password     = $database_password;
    db()->dbname       = $dbase;
    db()->charset      = $database_connection_charset;
    db()->table_prefix = $table_prefix;
    db()->connect();
    
    if(db()->isConnected() && db()->table_exists('[+prefix+]system_settings')) {
        $collation = db()->getCollation();
        $_SESSION['database_server']            = $database_server;
        $_SESSION['database_user']              = $database_user;
        $_SESSION['database_password']          = $database_password;
        $_SESSION['dbase']                      = trim($dbase,'`');
        $_SESSION['database_charset']           = substr($collation,0,strpos($collation,'_'));
        $_SESSION['database_collation']         = $collation;
        $_SESSION['database_connection_method'] = 'SET CHARACTER SET';
        $_SESSION['table_prefix']               = $table_prefix;
        return 1;
    }
    return 0;
}

function parseProperties($propertyString) {
    if (!$propertyString) {
        return array();
    }

    $tmpParams = explode('&', $propertyString);
    $parameter= array ();
    foreach ($tmpParams as $xValue) {
        if (strpos($xValue, '=', 0)) {
            $pTmp = explode('=', $xValue);
            $pvTmp = explode(';', trim($pTmp[1]));
            if ($pvTmp[1] === 'list' && $pvTmp[3] != '') {
                $parameter[trim($pTmp[0])] = $pvTmp[3]; //list default
            } elseif ($pvTmp[1] !== 'list' && $pvTmp[2] != '') {
                $parameter[trim($pTmp[0])] = $pvTmp[2];
            }
        }
    }
    return $parameter;
}

function result($status='ok',$ph=array()){
    global $modx;
    
    $ph['status'] = $status;
    if ($ph['name']) {
        $ph['name'] = sprintf('&nbsp;&nbsp;%s : ', $ph['name']);
    } else {
        $ph['name'] = '';
    }
    if(!isset($ph['msg'])) {
        $ph['msg'] = '';
    }
    $tpl = '<p>[+name+]<span class="[+status+]">[+msg+]</span></p>';
    return $modx->parseText($tpl,$ph);
}

function get_langs() {
    $langs = array();
    foreach(glob('langs/*.inc.php') as $path) {
        if(substr($path,6,1)==='.') continue;
        $langs[] = substr($path,6,strpos($path,'.inc.php')-6);
    }
    sort($langs);
    return $langs;
}

function get_lang_options($lang_name) {
    $langs = get_langs();
    
    foreach ($langs as $lang) {
        $abrv_language = explode('-',$lang);
        $option[] = sprintf(
            '<option value="%s" %s>%s</option>'
            , $lang
            , $lang == $lang_name ? 'selected="selected"' : ''
            , ucwords($abrv_language[0])
        );
    }
    return "\n" . implode("\n",$option);
}

function collectTpls($path) {
    $files1 = glob($path . '*/*.install_base.tpl');
    $files2 = glob($path . '*.install_base.tpl');
    $files = array_merge((array)$files1,(array)$files2);
    natcasesort($files);
    
    return $files;
}

function ph() {
    global $_lang,$cmsName,$cmsVersion,$modx_textdir,$modx_release_date;

    $ph['pagetitle']     = $_lang['modx_install'];
    $ph['textdir']       = ($modx_textdir && $modx_textdir==='rtl') ? ' id="rtl"':'';
    $ph['help_link']     = $_SESSION['installmode'] == 0 ? $_lang['help_link_new'] : $_lang['help_link_upd'];
    $ph['version']       = $cmsName.' '.$cmsVersion;
    $ph['release_date']  = ($modx_textdir && $modx_textdir==='rtl' ? '&rlm;':'') . $modx_release_date;
    $ph['footer1']       = str_replace('[+year+]', date('Y'), $_lang['modx_footer1']);
    $ph['footer2']       = $_lang['modx_footer2'];
    return $ph;
}

function install_sessionCheck()
{
    $_SESSION['test'] = 1;
    
    if(!isset($_SESSION['test']) || $_SESSION['test']!=1) {
        return false;
    }
    return true;
}

function getLast($array=array()) {
    $array = (array) $array;
    return end($array);
}
