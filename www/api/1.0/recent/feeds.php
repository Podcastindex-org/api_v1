<? include get_cfg_var("global_conf") . '/includes/env.php'; ?>
<? include "$cg_confroot/$cg_templates/php_rest_init.php" ?>
<?
//loggit(3, "REQUEST: " . print_r($_REQUEST, TRUE));

//Globals
$max = NULL;
$since = NULL;
$language = NULL;
$excludeCategories = NULL;
$includeCategories = NULL;
$excludeString = NULL;
$orderBy = NULL;

//Is there a string query given?
if (isset($_REQUEST['max']) && !empty($_REQUEST['max']) && is_numeric($_REQUEST['max'])) {
    $max = trim($_REQUEST['max']);
}
if (isset($_REQUEST['since']) && !empty($_REQUEST['since']) && is_numeric($_REQUEST['since'])) {
    $since = $_REQUEST['since'];
    if($since < 0) {
        $since = time() - abs($since);
    }
}
if (isset($_REQUEST['lang']) && !empty($_REQUEST['lang'])) {
    $language = array_slice(explode(',', substr(trim($_REQUEST['lang']), 0, 200)), 0, 10);
}
if (isset($_REQUEST['notcat']) && !empty($_REQUEST['notcat'])) {
    $excludeCategories = array_slice(explode(',', substr(trim($_REQUEST['notcat']), 0, 200)), 0, 10);
}
if (isset($_REQUEST['cat']) && !empty($_REQUEST['cat'])) {
    $includeCategories = array_slice(explode(',', substr(trim($_REQUEST['cat']), 0, 200)), 0, 10);
}
if (isset($_REQUEST['sort']) && !empty($_REQUEST['sort'])) {
    $orderBy = strtolower(trim($_REQUEST['sort']));
    if($orderBy != "discovery") {
        $orderBy = NULL;
    }
}


//Do the lookup
$feeds = get_recent_feeds_with_filters($since, $max, $language, $excludeCategories, $includeCategories, $orderBy);
if(empty($feeds)) {
    header('X-PHP-ResponseCode: 201', true, 200);
    $jsondata['status'] = "true";
    $jsondata['feeds'] = array();
    $jsondata['count'] = 0;
    $jsondata['max'] = $max;
    $jsondata['since'] = $since;
    $jsondata['description'] = "No recent feeds found.";
    echo json_encode($jsondata);
    exit(0);
}


//--------------------------------------------------------------------------------
//Return
header('X-PHP-ResponseCode: 201', true, 200);
$jsondata['status'] = "true";
$jsondata['feeds'] = $feeds;
$jsondata['count'] = count($feeds);
$jsondata['max'] = $max;
$jsondata['since'] = $since;
$jsondata['description'] = "Found matching feeds.";
if(isset($_REQUEST['pretty'])) {
    echo json_encode($jsondata, JSON_PRETTY_PRINT);
    return(0);
}
echo json_encode($jsondata);
return (0);