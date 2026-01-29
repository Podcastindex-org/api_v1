<?include get_cfg_var("global_conf").'/includes/env.php';?>
<?include "$cg_confroot/$cg_templates/php_cgi_init.php"?>
<?include "$cg_confroot/$cg_templates/php_cgi_admin.php"?>
<?include "$cg_confroot/$cg_includes/feeds.php"?>
<?

// Json header
header("Cache-control: no-cache, must-revalidate");
header("Content-Type: application/json");
$jsondata = array();

//Get the POST data
if (
    isset($_REQUEST['id'])
    && !empty($_REQUEST['id'])
    && is_numeric($_REQUEST['id'])
    && !empty(get_feed_url($_REQUEST['id']))
) {
    $fid = trim($_REQUEST['id']);
} else {
    loggit(2, "Feed id doesn't appear to be valid.");
    $jsondata['status'] = "false";
    $jsondata['description'] = "Feed id doesn't look valid.";
    echo json_encode($jsondata);
    exit(1);
}

if (
    isset($_REQUEST['url'])
    && !empty($_REQUEST['url'])
    && is_string($_REQUEST['url'])
    && stripos($_REQUEST['url'], 'http') === 0
) {
    $url = trim($_REQUEST['url']);
} else {
    loggit(2, "New feed url doesn't appear to be valid.");
    $jsondata['status'] = "false";
    $jsondata['description'] = "New feed url doesn't look valid.";
    echo json_encode($jsondata);
    exit(1);
}

//Take action
$output = "";
if(get_feed_url($fid) === $url) {
    loggit(2, "The url entered is not different.");
    $jsondata['status'] = "false";
    $jsondata['description'] = "The url entered is not different.";
    echo json_encode($jsondata);
    exit(1);
}

//Here we need to allow for a conflicting feed url that is dead to be switched to _conflict
//automatically so this one can take its place
$existing_id = feed_exists($url);
if(
    $existing_id !== FALSE
    && is_numeric($existing_id)
    && $existing_id !== $fid
) {
    loggit(2, "The url entered already exists.");
    $jsondata['status'] = "false";
    $jsondata['description'] = "The url entered already exists as podcast id: [$existing_id].";
    echo json_encode($jsondata);
    exit(1);
}

//Change the feed url
if(set_feed_url($fid, $url)) {
    loggit(3, "Changed: [$fid] to url: [$url].");
    $output = "Feed url changed successfully.";
} else {
    loggit(2, "Failed to change feed: [$fid] to url: [$url].");
    $jsondata['status'] = "false";
    $jsondata['description'] = "Failed to change feed url.";
    echo json_encode($jsondata);
    exit(1);
}

//Let's pull a fresh copy
mark_feed_as_pullparse_by_id($fid);

//--------------------------------------------------------------------------------
//Give feedback that all went well
$jsondata['status'] = "true";
$jsondata['description'] = $output;
echo json_encode($jsondata);
exit(0);