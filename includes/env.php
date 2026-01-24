<?php
//########################################################################################
// Pull all of the global config variables in and set up the initial environment
//########################################################################################


//Get the configuration variables
$envars = parse_ini_file(get_cfg_var("global_conf") . "/conf/global.conf");

//Pass the array back
extract($envars);

//Set root path to app
$cg_confroot = rtrim(get_cfg_var("global_conf"), '/');

//Give a timestamp for body use
$dg_timestamp = time();

//Logged in?
$dg_is_logged_in = FALSE;

//Includes
require_once "$cg_confroot/$cg_includes/util.php";
require_once "$cg_confroot/$cg_includes/auth.php";
require_once "$cg_confroot/$cg_includes/developer.php";
require_once "$cg_confroot/$cg_includes/api.php";
require_once "$cg_confroot/$cg_includes/admin.php";