<?php
//########################################################################################
// API for feed work
//########################################################################################


/**
 * Determine if the provided XML content represents a podcast feed.
 *
 * Parses the XML and returns a feed type identifier based on the presence of podcast-specific elements.
 *
 * @param string|null $content Raw XML content to inspect.
 * @return string|false Returns "rss" for RSS podcast feeds, "atom" for Atom podcast feeds, or false if not a podcast feed.
 */
function is_podcast_feed($content = NULL)
{
    //Check parameters
    if ($content == NULL) {
        loggit(2, "The content to test is blank or corrupt: [$content]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Load the content into a simplexml object
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($x === FALSE) {
        loggit(2, "The content didn't parse correctly: [" . libxml_get_last_error()->message . "]");
        libxml_clear_errors();
        return (FALSE);
    }
    libxml_clear_errors();

    //Look for xml type nodes
    if ((string)$x->getName() == "rss") {
        loggit(1, "Found a channel element. Looks like an RSS feed.");

        //Look for rss nodes
        if (isset($x->channel)) {
            loggit(1, "Found a channel node. This content looks like RSS or RDF.");

            //See if we have any items
            if (isset($x->channel->item[0])) {
                loggit(1, "Found at least one channel item.");

                //See if any enclosures exist in any items
                foreach ($x->channel->item as $entry) {
                    if (isset($entry->enclosure)) {
                        return ("rss");
                    }
                }
            }

            //Any live items? Some feeds only have podcast:liveItem when they "go live" on things like
            //peertube
            if (isset($x->channel->children('podcast', TRUE)->liveItem[0])) {
                return ("rss");
            }
        }
        return (FALSE);

    }
    if ((string)$x->getName() == "feed") {
        loggit(1, "Found a feed element. Looks like an ATOM feed.");

        //Look for atom nodes
        if (isset($x->entry)) {
            loggit(1, "Found and entry node. This content looks like ATOM.");

            //See if any enclosures exist in any items
            foreach ($x->entry as $entry) {
                $mcount = count($entry->link);
                for ($lcount = 0; $lcount < $mcount; $lcount++) {
                    if ($entry->link[$lcount]['rel'] == "enclosure") {
                        return ("atom");
                    }
                }
            }
        }
        return (FALSE);

    }


    //None of the tests passed so return FALSE
    //loggit(3, "The content tested was not an xml-based feed.");
    return (FALSE);
}


/**
 * Determine whether a podcast feed should be considered blocked.
 *
 * Inspects RSS for `itunes:block`, missing/empty title, and spam patterns in the title.
 * Optionally checks the provided URL against a blocklist.
 *
 * @param string|null $content Raw XML content of the feed.
 * @param string|null $url Optional URL of the feed for URL-based block checks.
 * @return bool True if the feed is blocked; false otherwise.
 */
function is_podcast_blocked($content = NULL, $url = NULL)
{
    //Check parameters
    if ($content == NULL) {
        loggit(2, "The content to test is blank or corrupt: [$content]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Load the content into a simplexml object
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    if ($x === FALSE) {
        loggit(2, "The content didn't parse correctly: [" . libxml_get_last_error()->message . "]");
        libxml_clear_errors();
        return (FALSE);
    }
    libxml_clear_errors();

    //Look for xml type nodes
    if ((string)$x->getName() == "rss") {
        loggit(1, "Found a channel element. Looks like an RSS feed.");

        //Look for rss nodes
        if (isset($x->channel)) {
            loggit(1, "Found a channel node. This content looks like RSS or RDF.");

            //Look for an itunes block tag
            $itunes = $x->channel->children('itunes', TRUE);
            $block = (string)$itunes->block;
            if (strtolower(trim($block)) == "yes" || strtolower(trim($block)) == "true") {
                loggit(1, "The itunes:block tag is set to yes.");
                return (TRUE);
            }

            //No valid title
            if (!isset($x->channel->title)
                || empty(trim((string)$x->channel->title))
            ) {
                loggit(2, "Feed has no valid title: [$url].  Blocking.");
                return (TRUE);
            }

            //Spam strings in title
            if (isset($x->channel->title)
                && stripos(strtolower((string)$x->channel->title), "opsssite") !== FALSE
                && stripos(strtolower((string)$x->channel->title), ".com") !== FALSE
                && stripos(strtolower((string)$x->channel->title), "private feed for") !== FALSE
            ) {
                loggit(2, "Spammy string found in feed title of: [$url|" . (string)$x->channel->title . "].  Blocking.");
                return (TRUE);
            }
        }
        return (FALSE);
    }

    //Is this url blocked?
    if (!empty($url)) {
        if (is_url_blocked($url)) {
            return TRUE;
        }
    }

    //None of the tests passed so return FALSE
    //loggit(3, "No itunes:block tag found.");
    return (FALSE);
}


/**
 * Get a list of Apple directory feeds that have canonical feed URLs and are not yet in the newsfeeds table.
 *
 * @param int|null $max Optional maximum number of rows to return.
 * @return array<int,array<string,mixed>> List of feeds with keys like `itunes_id` and `url`.
 */
function apple_get_resolved_feeds($max = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    //$sqltxt = "SELECT itunes_id, feed_url FROM $cg_table_directory_apple WHERE feed_url LIKE 'http%' ORDER BY id ASC";
    $sqltxt = "SELECT apple.itunes_id, 
                      apple.feed_url 
               FROM $cg_table_directory_apple AS apple
               LEFT JOIN $cg_table_newsfeeds AS newsfeeds ON newsfeeds.url = apple.feed_url
               WHERE newsfeeds.url IS NULL 
                 AND LOWER(apple.feed_url) LIKE 'http%'
                 AND apple.dead = 0
                 ORDER BY apple.id ASC";

    //Limits
    if (!empty($max) && is_numeric($max)) {
        $sqltxt .= " LIMIT $max";
    }

    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        loggit(2, "There are no apple directory feeds in the system.");
        return (array());
    }

    $sql->bind_result($itunes_id, $feed_url) or loggit(2, "MySql error: " . $dbh->error);

    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'itunes_id' => $itunes_id,
            'url' => $feed_url
        );
        $count++;
    }

    $sql->close();

    loggit(1, "Returning: [$count] apple directory feeds in the system.");
    return ($feeds);
}


/**
 * Get a list of Apple directory feeds that have canonical feed URLs and are not present in either
 * `newsfeeds.url` or `newsfeeds.original_url`.
 *
 * @param int|null $max Optional maximum number of rows to return.
 * @return array<int,array<string,mixed>> List of feeds with keys like `itunes_id` and `url`.
 */
function apple_get_resolved_feeds2($max = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    //$sqltxt = "SELECT itunes_id, feed_url FROM $cg_table_directory_apple WHERE feed_url LIKE 'http%' ORDER BY id ASC";
    $sqltxt = "SELECT apple.itunes_id,
                      apple.feed_url
               FROM $cg_table_directory_apple AS apple
               LEFT JOIN $cg_table_newsfeeds AS newsfeeds1 ON newsfeeds1.url = apple.feed_url
               LEFT JOIN $cg_table_newsfeeds AS newsfeeds2 ON newsfeeds2.original_url = apple.feed_url
               WHERE
                 newsfeeds1.url IS NULL AND
                 newsfeeds2.url IS NULL
               AND LOWER(apple.feed_url) LIKE 'http%'
               AND apple.dead = 0
                 ORDER BY apple.id ASC";

    //Limits
    if (!empty($max) && is_numeric($max)) {
        $sqltxt .= " LIMIT $max";
    }

    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        loggit(2, "There are no apple directory feeds in the system.");
        return (array());
    }

    $sql->bind_result($itunes_id, $feed_url) or loggit(2, "MySql error: " . $dbh->error);

    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'itunes_id' => $itunes_id,
            'url' => $feed_url
        );
        $count++;
    }

    $sql->close();

    loggit(1, "Returning: [$count] apple directory feeds in the system.");
    return ($feeds);
}


/**
 * Get a list of Apple directory entries that are missing a canonical feed URL.
 *
 * @param int|null $max Optional maximum number of rows to return.
 * @return array<int,int> List of iTunes IDs with no resolved feed URL.
 */
function apple_get_unresolved_feeds($max = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    //$sqltxt = "SELECT itunes_id, feed_url FROM $cg_table_directory_apple WHERE feed_url LIKE 'http%' ORDER BY id ASC";
    $sqltxt = "SELECT apple.itunes_id FROM $cg_table_directory_apple AS apple WHERE apple.feed_url = '' ORDER BY apple.itunes_id ASC";

    //Limits
    if (!empty($max) && is_numeric($max)) {
        $sqltxt .= " LIMIT $max";
    }

    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        loggit(2, "There are no unresolved apple feeds.");
        return (array());
    }

    $sql->bind_result($itunes_id) or loggit(2, "MySql error: " . $dbh->error);

    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = $itunes_id;
        $count++;
    }

    $sql->close();

    loggit(1, "Returning: [$count] unresolved apple feeds.");
    return ($feeds);
}


/**
 * Link an Apple iTunes ID to a feed record.
 *
 * Updates the `newsfeeds` table to set `itunes_id` for the given feed ID.
 *
 * @param int|null $itunes_id The iTunes ID to link.
 * @param int|null $fid The internal feed ID to link to.
 * @return bool True on success; false on invalid input or failure.
 */
function apple_link_feed($itunes_id = NULL, $fid = NULL)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET itunes_id=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $itunes_id, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Linked apple directory feed: [$itunes_id] with feed: [$fid].");

    return (TRUE);
}


/**
 * Link an Apple iTunes ID to a feed record without overwriting an existing link.
 *
 * Only sets `newsfeeds.itunes_id` when it is currently NULL or 0.
 *
 * @param int|null $itunes_id The iTunes ID to link.
 * @param int|null $fid The internal feed ID to link to.
 * @return bool True on success; false on invalid input or failure.
 */
function apple_link_feed_noclobber($itunes_id = NULL, $fid = NULL)
{
    //Check parameters
    if ($itunes_id < 0 || !is_numeric($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (empty($fid) || !is_numeric($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET itunes_id=? WHERE id=? AND (itunes_id IS NULL OR itunes_id=0)";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $itunes_id, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Linked apple directory feed: [$itunes_id] with feed: [$fid].");

    return (TRUE);
}


/**
 * Update the canonical feed URL for a given Apple iTunes ID.
 *
 * Writes the provided URL into the Apple directory table for the specified iTunes ID.
 *
 * @param int|null $itunes_id The iTunes ID to update.
 * @param string|null $url The canonical feed URL to set.
 * @return bool True on success; false on invalid input or failure.
 */
function apple_update_feed_url_by_itunes_id($itunes_id = NULL, $url = NULL)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (empty($url)) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_directory_apple 
             SET feed_url=? 
             WHERE itunes_id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $url, $itunes_id) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Added url: [$url] for apple itunes id: [$itunes_id].");

    return (TRUE);
}


/**
 * Add a podcast entry to the Apple directory table.
 *
 * Inserts a new Apple directory row using the provided iTunes ID, title, and iTunes URL.
 *
 * @param int|null $itunes_id The Apple iTunes ID for the podcast.
 * @param string|null $title The podcast title/description.
 * @param string|null $itunes_url The iTunes page URL for the podcast.
 * @return bool True on success; false on invalid input or failure.
 */
function apple_add_feed($itunes_id = NULL, $title = NULL, $itunes_url = NULL)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (empty($title)) {
        loggit(2, "The itunes title is blank or corrupt: [$title]");
        return (FALSE);
    }
    if (empty($itunes_url)) {
        loggit(2, "The itunes url is blank or corrupt: [$itunes_url]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "INSERT INTO $cg_table_directory_apple (description, itunes_id, itunes_url, time_createdon) VALUES (?,?,?,UNIX_TIMESTAMP(NOW()))";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sds", $title, $itunes_id, $itunes_url) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //Get the last inserted id
    if ($sqlres === TRUE) {
        $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);;
    } else {
        //Close and exit
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        //loggit(3, "Failed to add feed with itunes id: [$itunes_id] to apple directory table.");
        return (FALSE);
    }
    $fid = $last_id;

    $sql->close();

    //Log and leave
    //loggit(3, "Added feed with itunes id: [$itunes_id] as feed: [$fid] to apple directory table.");

    return (TRUE);
}


/**
 * Add a podcast to the directory and include Apple/iTunes metadata.
 *
 * Inserts a new Apple directory entry with iTunes ID, title, iTunes URL, feed URL, and artwork URL.
 *
 * @param string|null $feed_url The canonical feed URL.
 * @param string|null $title The podcast title/description.
 * @param int|null $itunes_id The Apple iTunes ID.
 * @param string|null $itunes_url The iTunes page URL for the podcast.
 * @param string|null $itunes_image The artwork URL (e.g., 600x600).
 * @return bool True on success; false on invalid input or failure.
 */
function add_feed_with_itunes_info($feed_url = NULL, $title = NULL, $itunes_id = NULL, $itunes_url = NULL, $itunes_image = NULL)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (empty($feed_url)) {
        loggit(2, "The feed url is blank or corrupt: [$feed_url_url]");
        return (FALSE);
    }
    if (empty($title)) {
        $title = "";
        return (FALSE);
    }
    if (empty($itunes_url)) {
        $itunes_url = "";
        return (FALSE);
    }
    if (empty($itunes_image)) {
        $itunes_image = "";
        return (FALSE);
    }


    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "INSERT INTO $cg_table_directory_apple (itunes_id, description, itunes_url, feed_url, artwork_url_600, time_createdon) VALUES (?,?,?,?,?,UNIX_TIMESTAMP(NOW()))";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dssss", $itunes_id, $title, $itunes_url, $feed_url, $itunes_image) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //Get the last inserted id
    if ($sqlres === TRUE) {
        $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);;
    } else {
        //Close and exit
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        //loggit(3, "Failed to add feed with itunes id: [$itunes_id] to apple directory table.");
        return (FALSE);
    }
    $fid = $last_id;

    $sql->close();

    //Log and leave
    //loggit(3, "Added feed with itunes id: [$itunes_id|$feed_url] as feed: [$fid] to apple directory table.");

    return (TRUE);
}


/**
 * Add an Apple iTunes ID to the Apple directory table without other metadata.
 *
 * Inserts a minimal row containing only the iTunes ID (title and URL blank).
 *
 * @param int|null $itunes_id The Apple iTunes ID to add.
 * @return bool|int True on success; -1062 if the ID already exists; false on failure.
 */
function apple_add_itunes_id($itunes_id = NULL)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    $title = "";
    $itunes_url = "";

    //Build the query
    $stmt = "INSERT INTO $cg_table_directory_apple 
             (description, itunes_id, itunes_url, time_createdon) 
             VALUES (?,?,?,UNIX_TIMESTAMP(NOW()))";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sds", $title, $itunes_id, $itunes_url) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //Get the last inserted id
    if ($sqlres === TRUE) {
        $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);
    } else {
        if ($sql->errno === 1062) {
            //Close and exit
            $sql->close() or loggit(2, "MySql error: " . $dbh->error);
            //loggit(3, "Itunes ID: [$itunes_id] already exists.");
            return (-1062);
        }

        //Close and exit
        $sql->close() or loggit(2, "MySql error: " . $dbh->error);
        //loggit(3, "Failed to add feed with itunes id: [$itunes_id] to apple directory table.");
        return (FALSE);
    }
    $fid = $last_id;

    $sql->close();

    //Log and leave
    //loggit(3, "Added feed with itunes id: [$itunes_id] as feed: [$fid] to apple directory table.");

    return (TRUE);
}


/**
 * Check if a feed URL already exists in the Apple directory table.
 *
 * @param string|null $url The canonical feed URL to check.
 * @return int|false|null Returns the iTunes ID if found; false if not found; null on invalid input.
 */
function apple_feed_exists($url = NULL)
{
    //Check parameters
    if (empty($url)) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT itunes_id FROM $cg_table_directory_apple WHERE feed_url=?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $url) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "The feed at url: [$url] does not exist in the apple directory.");
        return (FALSE);
    }
    $sql->bind_result($iid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Make sure what we're going to return looks alright
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "Something went wrong looking up an apple feed: [$url].");
        return (NULL);
    }

    //Log and leave
    loggit(1, "The itunes id: [$iid] at url: [$url] is already in the apple directory.");
    return ($iid);
}


/**
 * Check if an Apple directory iTunes ID is marked as dead.
 *
 * @param int|null $iid The iTunes ID to check.
 * @return bool|null True if marked dead; false if not; null on invalid input.
 */
function apple_feed_is_dead($iid = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The itunes id is blank or corrupt: [$iid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT id FROM $cg_table_directory_apple WHERE itunes_id=? AND dead=1") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $iid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() > 0) {
        $sql->close();
        //loggit(3, "The itunes id: [$iid] exists, but is marked as dead.");
        return (TRUE);
    }
    $sql->close();

    //Log and leave
    loggit(1, "The itunes id: [$iid] is in the apple directory and not marked dead.");
    return (FALSE);
}


/**
 * Check if an Apple directory iTunes ID exists in the Apple table.
 *
 * @param int|null $iid The iTunes ID to check.
 * @return bool|null True if it exists; false if not; null on invalid input.
 */
function apple_feed_exists_by_itunes_id($iid = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The itunes id is blank or corrupt: [$iid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT id FROM $cg_table_directory_apple WHERE itunes_id=?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $iid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() > 0) {
        $sql->close();
        //loggit(3, "The itunes id: [$iid] exists in the apple table.");
        return (TRUE);
    }
    $sql->close();

    //Log and leave
    loggit(1, "The itunes id: [$iid] is NOT in the apple table.");
    return (FALSE);
}


/**
 * Find a feed by cross-referencing an Apple iTunes ID with the newsfeeds table.
 *
 * Joins the Apple directory and newsfeeds tables to locate the most recent feed
 * that matches the provided iTunes ID.
 *
 * @param int|null $iid The Apple iTunes ID to look up.
 * @return array<string,mixed>|array Returns an associative array with keys like `id`, `title`, `url`,
 *                                  or an empty array if no match is found.
 */
function get_feed_by_apple_itunes_id_cross_reference($iid = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The itunes id is blank or corrupt: [$iid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT 
           feeds.id,
           feeds.title,
           feeds.url 
        FROM directory_apple AS apple 
        INNER JOIN newsfeeds AS feeds ON feeds.url = apple.feed_url 
        WHERE apple.itunes_id = ?
        ORDER BY feeds.id DESC 
        LIMIT 1
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $iid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //See if any rows came back
    if ($sql->num_rows() == 0) {
        $sql->close();
        //loggit(3, "No feeds cross-reference with this itunes id in the apple table.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    loggit(1, "The itunes id: [$iid] cross-references with another itunes id.");
    return ($feeds[0]);
}


/**
 * Add a feed to the repository.
 *
 * Inserts a new row into `newsfeeds` if the URL does not already exist. If the feed
 * already exists, returns true without modifying it. When a new feed is added, a
 * GUID is generated and stored.
 *
 * @param string|null $url The feed URL to add.
 * @param string $content Optional raw content to store with the feed (trimmed).
 * @param string $title Optional title to store with the feed (trimmed).
 * @param int $source Optional source flag or identifier for auditing.
 * @return int|bool Returns the feed ID on insert; true if it already existed; false on failure.
 */
function add_feed($url = NULL, $content = '', $title = '', $source = 0)
{
    //Check parameters
    if (empty($url)) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Keep a copy of the original url in case we get a redirect in the future
    $original_url = $url;

    $updated = 0;
    $content = trim($content);
    if (!empty($content)) {
        $updated = 1;
    }
    $title = trim($title);

    //Timestamp
    $createdon = time();


    //Does this feed exist already?
    $last_id = FALSE;
    $existed = FALSE;
    $fid = feed_exists($url);
    if ($fid === NULL) {
        loggit(2, "Something went wrong adding the newsfeed at url: [$url]. feed_exists() returned NULL.");
        return (FALSE);
    } else
        if ($fid === FALSE) {
            $stmt = "INSERT INTO $cg_table_newsfeeds (url,createdon,title,content,original_url,description, pullnow, parsenow, updated) VALUES (?,?,?,?,?,'',1,1,?)";
            $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
            $sql->bind_param("sdsssd", $url, $createdon, $title, $content, $original_url, $updated) or loggit(2, "MySql error: " . $dbh->error);
            $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

            //Get the last inserted id
            if ($sqlres === TRUE) {
                $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);;
            } else {
                //Close and exit
                $sql->close() or loggit(2, "MySql error: " . $dbh->error);
                //loggit(3, "Failed to add feed with url: [$url].");
                return (FALSE);
            }
            $fid = $last_id;

            $sql->close();
        } else {
            $existed = TRUE;
            return (TRUE);
        }

    //Log and leave
    if ($existed == TRUE) {
        //loggit(3, "Feed: [$fid] with url [$url] already existed in the database.");
    } else {
        //loggit(3, "Added a new feed in the repository: [$fid] with url [$url].");
        if (!empty($fid) && is_numeric($fid)) {
            $newguid = create_podcast_guid($url);
            set_feed_guid($fid, $newguid);
        }
    }
    return ($fid);
}


/**
 * Add a feed to the repository by its URL.
 *
 * Checks whether the feed already exists (tolerating protocol and trailing-slash
 * variations). If it does not exist, inserts a new row into the `newsfeeds`
 * table and initializes identifiers. Returns the feed ID either way when
 * successful.
 *
 * @param string|null $url Absolute feed URL (http/https).
 * @return int|false Feed ID on success (including when it already existed); false on failure.
 */
function add_feed_by_url($url = NULL)
{
    //Check parameters
    if (empty($url)) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (FALSE);
    }
    $url = trim($url);

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Keep a copy of the original url in case we get a redirect in the future
    $original_url = $url;

    //Timestamp
    $createdon = time();
    $title = "";
    $content = "";

    //Does this feed exist already?
    $fid = feed_exists($url);

    //Feed already existed
    if (!empty($fid) && is_numeric($fid) && $fid > 0) {
        //loggit(3, "Feed: [$fid] with url [$url] already existed in the database.");
        return ($fid);
    }

    //Feed didn't exist so insert it
    if ($fid === FALSE) {
        $stmt = "INSERT INTO $cg_table_newsfeeds (url,createdon,title,content,original_url,description, pullnow, parsenow, updated) VALUES (?,?,'','',?,'',1,1,0)";
        $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
        $sql->bind_param("sdsssd", $url, $createdon, $original_url) or loggit(2, "MySql error: " . $dbh->error);
        $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

        //Get the last inserted id
        if ($sqlres === TRUE) {
            $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);;
        } else {
            //Close and exit
            $sql->close() or loggit(2, "MySql error: " . $dbh->error);
            //loggit(3, "Failed to add feed with url: [$url].");
            return (FALSE);
        }
        $fid = $last_id;

        $sql->close();
    }

    //Error
    if ($fid === NULL) {
        loggit(2, "Something went wrong adding the newsfeed at url: [$url]. feed_exists() returned NULL.");
        return (FALSE);
    }

    //Feed was new, so create a GUID
    if (!empty($fid) && is_numeric($fid)) {
        $newguid = create_podcast_guid($url);
        set_feed_guid($fid, $newguid);
    }

    //Log and leave
    //loggit(3, "Added a new feed: [$fid] to the index with url [$url].");
    return ($fid);
}


/**
 * Check if a feed URL already exists in the repository.
 *
 * Performs a flexible lookup that accounts for minor URL variations (trailing slash
 * and protocol changes between HTTP/HTTPS) across both `url` and `original_url`.
 *
 * @param string|null $url The feed URL to check (must start with http/https).
 * @return int|false|null Feed ID if found; false if not found; null on invalid input or error.
 */
function feed_exists($url = NULL)
{
    //Check parameters
    if (empty($url) || stripos($url, 'http') !== 0) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Modified versions
    $url = trim($url);
    $url_noslash = rtrim($url, '/');
    $url_slash = $url_noslash . "/";
    $url_slash_http = str_ireplace('https', 'http', $url_slash);
    $url_noslash_http = str_ireplace('https', 'http', $url_noslash);
    $url_slash_https = str_ireplace('http', 'https', $url_slash_http);
    $url_noslash_https = str_ireplace('http', 'https', $url_noslash_http);

    //loggit(3, "Checking for: [$url|$url_slash|$url_noslash|$url_slash_http|$url_noslash_http|$url_slash_https|$url_noslash_https]");

    //Look for the url in the feed table
    $stmt = "
            SELECT id 
            FROM $cg_table_newsfeeds 
            WHERE url=? OR url=? OR url=? OR url=? OR url=? 
               OR original_url=? OR original_url=? OR original_url=? OR original_url=? OR original_url=?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ssssssssss",
        $url,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https,
        $url,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https
    ) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "The feed at url: [$url] does not exist in the repository.");
        return (FALSE);
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Make sure what we're going to return looks alright
    if (empty($fid)) {
        loggit(2, "Something went wrong looking up a newsfeed url.");
        return (NULL);
    }

    //Log and leave
    loggit(1, "The feed: [$fid] at url: [$url] is already in the repository.");
    return ($fid);
}


/**
 * Get the developer ID that submitted a feed to the index.
 *
 * Looks up the `feeds_added` record linked to the feed found by URL (handling common
 * URL variations) and returns the associated `developerid`.
 *
 * @param string|null $url The feed URL to look up (must start with http/https).
 * @return int|false|null Developer ID on success; false if no record; null on invalid input.
 */
function get_feed_submitter_id($url = NULL)
{
    //Check parameters
    if (empty($url) || stripos($url, 'http') !== 0) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Modified versions
    $url = trim($url);
    $url_noslash = rtrim($url, '/');
    $url_slash = $url_noslash . "/";
    $url_slash_http = str_ireplace('https', 'http', $url_slash);
    $url_noslash_http = str_ireplace('https', 'http', $url_noslash);
    $url_slash_https = str_ireplace('http', 'https', $url_slash_http);
    $url_noslash_https = str_ireplace('http', 'https', $url_noslash_http);

    //loggit(3, "Checking for: [$url|$url_slash|$url_noslash|$url_slash_http|$url_noslash_http|$url_slash_https|$url_noslash_https]");

    //Look for the url in the feed table
    $stmt = "
            SELECT added.developerid,
                   feeds.id
            FROM $cg_table_newsfeeds AS feeds
            LEFT JOIN feeds_added AS added ON added.feedid = feeds.id 
            WHERE url=? OR url=? OR url=? OR url=? OR url=? 
               OR original_url=? OR original_url=? OR original_url=? OR original_url=? OR original_url=?
            LIMIT 1
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ssssssssss",
        $url,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https,
        $url,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https
    ) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "The feed at url: [$url] does not exist in the repository.");
        return (FALSE);
    }
    $sql->bind_result($did, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Make sure what we're going to return looks alright
    if (empty($fid)) {
        loggit(2, "The feed id is empty.");
        return (NULL);
    }
    if (empty($did)) {
        loggit(2, "The developer id is empty.");
        return (NULL);
    }


    //Log and leave
    loggit(1, "The feed: [$fid] at url: [$url] was submitted by: [$did].");
    return ($did);
}


/**
 * Check if a feed exists by URL or content hash.
 *
 * Searches for an existing feed by common URL variants (trailing slash, http/https)
 * or by the provided `chash` content hash.
 *
 * @param string|null $url The feed URL to check.
 * @param string|null $chash The content hash to check.
 * @return int|false|null Feed ID if found; false if not found; null on invalid input or error.
 */
function feed_exists_with_chash($url = NULL, $chash = NULL)
{
    //Check parameters
    if (empty($url) || stripos($url, 'http') !== 0) {
        loggit(2, "The feed url is blank or corrupt: [$url]");
        return (NULL);
    }
    if (empty($chash)) {
        loggit(2, "The feed chash is blank or corrupt: [$chash]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Modified versions
    $url = trim($url);
    $url_noslash = rtrim($url, '/');
    $url_slash = $url_noslash . "/";
    $url_slash_http = str_ireplace('https', 'http', $url_slash);
    $url_noslash_http = str_ireplace('https', 'http', $url_noslash);
    $url_slash_https = str_ireplace('http', 'https', $url_slash_http);
    $url_noslash_https = str_ireplace('http', 'https', $url_noslash_http);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT id FROM $cg_table_newsfeeds WHERE url=? OR url=? OR url=? OR url=? OR original_url=? OR original_url=? OR original_url=? OR original_url=? OR chash=?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sssssssss",
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https,
        $chash
    ) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "The feed at url: [$url] does not exist in the repository.");
        return (FALSE);
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Make sure what we're going to return looks alright
    if (empty($fid)) {
        loggit(2, "Something went wrong looking up a newsfeed url.");
        return (NULL);
    }

    //Log and leave
    loggit(1, "The feed: [$fid] at url: [$url] is already in the repository.");
    return ($fid);
}


/**
 * Check if a feed exists by its Apple iTunes ID.
 *
 * Looks in the `newsfeeds` table for a row where `itunes_id` matches.
 *
 * @param int|null $itunes_id The Apple iTunes ID to check.
 * @return int|false|null Feed ID if found; false if not found; null on invalid input.
 */
function feed_exists_by_itunes_id($itunes_id = NULL)
{
    //Check parameters
    if (empty($itunes_id) || !is_numeric($itunes_id)) {
        loggit(2, "The itunes id is blank or corrupt: [$itunes_id]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);


    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT id FROM $cg_table_newsfeeds WHERE itunes_id=?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $itunes_id) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exists with itunes id: [$itunes_id]");
        return (FALSE);
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Make sure what we're going to return looks alright
    if (empty($fid)) {
        loggit(2, "Something went wrong looking up a newsfeed by itunes id.");
        return (NULL);
    }

    //Log and leave
    loggit(1, "The feed: [$fid] with itunes id: [$itunes_id] exists.");
    return ($fid);
}


/**
 * Search feeds by a term in their title.
 *
 * Performs a simple LIKE search on `newsfeeds.title`, returning a limited set of
 * metadata for each match ordered by most recently updated.
 *
 * @param string|null $q The search term to match within titles.
 * @return array<int,array<string,mixed>> List of matching feed rows; empty array when none match.
 */
function search_feeds_by_term($q = NULL)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator
        FROM $cg_table_newsfeeds
        WHERE newsfeeds.title LIKE CONCAT('%',?,'%') 
        ORDER BY newsfeeds.lastupdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $q) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist that match search term: [$q].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'link' => $flink,
            'lastupdate' => $flastupdate,
            'errors' => $ferrors,
            'lasthttpstatus' => $flasthttpstatus,
            'contenttype' => $fcontenttype,
            'itunes_id' => $fitunesid,
            'artwork' => $fartwork,
            'description' => limit_words(strip_tags($fdescription), 100, TRUE),
            'image' => $fimage,
            'type' => $ftype,
            'generator' => $fgenerator
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match search term: [$q].");
    return ($feeds);
}


/**
 * Search feeds using Sphinx full-text index.
 *
 * Queries a Sphinx index for matching feeds and returns a list of feed IDs,
 * ordered by popularity. Can optionally restrict to Apple-only index.
 *
 * @param string|null $q The search term.
 * @param int|null $max Maximum number of IDs to return.
 * @param bool $apple_only Whether to use the Apple-only index.
 * @return array<int,int> List of matching feed IDs; empty array when none match.
 */
function search_feeds_by_term_using_sphinx($q = NULL, $max = NULL, $apple_only = FALSE)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost_search, '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    if (empty($max) || !is_numeric($max) || $max > 99) {
        $max = $cg_default_max_search_results;
    }

    $fids = array();
    $count = 0;
    $index = "test1";
    if ($apple_only) {
        $index = "test2";
    }

    //Do the query
    if ($result = $dbh->query("SELECT id FROM $index WHERE MATCH('" . $clean_query . "') ORDER BY popularity DESC LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $fids[] = $row[0];
            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No feeds exist that match search term: [$q].");
        $result->close();
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] feed id's from sphinx that match search term: [$q].");
    return ($fids);
}


/**
 * Search music feeds using Sphinx full-text index.
 *
 * Queries the music-focused Sphinx index and returns matching feed IDs ordered
 * by popularity.
 *
 * @param string|null $q The search term.
 * @param int|null $max Maximum number of IDs to return.
 * @return array<int,int> List of matching feed IDs; empty array when none match.
 */
function search_music_feeds_by_term_using_sphinx($q = NULL, $max = NULL)
{
    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost_search, '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    if (empty($max) || !is_numeric($max) || $max > 99) {
        $max = $cg_default_max_search_results;
    }

    $fids = array();
    $count = 0;
    $index = "test3";

    //Do the query
    if ($result = $dbh->query("SELECT id FROM $index WHERE MATCH('" . $clean_query . "') ORDER BY popularity DESC LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $fids[] = $row[0];
            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No music feeds exist that match search term: [$q].");
        $result->close();
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] music feed id's from sphinx that match search term: [$q].");
    return ($fids);
}


/**
 * Search value-enabled music feeds using Sphinx.
 *
 * Queries a Sphinx index for feeds that match the term and are of the specified
 * `type`, returning feed IDs ordered by popularity.
 *
 * @param string|null $q The search term.
 * @param int $type The value type to filter by.
 * @param int|null $max Maximum number of IDs to return.
 * @return array<int,int> List of matching feed IDs; empty array when none match.
 */
function search_music_value_feeds_by_term_using_sphinx($q = NULL, $type = 0, $max = NULL)
{
    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost_valuesearch, '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    if (empty($max) || !is_numeric($max) || $max > 99) {
        $max = $cg_default_max_search_results;
    }

    $fids = array();
    $count = 0;

    //Do the query
    if ($result = $dbh->query("SELECT id FROM test2 WHERE MATCH('" . $clean_query . "') AND `type` = $type ORDER BY popularity DESC LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $fids[] = $row[0];
            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No feeds exist that match value search term: [$q].");
        $result->close();
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] feed id's from sphinx value that match search term: [$q].");
    return ($fids);
}


/**
 * Search feeds by exact title using SQL.
 *
 * Returns feed IDs whose `title` matches the provided string pattern (using SQL LIKE),
 * limited and ordered by popularity and newest item date.
 *
 * @param string|null $q The title or pattern to search for.
 * @param int|null $max Maximum number of IDs to return.
 * @return array<int,int> List of matching feed IDs; empty array when none match.
 */
function search_feeds_by_title($q = NULL, $max = NULL)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }
    if (empty($max) || !is_numeric($max) || $max > 99) {
        $max = $cg_default_max_search_results;
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Vars
    $fids = array();
    $title = trim($q);
    $count = 0;

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.title LIKE ? 
        AND dead = 0
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.popularity DESC, newsfeeds.newest_item_pubdate DESC
        LIMIT ?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $q, $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that exact title: [$q].");
        return (array());
    }
    $sql->bind_result(
        $fid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $count = 0;
    while ($sql->fetch()) {
        $fids[] = $fid;

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feed id's that match title: [$q].");
    return ($fids);
}


/**
 * Search value-enabled feeds using Sphinx.
 *
 * Queries a Sphinx index for feeds matching the term and filtered by the given `type`,
 * returning feed IDs ordered by popularity.
 *
 * @param string|null $q The search term.
 * @param int $type The value type to filter by.
 * @param int|null $max Maximum number of IDs to return.
 * @return array<int,int> List of matching feed IDs; empty array when none match.
 */
function search_value_feeds_by_term_using_sphinx($q = NULL, $type = 0, $max = NULL)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost_valuesearch, '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    if (empty($max) || !is_numeric($max) || $max > 99) {
        $max = $cg_default_max_search_results;
    }

    $fids = array();
    $count = 0;

    //Do the query
    if ($result = $dbh->query("SELECT id FROM test1 WHERE MATCH('" . $clean_query . "') AND `type` = $type ORDER BY popularity DESC LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $fids[] = $row[0];
            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No feeds exist that match value search term: [$q].");
        $result->close();
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] feed id's from sphinx value that match search term: [$q].");
    return ($fids);
}


/**
 * Searches episodes' metadata using Sphinx and returns matching episode IDs.
 *
 * @param string|null $q Search term.
 * @param int|null $max Maximum number of results to return.
 * @return array<int> List of episode IDs.
 */
function search_episodes_by_term_using_sphinx($q = NULL, $max = NULL)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli('192.168.172.61', '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    loggit(3, "CLEAN QUERY (PERSON): $clean_query");

    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 100) {
        $max = 100;
    }

    $iids = array();
    $count = 0;

    //Do the query
    if ($result = $dbh->query("SELECT id FROM test1 WHERE MATCH('" . $clean_query . "') LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $iids[] = $row[0];
            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No episodes exist that match search term: [$q].");
        $result->close();
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] episode id's from sphinx that match search term: [$q].");
    return ($iids);
}


/**
 * Retrieves feeds by a list of feed IDs.
 *
 * @param array<int> $fids Feed IDs to fetch.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $max Maximum number of feeds to return.
 * @param bool $withexplicit Include explicit feeds when true.
 * @return array<int, array<string, mixed>> List of feeds keyed sequentially.
 */
function get_feeds_by_id_array($fids = array(), $fulltext = FALSE, $max = NULL, $withexplicit = TRUE)
{
    //Check parameters
    if (empty($fids)) {
        loggit(2, "The feed id array argument is blank.");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Assemble the feed id list to get
    $assembled_feed_list = "";
    $count = 0;
    foreach ($fids as $fid) {
        if ($count == 0) {
            $assembled_feed_list .= " ( ";
        }
        $assembled_feed_list .= " newsfeeds.id = $fid ";
        if (isset($fids[$count + 1])) {
            $assembled_feed_list .= "OR ";
        }
        $count++;
    }
    $assembled_feed_list .= " ) ";

    if (!$withexplicit) {
        $assembled_feed_list .= " AND explicit = 0 ";
    }

    if (empty($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 2000) {
        $max = 2000;
    }

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.parsenow,
          newsfeeds.priority,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.explicit,
          guids.guid,
          mediums.medium,
          newsfeeds.item_count,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash      
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
         LEFT JOIN nfmediums AS mediums ON mediums.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
        WHERE $assembled_feed_list AND dead=0
        GROUP BY newsfeeds.id
        ORDER BY popularity DESC, newest_item_pubdate DESC
        LIMIT ?
    ";
    //loggit(3, $stmt);
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "Could not retrieve feeds for search result lookup.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fparsenow,
        $fpriority,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fexplicit,
        $fguid,
        $fmedium,
        $fitemcount,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!empty($fduplicateof)) {
            if (in_array($fduplicateof, $fids)) {
                continue;
            }
        }
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $explicit = FALSE;
        if ($fexplicit == 1) $explicit = TRUE;
        if (empty($fmedium)) $fmedium = "podcast";
        if (empty($categories)) $categories = NULL;
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'inPollingQueue' => $fparsenow,
            'priority' => $fpriority,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'explicit' => $explicit,
            'podcastGuid' => $fguid,
            'medium' => $fmedium,
            'episodeCount' => $fitemcount,
            'imageUrlHash' => $fimageurlhash,
            'newestItemPubdate' => $fnewestitemdate
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match the feed id array.");
    return ($feeds);
}


/**
 * Retrieves episodes by a list of item IDs.
 *
 * @param array<int> $iids Item (episode) IDs to fetch.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $max Maximum number of episodes to return.
 * @return array<int, array<string, mixed>> List of episodes keyed sequentially.
 */
function get_episodes_by_id_array($iids = array(), $fulltext = FALSE, $max = NULL)
{
    //Check parameters
    if (empty($iids)) {
        loggit(2, "The episode id array argument is blank.");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Assemble the feed id list to get
    $assembled_item_list = "";
    $count = 0;
    foreach ($iids as $iid) {
        $assembled_item_list .= " items.id = $iid ";
        if (isset($iids[$count + 1])) {
            $assembled_item_list .= "OR ";
        }
        $count++;
    }

    if (empty($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 100) {
        $max = 100;
    }

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,          
          feeds.image,
          items.image,
          feeds.language,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          feeds.itunes_author,
          feeds.title,
          feeds.url,
          items.itunes_duration          
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
        WHERE $assembled_item_list       
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
    //loggit(3, $stmt);
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "Could not retrieve feeds for search result lookup.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $fitunesid,
        $fimage,
        $iimage,
        $flanguage,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $fauthor,
        $ftitle,
        $furl,
        $iduration
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $transcripts = [];
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'feedUrl' => $furl,
                'feedAuthor' => $fauthor,
                'feedTitle' => $ftitle,
                'feedLanguage' => $flanguage,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            $items[$iid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$iid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    //loggit(3, "Returning: [$count] items asked for in array.");
    return ($episodes);
}


/**
 * Updates the title of a feed.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $title New title.
 * @return bool True on success, false on failure.
 */
function update_feed_title($fid = NULL, $title = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($title)) {
        loggit(2, "The feed title argument is blank or corrupt: [$title]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the feed title
    $title = trim($title);

    //Build and execute the database query
    $stmt = "UPDATE $table_newsfeeds SET title=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $title, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed:[$fid]'s title to: [$title].");
    return (TRUE);
}


/**
 * Updates the artwork URL of a feed for a given size.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $url Artwork URL.
 * @param int $size Artwork size selector (e.g., 600, 100, 60, 30).
 * @return bool True on success, false on failure.
 */
function update_feed_art_url($fid = NULL, $url = NULL, $size = 600)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($url)) {
        loggit(2, "The album art url argument is blank or corrupt: [$url]");
        return (FALSE);
    }
    if (empty($url)) {
        loggit(2, "The album art url argument is blank or corrupt: [$url]");
        return (FALSE);
    }

    //Which size do we want?
    $sizeField = "artwork_url_600";
    if ($size == 100) {
        $sizeField = "artwork_url_100";
    } else if ($size == 60) {
        $sizeField = "artwork_url_60";
    } else if ($size == 30) {
        $sizeField = "artwork_url_30";
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the feed title
    $title = trim($title);

    //Build and execute the database query
    $stmt = "UPDATE $table_newsfeeds SET $sizeField=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $url, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed:[$fid]'s artwork url to: [$url].");
    return (TRUE);
}


/**
 * Retrieves items (episodes) for a given feed ID.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_feed_id($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "d";
    $msb_params[] = $fid;

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //Look for the url in the feed table
    $sql = $dbh->prepare("
        SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,
          feeds.image,
          items.image,
          feeds.language,
          chapters.url,
          transcripts.url
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid 
        WHERE items.feedid = ? 
         AND items.timestamp < $nowtime
         $since_clause
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $fitunesid,
        $fimage,
        $iimage,
        $flanguage,
        $ichapters,
        $itranscripts
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => $idescription,
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iepisodeseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedLanguage' => $flanguage,
            'chaptersUrl' => $ichapters,
            'transcriptUrl' => $itranscripts
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] items for feed: [$fid].");
    return ($items);
}


/**
 * Retrieves items (episodes) for a given iTunes ID.
 *
 * @param int|null $iid iTunes ID of the feed.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_itunes_id($iid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($iid)) {
        loggit(2, "The itunes id is blank or corrupt: [$iid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "d";
    $msb_params[] = $iid;

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.image,
          items.image,
          feeds.id,
          feeds.language,
          chapters.url,
          transcripts.url,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid 
        WHERE feeds.itunes_id = ?
         AND items.timestamp < $nowtime
         $since_clause
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, $msb_types);
//    loggit(3, print_r($msb_params, TRUE));
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No items exist for itunes id: [$iid].");
        return (array());
    }
    $sql->bind_result(
        $itemid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $fimage,
        $iimage,
        $fid,
        $flanguage,
        $ichapters,
        $itranscript,
        $isbstarttime,
        $isbduration,
        $isbtitle
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$itemid])) {
            $items[$itemid] = array(
                'id' => $itemid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedId' => $fid,
                'feedLanguage' => $flanguage,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscript
            );
        }
        if (!empty($isbstarttime) && !empty($isbduration)) {
            $items[$itemid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$itemid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    foreach ($items as $item) {
        $episodes[] = $item;
    }

    //Log and leave
    //loggit(3, "Returning: [$count] items for itunes id: [$iid].");
    return ($episodes);
}


/**
 * Retrieves the newest episodes across feeds.
 *
 * @param int|null $max Maximum number of episodes to return.
 * @param string $exclude_string Exclude items whose fields contain this substring.
 * @param bool $exclude_blanks Exclude items missing key fields when true.
 * @param int|null $before_id Return items with ID less than this value.
 * @return array<int, array<string, mixed>> List of recent episodes.
 */
function get_recent_episodes($max = NULL, $exclude_string = '', $exclude_blanks = FALSE, $before_id = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Check parameters
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 1000) {
        $max = 1000;
    }

    $nowtime = time() - 1;
    $yesterday = $nowtime - 86400;
    $limit = ($max + 50);

    $before_clause = "";
    if (!empty($before_id) && is_numeric($before_id)) {
        $before_clause = "AND items.id < ? ";
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage
        FROM $cg_table_newsfeed_items AS items
        JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
        WHERE items.timestamp < $nowtime
          $before_clause
        ORDER BY items.timestamp DESC
        LIMIT ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    if (!empty($before_id) && is_numeric($before_id)) {
        $sql->bind_param("dd", $before_id, $limit) or loggit(2, "MySql error: " . $dbh->error);
    } else {
        $sql->bind_param("d", $limit) or loggit(2, "MySql error: " . $dbh->error);
    }
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feed items returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $count = 0;
    //$feeds_seen = [];
    while ($sql->fetch()) {
        //if (isset($feeds_seen[$fid])) continue;
        if (!empty($exclude_string)) {
            if (stripos($ititle, $exclude_string) !== FALSE) continue;
            if (stripos($ilink, $exclude_string) !== FALSE) continue;
            if (stripos($ienclosureurl, $exclude_string) !== FALSE) continue;
            if (stripos($ftitle, $exclude_string) !== FALSE) continue;
        }
        if ($exclude_blanks) {
            if (empty(trim($ititle))) continue;
            if (empty(trim($fimage))) continue;
        }
        if ($count == $max) break;
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE),
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage
        );
        //$feeds_seen[$fid] = 1;
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] recent feed items.");
    return ($items);
}


/**
 * Retrieves the newest feeds that have changed since a given time.
 *
 * @param int|null $since Unix timestamp lower bound for updates.
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of recent feeds.
 */
function get_recent_feeds($since = NULL, $max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;

    //Check parameters
    if (empty($max) || $max > 1000) {
        $max = 1000;
    }
    if (empty($since) || !is_numeric($since)) {
        $since = $hourago;
    } else {
        if ($since < $yesterday) {
            $since = $yesterday;
        }
    }

    //loggit(3, "WHERE feeds.lastupdate > $since AND feeds.lastupdate < $nowtime LIMIT $max");

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("
        SELECT
          newsfeeds.id,
          newsfeeds.url,
          newsfeeds.itunes_id,
          newsfeeds.newest_item_pubdate,
          newsfeeds.language
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.newest_item_pubdate > ? 
          AND newsfeeds.newest_item_pubdate < ?
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT ?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ddd", $since, $nowtime, $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $fitunesid,
        $fnewestitemdate,
        $flanguage
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'newestItemPublishTime' => $fnewestitemdate,
            'itunesId' => $fitunesid,
            'language' => $flanguage
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves a feed by its iTunes ID.
 *
 * @param int|null $itunesId iTunes ID of the feed.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_itunes_id($itunesId = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($itunesId)) {
        loggit(2, "The itunesId argument is blank or corrupt: [$itunesId]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          funding.url,
          funding.message
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
        WHERE newsfeeds.itunes_id=? 
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $itunesId) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that itunes id: [$itunesId].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fundingurl,
        $fundingmessage
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match itunes id: [$itunesId].");
    return ($feeds[0]);
}


/**
 * Retrieves a feed by its internal ID.
 *
 * @param int|null $fid Feed ID.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_id($fid = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.id=? 
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning: [$count] feeds that match id: [$fid].");
    return ($feeds[0]);
}


/**
 * Retrieves a feed's internal ID by its iTunes ID.
 *
 * @param int|null $itunesId iTunes ID of the feed.
 * @return int|false Feed ID on success, false if not found.
 */
function get_feed_id_by_itunes_id($itunesId = NULL)
{

    //Check parameters
    if (empty($itunesId)) {
        loggit(2, "The itunesId argument is blank or corrupt: [$itunesId]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("SELECT id FROM newsfeeds WHERE itunes_id=?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $itunesId) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that itunes id: [$itunesId].");
        return (FALSE);
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);

    $sql->close();

    //Log and leave
    //loggit(3, "Returning feed id: [$fid] that matches itunes id: [$itunesId].");
    return ($fid);
}


//TODO: This isn't finished
/**
 * Creates a UPID for a podcast feed (incomplete implementation).
 *
 * @param int|null $fid Feed ID.
 * @return int|bool Feed ID on success, true if existed, or false on failure.
 */
function create_podcast_upid($fid = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Timestamp
    $createdon = time();

    //Does this feed exist already?
    $last_id = FALSE;
    $existed = FALSE;
    $fid = feed_exists($url);
    if ($fid === NULL) {
        loggit(2, "Something went wrong adding the newsfeed.");
        return (FALSE);
    } else
        if ($fid === FALSE) {
            $stmt = "INSERT INTO $cg_table_newsfeeds (url,createdon,content,original_url,description) VALUES (?,?,'',?,'')";
            $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
            $sql->bind_param("sds", $url, $createdon, $original_url) or loggit(2, "MySql error: " . $dbh->error);
            $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

            //Get the last inserted id
            if ($sqlres === TRUE) {
                $last_id = $dbh->insert_id or loggit(2, "MySql error: " . $dbh->error);;
            } else {
                //Close and exit
                $sql->close() or loggit(2, "MySql error: " . $dbh->error);
                //loggit(3, "Failed to add feed with url: [$url].");
                return (FALSE);
            }
            $fid = $last_id;

            $sql->close();
        } else {
            $existed = TRUE;
            return (TRUE);
        }

    //Log and leave
    if ($existed == TRUE) {
        loggit(3, "Feed: [$fid] with url [$url] already existed in the database.");
    } else {
        loggit(3, "Added a new feed in the repository: [$fid] with url [$url].");
    }
    return ($fid);
}


/**
 * Parses a feed XML string and returns its title.
 *
 * @param string|null $content XML content of the feed.
 * @return string Title string (empty string if not found).
 */
function parse_feed_title($content = NULL)
{
    //Check parameters
    if ($content == NULL) {
        loggit(2, "The content of the feed is blank or corrupt: [$content]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Load the content into a simplexml object
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();

    //Look for a title node in the rss
    if (isset($x->channel->title)) {
        foreach ($x->channel->title as $entry) {
            loggit(1, "Found a title node: [$entry].");
            return ((string)$entry);
        }
    }

    //Look for atom nodes
    if (isset($x->title)) {
        foreach ($x->title as $entry) {
            loggit(1, "Found a title node: [$entry].");
            return ((string)$entry);
        }
    }

    //None of the tests passed so return FALSE
    loggit(1, "Could not find a title for this feed.");
    return ("");
}


/**
 * Marks a feed for immediate pull and optional parse by internal ID.
 *
 * @param int|null $fid Feed ID.
 * @param bool $overwrite When true, forces re-parse even if unchanged.
 * @return bool True on success, false on failure.
 */
function mark_feed_as_pullparse_by_id($fid = NULL, $overwrite = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $pval = 1;
    if ($overwrite) {
        $pval = 2;
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build and execute the database query
    $stmt = "UPDATE $cg_table_newsfeeds 
                SET pullnow=1,
                    parsenow=$pval,
                    lastmod=1,
                    content=''
              WHERE id=? 
                AND dead=0
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    //loggit(3, "Marked feed:[$fid] with immediate pull/parse flag.");
    return (TRUE);

}


/**
 * Clears cached fields for a feed by internal ID.
 *
 * @param int|null $fid Feed ID.
 * @return bool True on success, false on failure.
 */
function clear_feed_caches_by_id($fid = NULL)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build and execute the database query
    $stmt = "UPDATE $cg_table_newsfeeds 
                SET newest_item_pubdate=0,
                    chash='',
                    podcast_chapters='',
                    contenthash=''
              WHERE id=? 
                AND dead=0
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    //loggit(3, "Marked feed:[$fid] with immediate pull/parse flag.");
    return (TRUE);

}


/**
 * Marks a feed for immediate pull and optional parse by URL.
 *
 * @param string|null $url Feed URL.
 * @param bool $overwrite When true, forces re-parse even if unchanged.
 * @param bool $podping Indicates the mark originated from Podping when true.
 * @return bool True on success, false on failure.
 */
function mark_feed_as_pullparse_by_url($url = NULL, $overwrite = FALSE, $podping = FALSE)
{
    //Check parameters
    if (empty($url)) {
        loggit(2, "The feed url argument is blank or corrupt: [$url]");
        return (FALSE);
    }

    if (stripos($url, 'http') !== 0) {
        loggit(2, "The feed url argument doesn't start with http scheme: [$url]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $pval = 1;
    if ($overwrite) {
        $pval = 2;
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Modified versions
    $url = trim($url);
    $url_noslash = rtrim($url, '/');
    $url_slash = $url_noslash . "/";
    $url_slash_http = str_ireplace('https', 'http', $url_slash);
    $url_noslash_http = str_ireplace('https', 'http', $url_noslash);
    $url_slash_https = str_ireplace('http', 'https', $url_slash_http);
    $url_noslash_https = str_ireplace('http', 'https', $url_noslash_http);

    //Build and execute the database query
    $stmt = "UPDATE $cg_table_newsfeeds 
             SET pullnow=1, 
                 parsenow=$pval
             WHERE url=? 
                OR url=? 
                OR url=? 
                OR url=? 
                OR original_url=? 
                OR original_url=? 
                OR original_url=? 
                OR original_url=?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ssssssss",
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https,
        $url_slash_http,
        $url_slash_https,
        $url_noslash_http,
        $url_noslash_https
    ) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $updatecount = $sql->affected_rows;
    if ($updatecount == 0) {
        $sql->close();
        loggit(1, "Marking pull/parse failed for feed: [$url].");
        return (FALSE);
    }
    $sql->close();

    //Log and return
    if ($podping) {
        //loggit(3, "Marked feed with url: [$url] with immediate pull/parse flag via PODPING.");
    } else {
        //loggit(3, "Marked feed with url: [$url_slash or $url_noslash] with immediate pull/parse flag.");
    }

    return (TRUE);

}


/**
 * Records a history entry for when and how a feed was added.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $uid User ID responsible for the addition.
 * @param int|null $did Developer ID associated with the addition.
 * @param int $source Origin code of the addition (e.g., API, CRON).
 * @return int|false Feed ID on success, false on failure.
 */
function add_feed_creation_history($fid = NULL, $uid = NULL, $did = NULL, $source = 0)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Sources
    /*
     * 0 - API
     * 1 - API batch
     * 2 - CRON
     * 3 - apple sync script
     * 4 - podping
     *
     */

    //Do the call
    $stmt = "INSERT INTO $cg_table_feeds_added (feedid,userid,developerid,time_added,`source`) VALUES (?,?,?,UNIX_TIMESTAMP(now()),?)";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dddd", $fid, $uid, $did, $source) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    loggit(3, "Feed: [$fid] added to the index by user|developer: [$uid|$did] from: [$source]");
    return ($fid);
}


/**
 * Retrieves a specific episode by its internal ID.
 *
 * @param int|null $id Episode ID.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed>|array<int, mixed>|null Episode data, empty array if not found, or null on invalid input.
 */
function get_episode_by_id($id = NULL, $fulltext = FALSE)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Check parameters
    if (empty($id)) {
        loggit(2, "The episode id argument is blank or corrupt: [$id]");
        return (NULL);
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          social.uri,
          social.protocol,
          social.accountId,
          social.accountUrl,
          social.priority,
          guids.guid
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfitem_socialinteract AS social ON items.id = social.itemid
         LEFT JOIN nfguids AS guids ON items.feedid = guids.feedid
        WHERE items.id = ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $id) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No episode found with id: [$id]");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $iduration,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $socialuri,
        $socialprotocol,
        $socialaccountid,
        $socialaccounturl,
        $socialpriority,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $soundbites = [];
    $persons = [];
    $transcripts = [];
    $socialInteracts = [];
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedId' => $fid,
                'podcastGuid' => $fguid,
                'feedTitle' => $ftitle,
                'feedLanguage' => $flanguage,
                'chaptersUrl' => $ichapters
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            if (!in_array((string)($isbstarttime . $isbduration), $soundbites)) {
                $items[$iid]['soundbites'][] = array(
                    'startTime' => $isbstarttime,
                    'duration' => $isbduration,
                    'title' => $isbtitle
                );
                $soundbites[] = (string)($isbstarttime . $isbduration);
            }
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Social interact
        if (!empty($socialuri)) {
            if (!in_array($socialuri, $socialInteracts)) {
                $socialprotocoltext = 'activitypub';
                if ($socialprotocol == 2) {
                    $socialprotocoltext = 'twitter';
                }
                $items[$iid]['socialInteract'][] = array(
                    'uri' => $socialuri,
                    'protocol' => $socialprotocoltext,
                    'accountId' => $socialaccountid,
                    'accountUrl' => $socialaccounturl,
                    'priority' => $socialpriority
                );
                $socialInteracts[] = $socialuri;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }


        $count++;
    }
    $sql->close();

    $episodes = array();
    foreach ($items as $item) {
        $episodes[] = $item;
    }

    //Log and leave
    //loggit(3, "Returning episode id: [$id]");
    return ($episodes[0]);
}


/**
 * Retrieves a specific episode by its GUID within a feed.
 *
 * @param string|null $guid Episode GUID.
 * @param int|null $feedid Feed ID the episode belongs to.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed>|array<int, mixed>|null Episode data, empty array if not found, or null on invalid input.
 */
function get_episode_by_guid($guid = NULL, $feedid = NULL, $fulltext = FALSE)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Check parameters
    if (empty($guid)) {
        loggit(2, "The episode id argument is blank or corrupt: [$guid]");
        return (NULL);
    }
    if (empty($feedid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$feedid]");
        return (NULL);
    }


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.url,
          feeds.image AS feedImage,
          CRC32(REPLACE(REPLACE(feeds.image, 'https://', ''), 'http://', '')) as feedImageUrlHash,   
          items.image AS itemImage,
          CRC32(REPLACE(REPLACE(items.image, 'https://', ''), 'http://', '')) as imageUrlHash,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          feedval.value_block,
          guids.guid
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfvalue AS feedval ON feedval.feedid = feeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = feeds.id
        WHERE items.guid = ?
         AND items.feedid = ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $guid, $feedid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No episode found with guid|feedid: [$guid|$feedid]");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $iduration,
        $fitunesid,
        $furl,
        $fimage,
        $fimageurlhash,
        $iimage,
        $iimageurlhash,
        $ftitle,
        $fid,
        $flanguage,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $feedValueBlock,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $soundbites = [];
    $persons = [];
    $transcripts = [];
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iseason,
                'image' => $iimage,
                'imageUrlHash' => $iimageurlhash,
                'feedItunesId' => $fitunesid,
                'feedUrl' => $furl,
                'feedImage' => $fimage,
                'feedImageUrlHash' => $fimageurlhash,
                'feedId' => $fid,
                'podcastGuid' => $fguid,
                'feedTitle' => $ftitle,
                'feedLanguage' => $flanguage,
                'chaptersUrl' => $ichapters
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            if (!in_array((string)($isbstarttime . $isbduration), $soundbites)) {
                $items[$iid]['soundbites'][] = array(
                    'startTime' => $isbstarttime,
                    'duration' => $isbduration,
                    'title' => $isbtitle
                );
                $soundbites[] = (string)($isbstarttime . $isbduration);
            }
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        } else if (!empty($feedValueBlock)) {
            $feedValueBlock = json_decode($feedValueBlock, TRUE);
            if (is_array($feedValueBlock) && isset($feedValueBlock['model']) && isset($feedValueBlock['destinations'])) {
                $items[$iid]['value'] = $feedValueBlock;
            }
        }


        $count++;
    }
    $sql->close();

    $episodes = array();
    foreach ($items as $item) {
        $episodes[] = $item;
    }

    //Log and leave
    //loggit(3, "Returning episode with guid|feedid: [$guid|$feedid]");
    return ($episodes[0]);
}


/**
 * Retrieves a random selection of episodes, optionally filtered by language.
 *
 * @param int $max Maximum number of episodes to return.
 * @param string $language Optional language code filter.
 * @return array<int, array<string, mixed>> List of random episodes.
 */
function get_random_episodes($max = 1, $language = "")
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Param check
    if (!is_numeric($max) || $max > $cg_default_max_list) {
        $max = $cg_default_max_list;
    }
    $language_clause = "";
    if (!empty($language)) {
        $language_clause = " AND feeds.language = ? ";
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Get the maximum id value from the nfitems table
    $stmt = "SELECT MIN(id),MAX(id) FROM $cg_table_newsfeed_items";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_result($minid, $maxid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);

    $maxtime = time();
    $mintime = $maxtime - (86400 * 30);
    $randomtime = rand($mintime, $maxtime);

    //Calc a random value
    $itemid = rand($minid, $maxid);
    //loggit(3, "$itemid - $minid - $maxid - $randomtime");

    //Get the random row from the episodes table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage
        FROM $cg_table_newsfeed_items AS items
        JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
        WHERE items.timestamp >= ?
        $language_clause
        LIMIT ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    if (!empty($language)) {
        $sql->bind_param("dsd", $randomtime, $language, $max) or loggit(2, "MySql error: " . $dbh->error);
    } else {
        $sql->bind_param("dd", $randomtime, $max) or loggit(2, "MySql error: " . $dbh->error);
    }
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No random episode found.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $count = 0;
    while ($sql->fetch()) {
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE),
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning random episode.");
    return ($items);
}


/**
 * Retrieves a random selection of feeds, optionally filtered by language.
 *
 * @param int $max Maximum number of feeds to return.
 * @param string $language Optional language code filter.
 * @return array<int, array<string, mixed>> List of random feeds.
 */
function get_random_feeds($max = 1, $language = "")
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Param check
    if (!is_numeric($max) || $max > $cg_default_max_list) {
        $max = $cg_default_max_list;
    }
    if (!empty($language)) {
        $language_clause = " AND feeds.language = ? ";
    }

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Get the maximum id value from the nfitems table
    $stmt = "SELECT MIN(id),MAX(id) FROM $cg_table_newsfeeds";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_result($minid, $maxid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->fetch() or loggit(2, "MySql error: " . $dbh->error);

    //Calc a random value
    $itemid = rand($minid, $maxid);
    //loggit(3, "$itemid - $minid - $maxid");

    //Get the random row from the feeds table
    $stmt = "SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language
        FROM $cg_table_newsfeeds AS newsfeeds
        JOIN $cg_table_newsfeed_items AS items ON items.feedid = newsfeeds.id 
        WHERE newsfeeds.id >= ?
        $language_clause
        LIMIT ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    if (!empty($language)) {
        $sql->bind_param("dsd", $itemid, $language, $max) or loggit(2, "MySql error: " . $dbh->error);
    } else {
        $sql->bind_param("dd", $itemid, $max) or loggit(2, "MySql error: " . $dbh->error);
    }
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No random episode found.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $count = 0;
    while ($sql->fetch()) {
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE),
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning random episode.");
    return ($items);
}


//Get feeds added in the last 24 hours
/**
 * Retrieves feeds recently added to the index, optionally filtered by developer.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param int|null $developer Developer ID to filter by.
 * @return array<int, array<string, mixed>> List of recently added feeds.
 */
function get_recently_added_feeds($since = NULL, $max = NULL, $developer = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $defaultstart = $nowtime - (86400 * 30);
    $msb_types = "";
    $msb_values = [];

    //Check parameters
    if (empty($since) || !is_numeric($since)) {
        $since = $defaultstart;
    }
    $msb_types .= "d";
    $msb_values[] = $since;

    $developer_clause = "";
    if (!empty($developer) && is_numeric($developer)) {
        $developer_clause = " AND developerid=? ";
        $msb_types .= "d";
        $msb_values[] = $developer;
    }

    if (empty($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 25000) {
        $max = 25000;
    }
    $msb_types .= "d";
    $msb_values[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sqltxt = "
        SELECT
          feedsadded.feedid,
          newsfeeds.url,
          newsfeeds.title,
          newsfeeds.description,
          newsfeeds.link,
          feedsadded.time_added,
          newsfeeds.lastparse,
          newsfeeds.chash,
          newsfeeds.language,
          newsfeeds.image,
          newsfeeds.artwork_url_600,
          newsfeeds.itunes_id,
          feedsadded.source,
          newsfeeds.dead
        FROM $cg_table_feeds_added AS feedsadded          
        LEFT JOIN $cg_table_newsfeeds AS newsfeeds ON feedsadded.feedid = newsfeeds.id
        WHERE feedsadded.time_added > ?
        $developer_clause
        ORDER BY feedsadded.time_added DESC
        LIMIT ?
    ";
    //loggit(3, $sqltxt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_values, TRUE));
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_values) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No added feeds since: [$since].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fdescription,
        $flink,
        $ftimeadded,
        $flastparse,
        $fcontenthash,
        $flanguage,
        $fimage,
        $fartwork,
        $fitunesid,
        $fsource,
        $fdead
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $status = "confirmed";
        if (empty($flastparse)) {
            $status = "pending";
        }
        if (!empty($fartwork)) {
            $fimage = $fartwork;
        }
        $feeds[] = array(
            'id' => $fid,
            'status' => $status,
            'url' => $furl,
            'title' => $ftitle,
            'description' => $fdescription,
            'link' => $flink,
            'timeAdded' => $ftimeadded,
            'contentHash' => $fcontenthash,
            'language' => $flanguage,
            'image' => $fimage,
            'itunesId' => $fitunesid,
            'source' => $fsource,
            'dead' => $fdead
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] recently added feeds.");
    return ($feeds);
}


/**
 * Retrieves a feed by its internal ID with optional private info and dead feeds.
 *
 * @param int|null $fid Feed ID.
 * @param bool $fulltext Include full text fields when true.
 * @param bool $withprivateinfo Include private fields when true.
 * @param bool $withdead Include dead feeds when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_id2($fid = NULL, $fulltext = FALSE, $withprivateinfo = FALSE, $withdead = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Dead?
    $dead = 0;
    if ($withdead) {
        $dead = 1;
    }

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.explicit,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.itunes_type,
          newsfeeds.language,
          newsfeeds.chash,
          newsfeeds.item_count,
          newsfeeds.podcast_locked,
          newsfeeds.podcast_owner,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash,
          val.value_block,
          guids.guid,
          mediums.medium
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id 
         LEFT JOIN nfvalue AS val ON val.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
         LEFT JOIN nfmediums AS mediums ON mediums.feedid = newsfeeds.id
        WHERE newsfeeds.id=? 
          AND dead=?
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $fid, $dead) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fexplicit,
        $fauthor,
        $femail,
        $fname,
        $fitunestype,
        $flanguage,
        $fchash,
        $fitemcount,
        $flocked,
        $fowner,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash,
        $fvalblock,
        $fguid,
        $fmedium
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $explicit = FALSE;
        if ($fexplicit == 1) $explicit = TRUE;
        if (empty($fmedium)) $fmedium = "podcast";
        $feeds[$count] = array(
            'id' => $fid,
            'podcastGuid' => $fguid,
            'medium' => $fmedium,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'itunesType' => $fitunestype,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'explicit' => $explicit,
            'type' => $ftype,
            'dead' => $fdead,
            'chash' => $fchash,
            'episodeCount' => $fitemcount,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'imageUrlHash' => $fimageurlhash,
            'value' => $fvalblock
        );
        if ($withprivateinfo) {
            $feeds[$count]['itunesOwnerEmail'] = $femail;
            $feeds[$count]['podcastOwnerEmail'] = $fowner;
        }
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match id: [$fid].");
    return ($feeds[0]);
}


/**
 * Retrieves recently changed feeds with optional language/category filters and sorting.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @param string|null $sort Sort mode (e.g., discovery).
 * @return array<int, array<string, mixed>> List of feeds.
 */
function get_recent_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL, $sort = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);

    //Sorting
    $sortColumnName = "feeds.newest_item_pubdate";
    if ($sort == "discovery") {
        $sortColumnName = "feeds.lastupdate";
    }
    $order_by_clause = " ORDER BY $sortColumnName DESC ";

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $weekago;
    if ($since < $weekago) {
        $since = $weekago;
    }
    $since_clause = " AND feeds.newest_item_pubdate > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.oldest_item_pubdate,
          feeds.description,
          feeds.image,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         JOIN nfcategories AS cat ON cat.feedid = feeds.id 
        WHERE feeds.newest_item_pubdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        $order_by_clause
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    //loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fitunesid,
        $fnewestitemdate,
        $foldestitemdate,
        $fdescription,
        $fimage,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'newestItemPublishTime' => $fnewestitemdate,
            'oldestItemPublishTime' => $foldestitemdate,
            'description' => $fdescription,
            'image' => $fimage,
            'itunesId' => $fitunesid,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves random episodes with optional language and category filters.
 *
 * @param int|null $max Maximum number of episodes to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of random episodes.
 */
function get_random_episodes_with_filters($max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL, $fulltext = FALSE)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $msb_types_l = "";
    $msb_params_l = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);

    //Generate a random timestamp as a starting point
    $maxtime = time();
    $mintime = $maxtime - (86400 * 30);
    $randomtime = rand($mintime, $maxtime);

    //Determine time range
    $since_clause = " items.timestamp >= $randomtime AND items.timestamp < $nowtime ";

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > $cg_default_max_list) {
        $max = $cg_default_max_list;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          chapters.url,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         JOIN nfcategories AS cat ON cat.feedid = items.feedid
         LEFT JOIN nfitem_chapters AS chapters ON chapters.itemid = items.id
        WHERE
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause
        LIMIT ?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage,
        $ichapters,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => $idescription,
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage,
            'categories' => $categories,
            'chaptersUrl' => $ichapters
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] random items.");
    return ($items);
}


//Find feed items for a given feed id
/**
 * Retrieves items (episodes) for a feed by ID with simplified fields.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_feed_id2($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " items.feedid = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " items.feedid = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    if ($fulltext && $max > 100) {
        $max = 100;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.image,
          items.image,
          feeds.language,
          chapters.url,
          transcripts.url,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
        WHERE 
         $feedid_clause
         AND items.timestamp < $nowtime
         $since_clause         
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_types, TRUE));
//    loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $fimage,
        $iimage,
        $flanguage,
        $ichapters,
        $itranscript,
        $isbstarttime,
        $isbduration,
        $isbtitle
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'feedLanguage' => $flanguage,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscript
            );
        }
        if (!empty($isbstarttime) && !empty($isbduration)) {
            $items[$iid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$iid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }
        $count++;
    }
    $sql->close();

    $episodes = array();
    foreach ($items as $item) {
        $episodes[] = $item;
    }

    //Log and leave
    //loggit(3, "Returning: [$count] items for feeds.");
    return ($episodes);
}


//Get the content hash of a feed
/**
 * Retrieves the stored content hash for a feed.
 *
 * @param int|null $fid Feed ID.
 * @return string|false Hash string on success, false if not found or on error.
 */
function get_feed_hash($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT contenthash FROM $cg_table_newsfeeds WHERE id = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$fid].");
        return (FALSE);
    }
    $sql->bind_result($fcontenthash) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $contenthash = $fcontenthash;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning hash: [$contenthash] for feed: [$fid].");
    return ($contenthash);
}


/**
 * Retrieves the current URL of a feed by its ID.
 *
 * @param int|null $fid Feed ID.
 * @return string|false URL string on success, false if not found.
 */
function get_feed_url($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT url FROM $cg_table_newsfeeds WHERE id = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$fid].");
        return (FALSE);
    }
    $sql->bind_result($furl) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $url = $furl;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning url: [$url] for feed: [$fid].");
    return ($url);
}


/**
 * Updates the stored content hash for a feed.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $hash New content hash value.
 * @return bool True on success, false on failure.
 */
function update_feed_hash($fid = NULL, $hash = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($hash)) {
        loggit(2, "The feed hash argument is blank or corrupt: [$hash]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the value
    $hash = trim($hash);

    //Build the query
    $stmt = "INSERT INTO nfhashes (feedid,hash,updatedon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE hash=?,updatedon=UNIX_TIMESTAMP(NOW())";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $hash, $hash) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();


    //Log and return
    loggit(1, "Changed feed:[$fid]'s hash to: [$hash].");
    return (TRUE);
}


/**
 * Retrieves feeds that have updated since a given time with optional language and category filters.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @return array<int, array<string, mixed>> List of updated feeds.
 */
function get_updated_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);
    $direction = " DESC ";

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) {
        $since = $weekago;
    } else {
        $direction = " ASC ";
    }
    if ($since < $weekago) {
        $since = $weekago;
    }
    $since_clause = " AND feeds.lastupdate > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.lastupdate,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         LEFT JOIN nfcategories AS cat ON cat.feedid = feeds.id 
        WHERE feeds.lastupdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        ORDER BY feeds.lastupdate $direction
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_params, TRUE));
    //loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fitunesid,
        $fnewestitemdate,
        $flastupdate,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'newestItemPublishTime' => $fnewestitemdate,
            'lastUpdateTime' => $flastupdate,
            'itunesId' => $fitunesid,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves recent episodes with optional language/category filters and pagination.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of episodes to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @param int|null $before_id Return items with ID less than this value.
 * @param bool|null $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of episodes.
 */
function get_recent_episodes_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL, $before_id = NULL, $fulltext = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $msb_types_l = "";
    $msb_params_l = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);
    $orderby_clause = " ORDER BY items.id DESC ";

    //Was an item id specified?
    $since_clause = "";
    if (!empty($before_id) && is_numeric($before_id)) {
        $since_clause = " items.timestamp < $nowtime AND items.id < ? ";
        $msb_types .= "d";
        $msb_params[] = $before_id;
    } else {
        //Determine time range
        if (empty($since) || !is_numeric($since)) $since = $weekago;
        if ($since < $weekago) {
            $since = $weekago;
        }
        $since_clause = " items.timestamp < $nowtime AND items.timestamp >= ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $categories_join_type = "LEFT";
    $category_include_clause = "";
    if (!empty($include_categories)) {
        //$orderby_clause = "";
        $categories_join_type = "";
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        //$orderby_clause = "";
        $categories_join_type = "";
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > $cg_default_max_list) {
        $max = $cg_default_max_list;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT
          items.id,
          items.title,
          items.link,          
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         $categories_join_type JOIN nfcategories AS cat ON cat.feedid = items.feedid
        WHERE
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause
        $orderby_clause
        LIMIT ?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_params, TRUE));
    //loggit(3, print_r($msb_types, TRUE) . print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        //$idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            //'description' => $idescription,
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] random items.");
    return ($items);
}


/**
 * Retrieves the channel-level image from all feeds starting at an optional offset.
 *
 * @param int|null $startat Starting offset for pagination.
 * @return array<int, array<string, mixed>> List of feed images.
 */
function get_all_feed_images($startat = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($startat) || !is_numeric($startat)) {
        $startat = 0;
    }

    //Build the query
    $sqltxt = "SELECT feeds.id,
                      feeds.image,
                      feeds.artwork_url_600 
               FROM $cg_table_newsfeeds AS feeds
               WHERE feeds.id > $startat 
               ORDER BY feeds.id ASC";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no feed images found. This is odd.");
        return (array());
    }

    //Set bindings
    $sql->bind_result($fid, $image, $artwork) or loggit(2, "MySql error: " . $dbh->error);

    //Process results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'image' => $image,
            'artwork' => $artwork
        );
        $count++;
    }

    $sql->close();

    //loggit(3, "Returning: [$count] feed images.");
    return ($feeds);
}


/**
 * Retrieves the oldest item publish timestamp for a feed.
 *
 * @param int|null $fid Feed ID.
 * @return int|false Unix timestamp on success, false if not found.
 */
function get_feed_oldest_item_pubdate($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT oldest_item_pubdate FROM $cg_table_newsfeeds WHERE id = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(1, "No feeds found with id: [$fid].");
        return (FALSE);
    }
    $sql->bind_result($foldest) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $oldest = $foldest;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning oldest item pubdate: [$oldest] for feed: [$fid].");
    return ($oldest);
}


/**
 * Deletes items from a feed older than the specified timestamp.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $timestamp Unix timestamp threshold.
 * @return int|false Number of deleted items on success, false on failure.
 */
function delete_old_feed_items($fid = NULL, $timestamp = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($timestamp)) {
        loggit(2, "The timestamp is blank or corrupt: [$timestamp]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("DELETE FROM $cg_table_newsfeed_items WHERE feedid = ? AND timestamp < ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $fid, $timestamp) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $deletecount = $sql->affected_rows;
    if ($deletecount == 0) {
        $sql->close();
        loggit(1, "No feed items deleted for: [$fid|$timestamp].");
        return (0);
    }

    $sql->close();

    //Log and leave
    loggit(1, "Deleted: [$deletecount] items older than: [$timestamp] for feed: [$fid].");
    return ($deletecount);
}


/**
 * Marks a feed as problematic (upserts a reason code and timestamp).
 *
 * Inserts or updates an entry in the `nfproblematic` table for the given feed.
 *
 * @param int|null $fid Feed ID.
 * @param int $problemcode Reason code (0–100).
 * @return bool True on success, false on failure.
 */
function mark_feed_as_problematic($fid = NULL, $problemcode = 0)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (!is_numeric($problemcode) || $problemcode > 100) {
        loggit(2, "The problem code is corrupt: [$problemcode]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);


    //Build the query
    $stmt = "INSERT INTO nfproblematic (feedid,reason,updatedon) 
             VALUES (?, ?, UNIX_TIMESTAMP(NOW())) 
             ON DUPLICATE KEY UPDATE reason=?,updatedon=UNIX_TIMESTAMP(NOW())
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dds", $fid, $problemcode, $problemcode) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();


    //Log and return
    loggit(1, "Marked feed: [$fid] as probmlematic, code: [$problemcode].");
    return (TRUE);
}

/**
 * Retrieves feeds marked as problematic.
 *
 * @param int|null $max Maximum number of records to return.
 * @return array<int, array<string, mixed>> List of problematic feed entries.
 */
function get_feeds_problematic($max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Param check
    if (empty($max) || !is_numeric($max) || $max > 10000) {
        $max = 10000;
    }

    //Build the query
    $sqltxt = "
        SELECT 
          problem.id,
          problem.feedid,
          problem.reason,
          problem.updatedon
        FROM nfproblematic AS problem
        ORDER BY problem.id DESC
        LIMIT ?
    ";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no problem feeds.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $pid,
        $pfeedid,
        $preason,
        $pupdatedon
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = array(
            'id' => $pid,
            'feedId' => $pfeedid,
            'reason' => $preason,
            'updatedOn' => $pupdatedon
        );
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] problematic feeds.");
    return ($feeds);
}

/**
 * Sets the popularity score for a feed by its iTunes ID.
 *
 * @param int|null $itunes_id iTunes ID of the feed.
 * @param int|float $score Popularity score to set.
 * @return bool True on success, false on failure.
 */
function set_feed_popularity_by_itunes_id($itunes_id = NULL, $score = 0)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id argument is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (!is_numeric($score)) {
        loggit(2, "The score value is corrupt: [$score]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET popularity=? WHERE itunes_id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $score, $itunes_id) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set popularity to: [$score] for itunes id: [$itunes_id].");

    return (TRUE);
}


/**
 * Sets the popularity score for a feed by its internal ID, optionally only if higher.
 *
 * @param int|null $fid Feed ID.
 * @param int|float $score Popularity score to set.
 * @param bool $only_higher When true, only updates if the existing score is lower.
 * @return bool True on success, false on failure.
 */
function set_feed_popularity_by_feed_id($fid = NULL, $score = 0, $only_higher = FALSE)
{
    //Check parameters
    if (empty($fid) || !is_numeric($fid) || $fid < 1) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (!is_numeric($score)) {
        loggit(2, "The score value is corrupt: [$score]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    if ($only_higher) {
        //Build the query
        $stmt = "UPDATE $cg_table_newsfeeds 
                    SET popularity=? 
                  WHERE id=?
                    AND popularity < ?
        ";
        $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
        $sql->bind_param("ddd", $score, $fid, $score) or loggit(2, "MySql error: " . $dbh->error);
    } else {
        //Build the query
        $stmt = "UPDATE $cg_table_newsfeeds 
                    SET popularity=? 
                  WHERE id=?
        ";
        $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
        $sql->bind_param("dd", $score, $fid) or loggit(2, "MySql error: " . $dbh->error);
    }
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set popularity to: [$score] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Increments the popularity score for a feed by its iTunes ID.
 *
 * @param int|null $itunes_id iTunes ID of the feed.
 * @param int|float $score Amount to add to the popularity score.
 * @return bool True on success, false on failure.
 */
function increment_feed_popularity_by_itunes_id($itunes_id = NULL, $score = 0)
{
    //Check parameters
    if (empty($itunes_id)) {
        loggit(2, "The itunes id argument is blank or corrupt: [$itunes_id]");
        return (FALSE);
    }
    if (!is_numeric($score)) {
        loggit(2, "The score value is corrupt: [$score]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET popularity=popularity + ? WHERE itunes_id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $score, $itunes_id) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Added: [$score] to the popularity score for itunes id: [$itunes_id].");

    return (TRUE);
}


/**
 * Retrieves all newsfeeds for an export dump.
 *
 * @param int|null $start Starting position (offset) for pagination.
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> Export rows for feeds.
 */
function get_all_feeds_for_export($start = NULL, $max = NULL)
{
    //Check parameters
    if ($start === NULL || !is_numeric($start)) {
        loggit(2, "The start param must be specified as a number.");
        return (FALSE);
    }
    if ($max === NULL || !is_numeric($max)) {
        loggit(2, "The max param must be specified as a number.");
        return (FALSE);
    }
    if ($max > 1000) {
        loggit(2, "The max param must be 1000 or less.");
        return (FALSE);
    }

    //Figure out stopping point
    $stop = $start + $max;

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Create a category name lookup table
    $categorymap = array_map('strtolower', $cg_categorynames);
    $categorymap[''] = "";

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    $sqltxt = "SELECT 
       pc.id,
       pc.url,
       pc.title,
       pc.lastupdate,
       pc.link,
       pc.lasthttpstatus,
       pc.dead,
       pc.contenttype,
       pc.itunes_id,
       pc.original_url,
       pc.itunes_author,
       pc.itunes_owner_name,
       pc.explicit,
       pc.image,
       pc.itunes_type,
       pc.generator,
       pc.newest_item_pubdate,
       pc.`language`,
       pc.oldest_item_pubdate,
       pc.item_count,
       pc.popularity,
       pc.priority,
       pc.createdon,
       pc.update_frequency,
       pc.chash,
       re.enclosure_url AS last_enclosure_url,
       re.itunes_duration,
       guids.guid,
       Replace(Replace(SUBSTRING(pc.description, 1, 1000),CHAR(10),''),CHAR(13),''),
       cat.catid1,
       cat.catid2,
       cat.catid3,
       cat.catid4,
       cat.catid5,
       cat.catid6,
       cat.catid7,       
       cat.catid8,
       cat.catid9,
       cat.catid10
    FROM newsfeeds AS pc
    LEFT JOIN nfguids AS guids ON guids.feedid = pc.id 
    LEFT JOIN nfcategories AS cat ON cat.feedid = pc.id 
    LEFT JOIN (
        WITH ordered_episodes AS (
            SELECT id,
                   feedid,
                   enclosure_url,
                   itunes_duration,
                   ROW_NUMBER() OVER (PARTITION BY feedid ORDER BY id DESC) AS rn
            FROM nfitems AS episodes WHERE feedid >= ? AND feedid < ?
        )
        SELECT feedid, enclosure_url, itunes_duration
        FROM ordered_episodes
        WHERE rn = 1
    ) AS re ON re.feedid = pc.id
    WHERE pc.id >= ? AND pc.id < ?";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dddd", $start, $stop, $start, $stop) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Set bindings
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $flastupdate,
        $flink,
        $flasthttpstatus,
        $fdead,
        $fcontenttype,
        $fitunes_id,
        $foriginal_url,
        $fitunes_author,
        $fitunes_owner_name,
        $fexplicit,
        $fimage,
        $fitunes_type,
        $fgenerator,
        $fnewest_item_pubdate,
        $flanguage,
        $foldest_item_pubdate,
        $fitem_count,
        $fpopularity,
        $fpriority,
        $fcreatedon,
        $fupdate_frequency,
        $fchash,
        $elastenclosureurl,
        $fduration,
        $fguid,
        $fdescription,
        $fcat1,
        $fcat2,
        $fcat3,
        $fcat4,
        $fcat5,
        $fcat6,
        $fcat7,
        $fcat8,
        $fcat9,
        $fcat10
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Process results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        if ($count == 0 && empty($start)) {
            $feeds[] = array(
                'id' => 'id',
                'url' => 'url',
                'title' => 'title',
                'lastUpdate' => 'lastUpdate',
                'link' => 'link',
                'lastHttpStatus' => 'lastHttpStatus',
                'dead' => 'dead',
                'contentType' => 'contentType',
                'itunesId' => 'itunesId',
                'originalUrl' => 'originalUrl',
                'itunesAuthor' => 'itunesAuthor',
                'itunesOwnerName' => 'itunesOwnerName',
                'explicit' => 'explicit',
                'imageUrl' => 'imageUrl',
                'itunesType' => 'itunesType',
                'generator' => 'generator',
                'newestItemPubdate' => 'newestItemPubdate',
                'language' => 'language',
                'oldestItemPubdate' => 'oldestItemPubdate',
                'episodeCount' => 'episodeCount',
                'popularityScore' => 'popularityScore',
                'priority' => 'priority',
                'createdOn' => 'createdOn',
                'updateFrequency' => 'updateFrequency',
                'chash' => 'chash',
                'host' => 'host',
                'newestEnclosureUrl' => 'lastEnclosureUrl',
                'podcastGuid' => 'podcastGuid',
                'description' => 'description',
                'category1' => 'category1',
                'category2' => 'category2',
                'category3' => 'category3',
                'category4' => 'category4',
                'category5' => 'category5',
                'category6' => 'category6',
                'category7' => 'category7',
                'category8' => 'category8',
                'category9' => 'category9',
                'category10' => 'category10',
                'newestEnclosureDuration' => 'newestEnclosureDuration'
            );
        }

        //Create a host tag for the feed source
        $hosttag = "";
        $host = parse_url($furl, PHP_URL_HOST);
        if (stripos($host, '.')) {
            $hostparts = explode('.', $host);
            $hosttag = $hostparts[0] . "." . $hostparts[1];
            if (count($hostparts) > 2) {
                $parts = [...array_slice($hostparts, (count($hostparts) * -1) + 1)];
                $hosttag = implode('.', $parts);
            }
        }

        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'lastUpdate' => $flastupdate,
            'link' => $flink,
            'lastHttpStatus' => $flasthttpstatus,
            'dead' => $fdead,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunes_id,
            'originalUrl' => $foriginal_url,
            'itunesAuthor' => $fitunes_author,
            'itunesOwnerName' => $fitunes_owner_name,
            'explicit' => $fexplicit,
            'imageUrl' => $fimage,
            'itunesType' => $fitunes_type,
            'generator' => $fgenerator,
            'newestItemPubdate' => $fnewest_item_pubdate,
            'language' => $flanguage,
            'oldestItemPubdate' => $foldest_item_pubdate,
            'episodeCount' => $fitem_count,
            'popularityScore' => $fpopularity,
            'priority' => $fpriority,
            'createdOn' => $fcreatedon,
            'updateFrequency' => $fupdate_frequency,
            'chash' => $fchash,
            'host' => $hosttag,
            'newestEnclosureUrl' => $elastenclosureurl,
            'podcastGuid' => $fguid,
            'description' => $fdescription,
            'category1' => $categorymap[$fcat1],
            'category2' => $categorymap[$fcat2],
            'category3' => $categorymap[$fcat3],
            'category4' => $categorymap[$fcat4],
            'category5' => $categorymap[$fcat5],
            'category6' => $categorymap[$fcat6],
            'category7' => $categorymap[$fcat7],
            'category8' => $categorymap[$fcat8],
            'category9' => $categorymap[$fcat9],
            'category10' => $categorymap[$fcat10],
            'newestEnclosureDuration' => $fduration
        );
        $count++;
    }

    $sql->close();

    //loggit(3, "Returning: [$count] feeds for export.");
    return ($feeds);
}


/**
 * Retrieves the owner email address for a feed.
 *
 * @param int|null $fid Feed ID.
 * @return string|false Owner email on success, false if not found.
 */
function get_feed_owner_email($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT podcast_owner FROM $cg_table_newsfeeds WHERE id = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$fid].");
        return (FALSE);
    }
    $sql->bind_result($fowner) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $owner = $fowner;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning email: [$owner] for feed: [$fid].");
    return ($owner);
}


/**
 * Retrieves the podcast owner email for the feed that owns a given item.
 *
 * @param int|null $iid Item (episode) ID.
 * @return string|false Owner email on success, false if not found or invalid input.
 */
function get_item_owner_email($iid = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The item id argument is blank or corrupt: [$iid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT f.podcast_owner 
                                FROM nfitems AS i
                                INNER JOIN newsfeeds AS f ON f.id = i.feedid 
                                WHERE i.id = ?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $iid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$iid].");
        return (FALSE);
    }
    $sql->bind_result($fowner) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $owner = $fowner;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning email: [$owner] for feed: [$iid].");
    return ($owner);
}


/**
 * Retrieves the title of a feed by its ID.
 *
 * @param int|null $fid Feed ID.
 * @return string Feed title (empty string if not found).
 */
function get_feed_title($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT title FROM $cg_table_newsfeeds WHERE id = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$fid].");
        return ('');
    }
    $sql->bind_result($ftitle) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        $title = $ftitle;
    }
    $sql->close();

    //Log and leave
    loggit(1, "Returning title: [$title] for feed: [$fid].");
    return ($title);
}


/**
 * Updates the Sphinx node pubkey for a feed and writes a basic value block.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $hash Node pubkey hash.
 * @return bool True on success, false on failure.
 */
function sphinx_update_pubkey($fid = NULL, $hash = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($hash)) {
        loggit(2, "The feed hash argument is blank or corrupt: [$hash]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the value
    $hash = trim($hash);

    //

    //Build the query
    $stmt = "INSERT INTO nfsphinx (feedid,node,updatedon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE node=?,updatedon=UNIX_TIMESTAMP(NOW())";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $hash, $hash) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //Create a value block
    $feed = [];
    $feed['model'] = array(
        'type' => 'lightning',
        'method' => 'keysend',
        'suggested' => '0.00000005000'
    );
    $feed['destinations'] = [];
    $feed['destinations'][] = array(
        'name' => 'Podcaster',
        'address' => $hash,
        'type' => "node",
        'split' => 100
    );
    $valblock = json_encode($feed);

    $stmt = "INSERT INTO nfvalue (feedid,value_block,createdon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE value_block=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $valblock, $valblock) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed:[$fid]'s sphinx pubkey node to: [$hash].");
    return (TRUE);
}


/**
 * Updates the Voltage node pubkey for a feed and writes a basic value block.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $hash Node pubkey hash.
 * @return bool True on success, false on failure.
 */
function voltage_update_pubkey($fid = NULL, $hash = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($hash)) {
        loggit(2, "The feed hash argument is blank or corrupt: [$hash]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the value
    $hash = trim($hash);

    //Build the query
    $stmt = "INSERT INTO nfsphinx (feedid,node,updatedon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE node=?,updatedon=UNIX_TIMESTAMP(NOW())";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $hash, $hash) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //Create a value block
    $feed = [];
    $feed['model'] = array(
        'type' => 'lightning',
        'method' => 'keysend',
        'suggested' => '0.00000005000'
    );
    $feed['destinations'] = [];
    $feed['destinations'][] = array(
        'name' => 'Podcaster',
        'address' => $hash,
        'type' => "node",
        'split' => 100
    );
    $valblock = json_encode($feed);

    $stmt = "INSERT INTO nfvalue (feedid,value_block,createdon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE value_block=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $valblock, $valblock) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed:[$fid]'s voltage pubkey node to: [$hash].");
    return (TRUE);
}


/**
 * Set or update the value block JSON for a feed.
 *
 * Inserts a new row in `nfvalue` for the given feed ID or updates the existing
 * `value_block` JSON via ON DUPLICATE KEY UPDATE.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $valueblock JSON-encoded value block to store.
 * @return bool True on success, false on invalid input or failure.
 */
function set_feed_valueblock($fid = NULL, $valueblock = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($valueblock)) {
        loggit(2, "The valueblock argument is blank or corrupt: [$valueblock]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the value
    $valblock = trim($valueblock);

    //Build the query
    $stmt = "INSERT INTO nfvalue (feedid,value_block,createdon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE value_block=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $fid, $valblock, $valblock) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed:[$fid]'s valueblock to: [$valblock].");
    return (TRUE);
}


/**
 * Set or update the value block JSON for an episode item.
 *
 * Inserts a new row in `nfitem_value` for the given item ID or updates the
 * existing `value_block` JSON via ON DUPLICATE KEY UPDATE.
 *
 * @param int|null $iid Episode item ID.
 * @param string|null $valueblock JSON-encoded value block to store.
 * @return bool True on success, false on invalid input or failure.
 */
function set_feed_item_valueblock($iid = NULL, $valueblock = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The episode item id argument is blank or corrupt: [$iid]");
        return (FALSE);
    }
    if (empty($valueblock)) {
        loggit(2, "The valueblock argument is blank or corrupt: [$valueblock]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Clean up the value
    $valblock = trim($valueblock);

    //Build the query
    $stmt = "INSERT INTO nfitem_value (itemid,value_block,createdon) VALUES (?, ?, UNIX_TIMESTAMP(NOW())) ON DUPLICATE KEY UPDATE value_block=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dss", $iid, $valblock, $valblock) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Changed feed item:[$iid]'s valueblock to: [$valblock].");
    return (TRUE);
}


/**
 * Delete the value block JSON for a feed.
 *
 * Removes any `nfvalue` record associated with the specified feed ID.
 *
 * @param int|null $fid Feed ID.
 * @return bool True if deletion executed (false on invalid input).
 */
function delete_feed_valueblock($fid = NULL)
{
    //Check parameters
    if (empty($fid) || !is_numeric($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "DELETE FROM nfvalue WHERE feedid=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and return
    loggit(1, "Deleted valueblock from feed: [$fid].");
    return (TRUE);
}


/**
 * Delete the value block JSON for an episode item.
 *
 * Removes any `nfitem_value` record associated with the specified item ID. Returns
 * true if the deletion affected at least one row; false if nothing was deleted
 * or on invalid input.
 *
 * @param int|null $iid Episode item ID.
 * @return bool True if a value block was deleted; false otherwise.
 */
function delete_item_valueblock($iid = NULL)
{
    //Check parameters
    if (empty($iid) || !is_numeric($iid)) {
        loggit(2, "The item id argument is blank or corrupt: [$iid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "DELETE FROM nfitem_value WHERE itemid=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $iid) or loggit(2, "MySql error: " . $dbh->error);
    $sqlres = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $deletecount = $sql->affected_rows;
    if ($deletecount == 0) {
        $sql->close();
        loggit(3, "No item value blocks deleted for: [$iid].");
        return (FALSE);
    }

    $sql->close();

    //Log and return
    loggit(3, "Deleted valueblock from item: [$iid].");
    return (TRUE);
}


/**
 * Retrieves basic feed information used by the Sphinx connection/indexing layer.
 *
 * @param int|null $fid Feed ID.
 * @return array<string, string>|string Associative array with keys:
 *                                      - title: Feed title.
 *                                      - itunes_owner: iTunes owner name (may be empty).
 *                                      - owner: Feed owner's email (may be empty).
 *                                      - nodekey: Sphinx node key (may be empty).
 *                                      Returns an empty string if the feed is not found.
 */
function sphinx_get_feed_info($fid = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT feeds.title, 
                                       feeds.podcast_owner, 
                                       feeds.itunes_owner_email,
                                       sphinx.node
                                FROM $cg_table_newsfeeds AS feeds
                                LEFT JOIN nfsphinx AS sphinx ON sphinx.feedid = feeds.id 
                                WHERE feeds.id = ?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() != 1) {
        $sql->close();
        loggit(2, "No feeds found with id: [$fid].");
        return ('');
    }
    $sql->bind_result($ftitle, $fitowner, $fowner, $fnode) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    while ($sql->fetch()) {
        if (empty($ftitle)) $ftitle = "";
        if (empty($fnode)) $fnode = "";
        $info = array(
            'title' => $ftitle,
            'itunes_owner' => $fitowner,
            'owner' => $fowner,
            'nodekey' => $fnode
        );
    }
    $sql->close();


    //Log and leave
    loggit(1, "Returning sphinx info for feed: [$fid].");
    return ($info);
}


//Get the most recent list of soundbites
/**
 * Retrieves the most recent soundbites across episodes.
 *
 * @param int|null $max Maximum number of soundbites to return.
 * @return array<int, array<string, mixed>> List of recent soundbites.
 */
function get_recent_soundbites($max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    if ($max > 1000 || empty($max) || !is_numeric($max)) {
        $max = 1000;
    }

    //Build the query
    $sqltxt = "SELECT soundbite.itemid,
                      soundbite.title,
                      soundbite.start_time,
                      soundbite.duration,
                      episodes.enclosure_url,
                      episodes.title,
                      newsfeeds.title,
                      newsfeeds.url,
                      newsfeeds.id
               FROM nfitem_soundbites AS soundbite
               LEFT JOIN nfitems AS episodes ON soundbite.itemid = episodes.id
               LEFT JOIN newsfeeds AS newsfeeds ON newsfeeds.id = episodes.feedid                
               ORDER BY soundbite.itemid DESC
               LIMIT ?";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no recent soundbites found.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $iid,
        $sbtitle,
        $sbstarttime,
        $sbduration,
        $enclosureurl,
        $episodetitle,
        $nftitle,
        $nfurl,
        $nfid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Process results
    $soundbites = array();
    $count = 0;
    while ($sql->fetch()) {
        $soundbites[] = array(
            'enclosureUrl' => $enclosureurl,
            'title' => $sbtitle,
            'startTime' => $sbstarttime,
            'duration' => $sbduration,
            'episodeId' => $iid,
            'episodeTitle' => $episodetitle,
            'feedTitle' => $nftitle,
            'feedUrl' => $nfurl,
            'feedId' => $nfid
        );
        $count++;
    }

    $sql->close();

    loggit(1, "Returning: [$count] soundbites.");
    return ($soundbites);
}


/**
 * Retrieves trending feeds with optional language and category filters.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @return array<int, array<string, mixed>> List of trending feeds.
 */
function get_trending_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $weekago;
    if ($since < $weekago) {
        $since = $weekago;
    }
    $since_clause = " AND feeds.newest_item_pubdate > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.description,               
          feeds.itunes_author,
          feeds.image,
          feeds.artwork_url_600,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.popularity,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         JOIN nfcategories AS cat ON cat.feedid = feeds.id 
        WHERE feeds.newest_item_pubdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        ORDER BY feeds.popularity DESC
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fdescription,
        $fauthor,
        $fimage,
        $fartwork,
        $fitunesid,
        $fnewestitemdate,
        $fpopularity,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'description' => $fdescription,
            'author' => $fauthor,
            'image' => $fimage,
            'artwork' => $fartwork,
            'newestItemPublishTime' => $fnewestitemdate,
            'itunesId' => $fitunesid,
            'trendScore' => $fpopularity,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves trending value-enabled feeds with optional language and category filters.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @return array<int, array<string, mixed>> List of trending value-enabled feeds.
 */
function get_trending_value_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $weekago;
    if ($since < $weekago) {
        $since = $weekago;
    }
    $since_clause = " AND feeds.newest_item_pubdate > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_author,
          feeds.image,
          feeds.artwork_url_600,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.popularity,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         JOIN nfcategories AS cat ON cat.feedid = feeds.id
         INNER JOIN nfvalue AS val ON val.feedid = feeds.id 
        WHERE feeds.newest_item_pubdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        ORDER BY feeds.popularity DESC
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fauthor,
        $fimage,
        $fartwork,
        $fitunesid,
        $fnewestitemdate,
        $fpopularity,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'author' => $fauthor,
            'image' => $fimage,
            'artwork' => $fartwork,
            'newestItemPublishTime' => $fnewestitemdate,
            'itunesId' => $fitunesid,
            'trendScore' => $fpopularity,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves feeds that have a value block.
 *
 * @param int|null $max Maximum number of feeds to return.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $start_at Starting feed ID for pagination.
 * @return array<int, array<string, mixed>> List of feeds with value blocks.
 */
function get_feeds_with_value($max = NULL, $fulltext = FALSE, $start_at = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Vars
    $order_by_clause = " ORDER BY newsfeeds.popularity DESC ";
    $where_clause = " ";
    $max_clause = " LIMIT ? ";
    $msb_types = "";
    $msb_params = [];

    //Was a starting point given?
    $since_clause = "";
    if ($start_at !== NULL && is_numeric($start_at)) {
        $order_by_clause = " ORDER BY val.feedid ASC ";
        $where_clause = " WHERE val.feedid >= ? AND newsfeeds.dead = 0 ";
        $msb_types .= "d";
        $msb_params[] = $start_at;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = 500;
    }
    if ($max > 5000) {
        $max = 5000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //Build the query
    $sqltxt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.popularity,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash,
          val.value_block,
          val.createdon,
          guids.guid
        FROM nfvalue AS val
         JOIN newsfeeds AS newsfeeds ON val.feedid = newsfeeds.id
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
        $where_clause
        $order_by_clause
        $max_clause
    ";

    //Prepare the query
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    //Execute the query
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no feeds with value blocks.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fpopularity,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash,
        $fvalblock,
        $fcreatedon,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'podcastGuid' => $fguid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'popularity' => $fpopularity,
            'imageUrlHash' => $fimageurlhash,
            'value' => $fvalblock,
            'valueCreatedOn' => $fcreatedon
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] feeds with value blocks.");
    return ($feeds);
}


/**
 * Retrieves feeds that have a valuetimesplit tag.
 *
 * @param int|null $max Maximum number of feeds to return.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $start_at Starting feed ID for pagination.
 * @return array<int, array<string, mixed>> List of feeds with valuetimesplit.
 */
function get_feeds_with_valuetimesplit($max = NULL, $fulltext = FALSE, $start_at = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Vars
    $order_by_clause = " ORDER BY newsfeeds.popularity DESC ";
    $where_clause = " ";
    $max_clause = " LIMIT ? ";
    $msb_types = "";
    $msb_params = [];

    //Was a starting point given?
    $since_clause = "";
    if ($start_at !== NULL && is_numeric($start_at)) {
        $order_by_clause = " ORDER BY val.feedid ASC ";
        $where_clause = " WHERE val.feedid >= ? AND newsfeeds.dead = 0 ";
        $msb_types .= "d";
        $msb_params[] = $start_at;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = 500;
    }
    if ($max > 5000) {
        $max = 5000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //Build the query
    $sqltxt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.popularity,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(newsfeeds.image, 'https://', ''), 'http://', '')) as imageUrlHash,
          val.value_block,
          val.createdon,
          guids.guid
        FROM nfitem_valuetimesplits AS vts
         JOIN nfitems AS ep ON vts.itemid = ep.id
         JOIN newsfeeds AS newsfeeds ON ep.feedid = newsfeeds.id
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
         LEFT JOIN nfvalue AS val ON val.feedid = newsfeeds.id
        $where_clause
        GROUP BY newsfeeds.id 
        $order_by_clause
        $max_clause
    ";

    //Prepare the query
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    //Execute the query
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no feeds with value blocks.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fpopularity,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash,
        $fvalblock,
        $fcreatedon,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'podcastGuid' => $fguid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'popularity' => $fpopularity,
            'imageUrlHash' => $fimageurlhash,
            'value' => $fvalblock,
            'valueCreatedOn' => $fcreatedon
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] feeds with value blocks.");
    return ($feeds);
}


/**
 * Retrieves a feed by its content hash (chash).
 *
 * @param string|null $chash Content hash of the feed.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_chash($chash = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($chash)) {
        loggit(2, "The feed chash argument is blank or corrupt: [$chash]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.chash
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.chash=? 
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $chash) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fchash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }

        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked,
            'chash' => $fchash
        );

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match chash: [$chash].");
    return ($feeds[0]);
}


/**
 * Retrieves feeds by a content hash (chash) value.
 *
 * @param string|null $chash Content hash value.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of feeds with the given chash.
 */
function get_feeds_by_chash($chash = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($chash)) {
        loggit(2, "The feed chash argument is blank or corrupt: [$chash]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.chash
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.chash=? 
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $chash) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fchash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }

        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked,
            'chash' => $fchash
        );

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match chash: [$chash].");
    return ($feeds);
}


/**
 * Retrieves feeds by a podcast GUID value.
 *
 * @param string|null $guid Podcast GUID value.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of feeds with the given GUID.
 */
function get_feeds_by_guid($guid = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($guid)) {
        loggit(2, "The feed guid argument is blank or corrupt: [$guid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.parsenow,
          newsfeeds.priority,          
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.chash,
          guids.guid,
          mediums.medium,
          newsfeeds.item_count,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(newsfeeds.image, 'https://', ''), 'http://', '')) as imageUrlHash   
        FROM nfguids AS guids
         JOIN newsfeeds AS newsfeeds ON newsfeeds.id = guids.feedid
         LEFT JOIN nfcategories AS cat ON cat.feedid = guids.feedid
         LEFT JOIN nfmediums AS mediums ON mediums.feedid = guids.feedid
        WHERE guids.guid = ?
        ORDER BY newsfeeds.popularity DESC, newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $guid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fparsenow,
        $fpriority,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fchash,
        $fguid,
        $fmedium,
        $fitemcount,
        $fcatids,
        $fimageurlhash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $explicit = FALSE;
        if ($fexplicit == 1) $explicit = TRUE;
        if (empty($fmedium)) $fmedium = "podcast";
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'inPollingQueue' => $fparsenow,
            'priority' => $fpriority,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked,
            'chash' => $fchash,
            'podcastGuid' => $fguid,
            'medium' => $fmedium,
            'episodeCount' => $fitemcount,
            'imageUrlHash' => $fimageurlhash,
            'newestItemPubdate' => $fnewestitemdate
        );

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match guid: [$guid].");
    return ($feeds);
}


/**
 * Retrieves feeds that were added but not yet processed.
 *
 * @param int|null $max Maximum number of records to return.
 * @return array<int, array<string, mixed>> List of unprocessed feeds with IDs, URLs, and chash.
 */
function get_feeds_added_unprocessed($max = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $sqltxt = "SELECT added.feedid,
                      newsfeeds.url,
                      newsfeeds.chash
               FROM feeds_added AS added
               LEFT JOIN newsfeeds AS newsfeeds ON newsfeeds.id = added.feedid
               WHERE stage=0
               ORDER BY time_added ASC";

    if (!empty($max)) {
        $sqltxt .= " LIMIT ?";
    }

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    if (!empty($max)) {
        $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    }
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Set bindings
    $sql->bind_result(
        $fid,
        $furl,
        $fhash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Process results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'chash' => $fhash
        );
        $count++;
    }

    $sql->close();

    //loggit(3, "Returning: [$count] added yet unprocessed feeds.");
    return ($feeds);
}


/**
 * Retrieves autocomplete suggestions for feeds using Sphinx.
 *
 * @param string|null $q Partial search term.
 * @return array<int, array<string, string|int>> List of hints with `feedId` and `hint`.
 */
function search_hints_using_sphinx($q = NULL)
{

    //Check parameters
    if (empty($q)) {
        loggit(2, "The search hint term argument is blank or corrupt: [$q]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli('192.168.234.187', '', '', '', 9306) or loggit(2, "MySql error: " . $dbh->error);

    $clean_query = sphinxEscapeString($q);
    $clean_query = $dbh->real_escape_string($clean_query);

    $max = 10;

    $hints = array();
    $count = 0;

    //Do the query
    if ($result = $dbh->query("SELECT id,title FROM test1 WHERE MATCH('^" . $clean_query . "*') GROUP BY title ORDER BY popularity DESC LIMIT $max")) {
        //Build the return results
        while ($row = $result->fetch_row()) {
            $hints[] = array(
                'feedId' => $row[0],
                'hint' => $row[1]
            );

            $count++;
        }
        $result->close();
    } else {
        //loggit(3, "No hints exist that match search term: [$q].");
        return (array());
    }

    //Log and leave
    //loggit(3, "Returning: [$count] hints from sphinx that match search term: [$q].");
    return ($hints);
}


/**
 * Retrieves feeds missing an iTunes ID, with optional zero-inclusion and ordering.
 *
 * @param bool $withzero Include feeds with itunes_id = 0 when true.
 * @param bool $newestfirst Order by newest feed IDs first when true.
 * @param int|null $since Only include feeds created after this Unix timestamp.
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of feeds with missing iTunes IDs.
 */
function get_feeds_without_itunes_id($withzero = FALSE, $newestfirst = FALSE, $since = NULL, $max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    $zeroclause = "";
    if ($withzero) {
        $zeroclause = " OR feeds.itunes_id = 0 ";
    }

    $directionclause = " ASC ";
    if ($newestfirst) {
        $directionclause = " DESC ";
    }

    $sinceclause = " ";
    if (!empty($since) && is_numeric($since)) {
        $sinceclause = " AND createdon > $since ";
    }

    //Build the query
    $sqltxt = "
        SELECT 
          feeds.id,
          feeds.url
        FROM newsfeeds AS feeds
          WHERE feeds.itunes_id IS NULL
          $zeroclause
          $sinceclause
        ORDER BY feeds.id $directionclause
    ";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no feeds with missing itunes ids.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $furl
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = array(
            'id' => $fid,
            'url' => $furl
        );
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] feeds without itunes ids.");
    return ($feeds);
}


//Retrieve a value block for a feed using it's feed id
/**
 * Retrieves the value block JSON for a feed by its ID.
 *
 * @param int|null $fid Feed ID.
 * @return string|array<mixed>|null Value block JSON string, empty array if not found, or null on invalid input.
 */
function get_valueblock_by_id($fid = NULL)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          val.value_block
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfvalue AS val ON val.feedid = newsfeeds.id
        WHERE newsfeeds.id=?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $fvalblock
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = array(
            'id' => $fid,
            'value' => $fvalblock
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning value block for feed id: [$fid].");
    return ($feeds[0]['value']);
}


/**
 * Retrieves the value block JSON for an episode by its ID.
 *
 * @param int|null $eid Episode ID.
 * @return string|array<mixed>|null Value block JSON string, empty array if not found, or null on invalid input.
 */
function get_valueblock_by_episode_id($eid = NULL)
{

    //Check parameters
    if (empty($eid)) {
        loggit(2, "The episode id argument is blank or corrupt: [$eid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          episodes.id,
          val.value_block
        FROM nfitems AS episodes
         LEFT JOIN nfitem_value AS val ON val.itemid = episodes.id
        WHERE episodes.id=?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $eid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$eid].");
        return (array());
    }
    $sql->bind_result(
        $eid,
        $fvalblock
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $episodes = array();
    $count = 0;
    while ($sql->fetch()) {
        $episodes[$count] = array(
            'id' => $eid,
            'value' => $fvalblock
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning value block for feed id: [$eid].");
    return ($episodes[0]['value']);
}


/**
 * Sets the detected language code for a feed by its ID.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $language Language code (e.g., en, es).
 * @return bool True on success, false on failure.
 */
function set_feed_detected_language_by_id($fid = NULL, $language = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($language)) {
        loggit(2, "The language code is blank or corrupt: [$language]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET detected_language=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $language, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set language to: [$language] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Retrieves a feed by its internal ID with extended fields.
 *
 * @param int|null $fid Feed ID.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_id3($fid = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.detected_language,
          newsfeeds.chash,
          newsfeeds.item_count,
          newsfeeds.podcast_locked,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash,
          val.value_block
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id 
         LEFT JOIN nfvalue AS val ON val.feedid = newsfeeds.id
        WHERE newsfeeds.id=? AND dead=0
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $fdetectedlanguage,
        $fchash,
        $fitemcount,
        $flocked,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash,
        $fvalblock
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'languageDetected' => $fdetectedlanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'chash' => $fchash,
            'episodeCount' => $fitemcount,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'imageUrlHash' => $fimageurlhash,
            'value' => $fvalblock
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match id: [$fid].");
    return ($feeds[0]);
}


/**
 * Retrieves feeds marked as dead.
 *
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of dead feeds.
 */
function get_feeds_dead($max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Param check
    if (empty($max) || !is_numeric($max) || $max > 10000) {
        $max = 10000;
    }

    //Build the query
    $sqltxt = "
        SELECT 
          feeds.id,
          feeds.title,
          feeds.url,
          feeds.duplicateof
        FROM newsfeeds AS feeds
          WHERE feeds.dead = 1
        ORDER BY feeds.id ASC
        LIMIT ?
    ";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no dead feeds.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $fduplicateof
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'duplicateOf' => $fduplicateof
        );
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] dead feeds.");
    return ($feeds);
}


/**
 * Retrieves items for one or more feed IDs with optional filters.
 *
 * @param int|array<int>|null $fid Single feed ID or array of feed IDs.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @param string|null $enclosure Optional enclosure type filter (e.g., audio/mp3).
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_feed_id3($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE, $enclosure = NULL)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " items.feedid = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " items.feedid = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //A specific enclosure is wanted
    $enclosure_clause = "";
    if (!empty($enclosure)) {
        $enclosure_clause = " AND items.enclosure_url = ? ";
        $msb_types .= "s";
        $msb_params[] = $enclosure;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $vmax = $max * 5;
    $msb_types .= "d";
    $msb_params[] = $vmax;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.url,
          feeds.image,
          items.image,
          feeds.language,
          feeds.dead,
          feeds.duplicateof,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          social.uri,
          social.protocol,
          social.accountId,
          social.accountUrl,
          social.priority,
          vts.id,
          vts.start_time,
          vts.duration,
          vts.remote_start_time,
          vts.remote_percentage,
          vts.feed_guid,
          vts.feed_url,
          vts.item_guid,
          vts.medium,
          guids.guid
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfitem_socialinteract AS social ON items.id = social.itemid
         LEFT JOIN nfitem_valuetimesplits AS vts ON items.id = vts.itemid
         LEFT JOIN nfguids AS guids ON guids.feedid = feeds.id
        WHERE 
         $feedid_clause
         AND items.timestamp < $nowtime
         $since_clause
         $enclosure_clause
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $rows = $sql->num_rows();
    if ($rows < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    //loggit(3, "Episode count: [$rows]");
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $furl,
        $fimage,
        $iimage,
        $flanguage,
        $fdead,
        $fduplicateof,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $socialuri,
        $socialprotocol,
        $socialaccountid,
        $socialaccounturl,
        $socialpriority,
        $vtsid,
        $vtsstarttime,
        $vtsduration,
        $vtsremotestarttime,
        $vtsremotepercentage,
        $vtsfeedguid,
        $vtsfeedurl,
        $vtsitemguid,
        $vtsmedium,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $persons = [];
    $transcripts = [];
    $socialInteracts = [];
    $valueTimeSplits = [];
    $count = 0;
    while ($sql->fetch()) {
        //Fix amps
        if (stripos($ienclosureurl, '&amp;') !== FALSE) {
            $ienclosureurl = str_ireplace('&amp;', '&', $ienclosureurl);
        }

        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedUrl' => $furl,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'podcastGuid' => $fguid,
                'feedLanguage' => $flanguage,
                'feedDead' => $fdead,
                'feedDuplicateOf' => $fduplicateof,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            $items[$iid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$iid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Social interact
        if (!empty($socialuri)) {
            if (!in_array($socialuri, $socialInteracts)) {
                $socialprotocoltext = 'activitypub';
                if ($socialprotocol == 2) {
                    $socialprotocoltext = 'twitter';
                }
                $items[$iid]['socialInteract'][] = array(
                    'uri' => $socialuri,
                    'protocol' => $socialprotocoltext,
                    'accountId' => $socialaccountid,
                    'accountUrl' => $socialaccounturl,
                    'priority' => $socialpriority
                );
                $socialInteracts[] = $socialuri;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }
        //Value time splits
        if (!empty($vtsstarttime) && !empty($vtsduration)) {
            if (!in_array($vtsid, $valueTimeSplits)) {
                $items[$iid]['timesplits'][] = array(
                    'startTime' => $vtsstarttime,
                    'duration' => $vtsduration,
                    'remoteStartTime' => $vtsremotestarttime,
                    'remotePercentage' => $vtsremotepercentage,
                    'feedGuid' => $vtsfeedguid,
                    'feedUrl' => $vtsfeedurl,
                    'itemGuid' => $vtsitemguid,
                    'medium' => $vtsmedium
                );
                $valueTimeSplits[] = $vtsid;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    if (is_array($fid)) {
        foreach ($fid as $feedid) {
            //loggit(3, "Returning episodes for feed id: [$feedid].");
        }
    } else {
        //loggit(3, "Returning episodes for feed id: [$fid|$count|$ecount].");
    }
    return ($episodes);
}


//Set the priority level of a feed
function set_feed_priority_by_id($fid = NULL, $priority = NULL, $override = FALSE)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($priority)) {
        loggit(2, "The feed priority level is blank or corrupt: [$priority]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET priority=? WHERE id=? AND priority >= 0";
    if ($override) {
        $stmt = "UPDATE $cg_table_newsfeeds SET priority=? WHERE id=?";
    }
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $priority, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set priority to: [$priority] for feed id: [$fid].");

    return (TRUE);
}


//Find the newest $max number of feeds that have changed
/**
 * Retrieves feeds that have been updated recently since a given time.
 *
 * @param int|null $since Unix timestamp lower bound for newest item publish time.
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of recently updated feeds.
 */
function get_recently_updated_feeds($since = NULL, $max = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $nowtime = time() - 1;
    $weekago = $nowtime - (86400 * 7);

    //Don't get feeds that have future publish times
    $msb_types = "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $weekago;
    if ($since < $weekago) {
        $since = $weekago;
    }
    $since_clause = " AND feeds.newest_item_pubdate > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 100000) {
        $max = 100000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.oldest_item_pubdate,
          feeds.itunes_owner_email,
          feeds.podcast_owner
        FROM newsfeeds AS feeds  
        WHERE feeds.newest_item_pubdate < ?
          $since_clause        
        ORDER BY feeds.lastcheck DESC
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fitunesid,
        $fnewestitemdate,
        $foldestitemdate,
        $fitunesemail,
        $fpodcastemail
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'itunesId' => $fitunesid,
            'newestItemPublishTime' => $fnewestitemdate,
            'oldestItemPublishTime' => $foldestitemdate,
            'itunesOwnerEmail' => $fitunesemail,
            'podcastOwner' => $fpodcastemail
        );
        $count++;
    }
    $sql->close();


    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Marks a feed as dead and disables pull/parse flags.
 *
 * @param int|null $fid Feed ID.
 * @return bool True on success, false on failure.
 */
function mark_feed_as_dead($fid = NULL)
{
    //Check parameters
    if (empty($fid) || !is_numeric($fid) || $fid < 1) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET dead=1,pullnow=0,parsenow=0 WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Marked feed: [$fid] as dead.");

    return (TRUE);
}


/**
 * Clears the dead flag on a feed (marks it as active).
 *
 * @param int|null $fid Feed ID.
 * @return bool True on success, false on failure.
 */
function unmark_feed_as_dead($fid = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET dead=0 WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Un-marked feed: [$fid] as dead.");

    return (TRUE);
}


/**
 * Retrieves newly added value-enabled feeds with optional language and category filters.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @return array<int, array<string, mixed>> List of value-enabled feeds.
 */
function get_new_value_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);
    $monthago = $nowtime - (86400 * 30);
    $yearago = $nowtime - (86400 * 365);

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $yearago;
    if ($since < $yearago) {
        $since = $yearago;
    }
    $since_clause = " AND val.createdon > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_author,
          feeds.image,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.popularity,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         JOIN nfcategories AS cat ON cat.feedid = feeds.id
         INNER JOIN nfvalue AS val ON val.feedid = feeds.id 
        WHERE feeds.newest_item_pubdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        ORDER BY val.createdon DESC
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fauthor,
        $fimage,
        $fitunesid,
        $fnewestitemdate,
        $fpopularity,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'author' => $fauthor,
            'image' => $fimage,
            'newestItemPublishTime' => $fnewestitemdate,
            'itunesId' => $fitunesid,
            'trendScore' => $fpopularity,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Retrieves newly added music feeds with optional language and category filters.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of feeds to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @return array<int, array<string, mixed>> List of music feeds.
 */
function get_new_music_feeds_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);
    $monthago = $nowtime - (86400 * 30);
    $yearago = $nowtime - (86400 * 365);

    //Don't get feeds that have future publish times
    $msb_types .= "d";
    $msb_params[] = $nowtime;

    //Determine time range
    $since_clause = "";
    if (empty($since) || !is_numeric($since)) $since = $yearago;
    if ($since < $yearago) {
        $since = $yearago;
    }
    $since_clause = " AND val.createdon > ? ";
    $msb_types .= "d";
    $msb_params[] = $since;
    $msb_types_l = "";
    $msb_params_l = [];

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $category_include_clause = "";
    if (!empty($include_categories)) {
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          feeds.id, 
          feeds.url,
          feeds.title,
          feeds.itunes_author,
          feeds.image,
          feeds.itunes_id,
          feeds.newest_item_pubdate,
          feeds.popularity,
          feeds.language,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds 
        FROM newsfeeds AS feeds 
         JOIN nfcategories AS cat ON cat.feedid = feeds.id
         INNER JOIN nfvalue AS val ON val.feedid = feeds.id 
        WHERE feeds.newest_item_pubdate < ?
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause          
        ORDER BY val.createdon DESC
        LIMIT ?;
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fauthor,
        $fimage,
        $fitunesid,
        $fnewestitemdate,
        $fpopularity,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'title' => $ftitle,
            'author' => $fauthor,
            'image' => $fimage,
            'newestItemPublishTime' => $fnewestitemdate,
            'itunesId' => $fitunesid,
            'trendScore' => $fpopularity,
            'language' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return ($feeds);
}


/**
 * Deletes all items belonging to a feed.
 *
 * @param int|null $fid Feed ID.
 * @return int|false Number of deleted items on success, 0 if none deleted, or false on invalid input/error.
 */
function delete_feed_items($fid = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("DELETE FROM $cg_table_newsfeed_items WHERE feedid = ?") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $deletecount = $sql->affected_rows;
    if ($deletecount == 0) {
        $sql->close();
        loggit(1, "No feed items deleted for: [$fid].");
        return (0);
    }

    $sql->close();

    //Log and leave
    loggit(1, "Deleted: [$deletecount] items for feed: [$fid].");
    return ($deletecount);
}


/**
 * Retrieves feeds by the podcast owner's email address.
 *
 * @param string|null $email Owner email address.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of feeds owned by the email address.
 */
function get_feeds_by_owner_email($email = NULL, $fulltext = FALSE, $max = NULL)
{
    //Check parameters
    if (empty($email)) {
        loggit(2, "The owner email is blank or corrupt.");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($max) || $max > $cg_default_max_search_results) {
        $max = $cg_default_max_search_results;
    }

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.explicit,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash      
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
        WHERE newsfeeds.podcast_owner=? AND dead=0
        GROUP BY newsfeeds.id
        ORDER BY popularity DESC, newest_item_pubdate DESC
        LIMIT ?
    ";
    //loggit(3, $stmt);
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $email, $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "Could not retrieve feeds for search result lookup.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $fexplicit,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fcatids,
        $fimageurlhash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!empty($fduplicateof)) {
            if (in_array($fduplicateof, $fids)) {
                continue;
            }
        }
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $isexplicit = FALSE;
        if ($fexplicit == 1) $isexplicit = TRUE;
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'itunesOwnerEmail' => $femail,
            'itunesOwnerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'explicit' => $isexplicit,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'lockedEmail' => $femail,
            'imageUrlHash' => $fimageurlhash
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match the email address: [$email].");
    return ($feeds);
}


/**
 * Retrieves the newest episodes across all feeds, ordered by item ID as a proxy for recency.
 *
 * @param int|null $max Maximum number of episodes to return (capped internally).
 * @return array<int, array<string, mixed>> List of recent episodes.
 */
function get_recent_episodes_by_timestamp($max = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Check parameters
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 1000) {
        $max = 1000;
    }

    $nowtime = time() - 1;
    $limit = ($max + 50);


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.image AS itemImage,
        FROM $cg_table_newsfeed_items AS items
        WHERE items.timestamp < $nowtime
        ORDER BY items.id DESC
        LIMIT ?
    ";
    //loggit(3, "SQL: $stmt");
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $limit) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feed items returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $iimage,
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $count = 0;
    $feeds_seen = [];
    while ($sql->fetch()) {
        if (isset($feeds_seen[$fid])) continue;
        if (!empty($exclude_string)) {
            if (stripos($ititle, $exclude_string) !== FALSE) continue;
            if (stripos($ilink, $exclude_string) !== FALSE) continue;
            if (stripos($ienclosureurl, $exclude_string) !== FALSE) continue;
            if (stripos($ftitle, $exclude_string) !== FALSE) continue;
        }
        if ($exclude_blanks) {
            if (empty(trim($ititle))) continue;
            if (empty(trim($fimage))) continue;
        }
        if ($count == $max) break;
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            'description' => limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE),
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'episode' => $iepisode,
            'episodeType' => $iepisodetype,
            'season' => $iseason,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage
        );
        $feeds_seen[$fid] = 1;
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] recent feed items.");
    return ($items);
}


/**
 * Retrieves feeds matching an exact title string.
 *
 * @param string|null $title Exact title to match.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of feeds with the given title.
 */
function get_feeds_by_title($title = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($title)) {
        loggit(2, "The feed title argument is blank or corrupt: [$title]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.chash
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.title=? 
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $title) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that title: [$title].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fchash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }

        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked,
            'chash' => $fchash
        );

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match title: [$title].");
    return ($feeds);
}


/**
 * Retrieves feeds that have an iTunes ID and returned HTTP 404.
 *
 * @return array<int, array<string, mixed>> List of feeds.
 */
function get_feeds_with_404_and_itunes_id()
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.chash
        FROM $cg_table_newsfeeds AS newsfeeds
        WHERE newsfeeds.lasthttpstatus = 404
          AND newsfeeds.itunes_id IS NOT NULL
          AND newsfeeds.itunes_id > 0
        GROUP BY newsfeeds.id
        ORDER BY newsfeeds.newest_item_pubdate DESC        
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that title: [$title].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fchash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'locked' => $flocked,
            'chash' => $fchash
        );

        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that have a 404 response and an itunes id.");
    return ($feeds);
}


/**
 * Sets the main URL of a feed.
 *
 * Updates the `url` column for the given feed ID.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $url Absolute URL to set for the feed.
 * @return bool True on success, false on failure.
 */
function set_feed_url($fid = NULL, $url = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($url)) {
        loggit(2, "The feed url argument is blank or corrupt: [$url]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE $cg_table_newsfeeds SET url=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $url, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql_result = $sql->execute() or loggit(2, "MySql error: " . $dbh->error);

    //See if any rows came back
    $updatecount = $sql->affected_rows;
    if ($updatecount == 0 || !$sql_result) {
        $sql->close();
        loggit(1, "Setting the feed: [$fid] to url: [$url] failed.");
        return (FALSE);
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Set url to: [$url] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Retrieves feeds and episodes that changed since a given timestamp with cursor pagination.
 *
 * @param int|null $since Unix timestamp lower bound (defaults to ~15 minutes ago).
 * @param int|null $max Maximum number of records to return (capped internally).
 * @param int|null $position Episode ID cursor; returns items with IDs greater than this.
 * @return array<string, mixed> Object containing `nextSince`, `position`, `feeds`, and `items`.
 */
function get_recent_data($since = NULL, $max = NULL, $position = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $nowtime = time();
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $position_statement = "";

    //Check parameters
    if (empty($since) || !is_numeric($since)) {
        $since = $fifteenminutesago;
    }
    $msb_types = "d";
    $msb_params[] = $since;
    if (!empty($position) && is_numeric($position) && $position > 0) {
        $position_statement = " AND items.id > ? ";
        $msb_types .= "d";
        $msb_params[] = $position;
    }
    if (empty($max) || $max > 10000) {
        $max = 10000;
    }
    $msb_types .= "d";
    $msb_params[] = $max;

    //loggit(3, "WHERE feeds.lastupdate > $since AND feeds.lastupdate < $nowtime LIMIT $max");

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build query
    $stmt = "SELECT
               items.feedid,
               feeds.url,
               feeds.title,
               feeds.description,
               feeds.image,
               feeds.language,
               feeds.itunes_id,
               items.id,
               items.title,
               items.description,
               items.image,       
               items.timestamp,
               items.timeadded,
               items.enclosure_url,
               items.enclosure_length,
               items.enclosure_type,
               items.itunes_duration,
               items.itunes_episode_type
             FROM nfitems AS items
               JOIN newsfeeds AS feeds ON feeds.id = items.feedid
             WHERE items.timeadded <= UNIX_TIMESTAMP() AND items.timeadded >= ?
               $position_statement
             ORDER BY items.timeadded ASC
             LIMIT ?;
    ";
//    loggit(3, $stmt);
//    loggit(3, print_r($msb_params, TRUE));
//    loggit(3, print_r($msb_types, TRUE).print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent data returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftitle,
        $fdescription,
        $fimage,
        $flanguage,
        $fitunesid,
        $iid,
        $ititle,
        $idescription,
        $iimage,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosurelength,
        $ienclosuretype,
        $iduration,
        $iepisodetype
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $position = 0;
    $last_timestamp = [];
    $feeds_seen = [];
    $feeds = [];
    $items_seen = [];
    $items = [];
    $count = 0;
    while ($sql->fetch()) {
        $next_since = $itimeadded;
        if (!isset($feeds_seen[$fid])) {
            $feeds_seen[$fid] = 0;
            $feeds[] = array(
                'feedId' => $fid,
                'feedUrl' => $furl,
                'feedTitle' => $ftitle,
                'feedDescription' => $fdescription,
                'feedImage' => $fimage,
                'feedLanguage' => $flanguage,
                'feedItunesId' => $fitunesid
            );
        }
        if ($iid > $position) {
            $position = $iid;
        }
        if (!isset($items_seen[$iid])) {
            $items_seen[$iid] = 0;
            $items[] = array(
                'episodeId' => $iid,
                'episodeTitle' => $ititle,
                'episodeDescription' => $idescription,
                'episodeImage' => $iimage,
                'episodeTimestamp' => $itimestamp,
                'episodeAdded' => $itimeadded,
                'episodeEnclosureUrl' => $ienclosureurl,
                'episodeEnclosureLength' => $ienclosurelength,
                'episodeEnclosureType' => $ienclosuretype,
                'episodeDuration' => $iduration,
                'episodeType' => $iepisodetype,
                'feedId' => $fid
            );
        }
        $count++;
    }
    $sql->close();


    //Log and leave
    //loggit(3, "Returning: [$count] recent feeds.");
    return (array('nextSince' => $next_since, 'position' => $position, 'feeds' => $feeds, 'items' => $items));
}


/**
 * Sets the podcast GUID for a feed.
 *
 * Inserts a row into the `nfguids` table linking the given feed ID to the supplied GUID.
 *
 * @param int|null $fid Feed ID.
 * @param string|null $guid Podcast-level GUID (typically a UUID or URL).
 * @return bool True on success, false on invalid parameters.
 */
function set_feed_guid($fid = NULL, $guid = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($guid)) {
        loggit(2, "The guid is blank or corrupt: [$guid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "INSERT INTO nfguids (feedid, guid) VALUES(?,?)";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ds", $fid, $guid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set guid to: [$guid] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Sets the `duplicateof` relationship for a feed.
 *
 * Updates the `newsfeeds.duplicateof` column to point at the canonical/original
 * feed this record is considered a duplicate of.
 *
 * @param int|null $fid Feed ID to update.
 * @param int|null $did Feed ID of the canonical/original feed.
 * @return bool True on success, false on invalid parameters.
 */
function set_feed_duplicateof($fid = NULL, $did = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($did)) {
        loggit(2, "The duplicate id is blank or corrupt: [$did]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE newsfeeds SET duplicateof=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $did, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set duplicateof to: [$did] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Sets the `lastmod` timestamp for a feed.
 *
 * Updates the `newsfeeds.lastmod` column with the provided Unix timestamp
 * indicating when the remote feed was last modified.
 *
 * @param int|null $fid Feed ID to update.
 * @param int|null $lastmod Unix timestamp (seconds since epoch).
 * @return bool True on success, false on invalid parameters.
 */
function set_feed_lastmod($fid = NULL, $lastmod = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (!is_numeric($lastmod) || $lastmod < 0) {
        loggit(2, "The lastmod param is blank or corrupt: [$lastmod]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE newsfeeds SET lastmod=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("dd", $lastmod, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set lastmod to: [$lastmod] for feed id: [$fid].");

    return (TRUE);
}


/**
 * Sets the ETag (stored in `content`) for a feed.
 *
 * Updates the `newsfeeds.content` column with the provided ETag or opaque token
 * used to validate conditional GET requests against the remote feed.
 *
 * Note: In this schema the ETag value is stored in the `content` field.
 *
 * @param int|null $fid Feed ID to update.
 * @param string|null $etag ETag/token string to store.
 * @return bool True on success, false on invalid parameters.
 */
function set_feed_etag($fid = NULL, $etag = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "UPDATE newsfeeds SET content=? WHERE id=?";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $etag, $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set etag for feed id: [$fid].");

    return (TRUE);
}


/**
 * Retrieves a feed ID for a given podcast GUID.
 *
 * @param string|null $guid Podcast GUID value.
 * @return int|null Feed ID if found, null on invalid input or not found.
 */
function get_feed_id_by_guid($guid = NULL)
{
    //Check parameters
    if (empty($guid)) {
        loggit(2, "The guid argument is blank or corrupt: [$guid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT guids.feedid 
        FROM nfguids AS guids 
        JOIN newsfeeds AS feeds ON feeds.id=guids.feedid 
        WHERE guids.guid=? 
          AND feeds.dead = 0
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("s", $guid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that guid: [$guid].");
        return (array());
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = array(
            'id' => $fid
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning feed id: [$fid] for guid: [$guid].");
    return ($feeds[0]['id']);
}


/**
 * Retrieves feed IDs for one or more podcast GUIDs.
 *
 * @param array<int, string>|string|null $guids One GUID or an array of GUIDs.
 * @return array<int> List of feed IDs (empty if none found).
 */
function get_feed_ids_by_guids($guids = NULL)
{
    //Check parameters
    if (empty($guids)) {
        loggit(2, "The guids argument is blank or corrupt: [$guids]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Build the feed id array
    $guid_clause = "";
    $fccount = 0;
    if (is_array($guids)) {
        foreach ($guids as $guid) {
            if ($fccount == 0) $guid_clause .= " ( ";
            if ($fccount > 0) $guid_clause .= " OR ";
            $guid_clause .= " guids.guid = ? ";
            $msb_types .= "s";
            $msb_params[] = $guid;
            $fccount++;
        }
        $guid_clause .= " ) ";
    } else {
        $fccount = 1;
        $guid_clause .= " guids.guid = ? ";
        $msb_types .= "s";
        $msb_params[] = $guids;
    }


    //Do the query
    $sql = $dbh->prepare("
        SELECT guids.feedid 
        FROM nfguids AS guids 
        JOIN newsfeeds AS feeds ON feeds.id=guids.feedid 
        WHERE $guid_clause
          AND feeds.dead = 0
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that guid: [$guid].");
        return (array());
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feed_ids = array();
    $count = 0;
    while ($sql->fetch()) {
        $feed_ids[$count] = $fid;
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning feed id: [$fid] for guid: [$guid].");
    return ($feed_ids);
}


/**
 * Retrieves episodes by one or more episode GUIDs within an optional feed GUID.
 *
 * @param array<int, string>|string|null $guids One episode GUID or an array of GUIDs.
 * @param string|null $feedguid Optional podcast GUID to scope the search.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of episodes.
 */
function get_episodes_by_guids($guids = NULL, $feedguid = NULL, $fulltext = FALSE)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Check parameters
    if (empty($guids)) {
        loggit(2, "The guids argument is blank or corrupt: [$guids]");
        return (NULL);
    }
    if (empty($feedguid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$feedguid]");
        return (NULL);
    }


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Build the feed id array
    $guid_clause = "";
    $fccount = 0;
    if (is_array($guids)) {
        foreach ($guids as $guid) {
            if ($fccount == 0) $guid_clause .= " ( ";
            if ($fccount > 0) $guid_clause .= " OR ";
            $guid_clause .= " items.guid = ? ";
            $msb_types .= "s";
            $msb_params[] = $guid;
            $fccount++;
        }
        $guid_clause .= " ) ";
    } else {
        $fccount = 1;
        $guid_clause .= " items.guid = ? ";
        $msb_types .= "s";
        $msb_params[] = $guids;
    }

    //Feed ID
    $msb_types .= "s";
    $msb_params[] = $feedguid;

    //Look for the url in the feed table
    $stmt = "SELECT 
          items.id,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,          
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.image AS feedImage,
          CRC32(REPLACE(REPLACE(feeds.image, 'https://', ''), 'http://', '')) as feedImageUrlHash,   
          items.image AS itemImage,
          CRC32(REPLACE(REPLACE(items.image, 'https://', ''), 'http://', '')) as imageUrlHash,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          guids.guid AS podcastGuid,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          feedval.value_block AS feedValueBlock
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfguids AS guids ON items.feedid = guids.feedid
         LEFT JOIN nfvalue AS feedval ON feedval.feedid = guids.feedid
        WHERE $guid_clause
         AND guids.guid = ?
    ";
    loggit(3, "SQL: $stmt");
    loggit(3, "Types: $msb_types");
    loggit(3, "Params: " . print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No episode found with guid|feedid: [$guid|$feedguid]");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iseason,
        $iduration,
        $fitunesid,
        $fimage,
        $fimageurlhash,
        $iimage,
        $iimageurlhash,
        $ftitle,
        $fid,
        $flanguage,
        $fguid,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $feedValueBlock,
    ) or loggit(2, "MySql error: " . $dbh->error);

    if (empty($iimage)) {
        $iimage = $fimage;
    }

    //Build the return results
    $items = array();
    $persons = [];
    $transcripts = [];
    $count = 0;
    while ($sql->fetch()) {
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iseason,
                'image' => $iimage,
                'imageUrlHash' => $iimageurlhash,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedImageUrlHash' => $fimageurlhash,
                'feedId' => $fid,
                'feedTitle' => $ftitle,
                'feedLanguage' => $flanguage,
                'podcastGuid' => $fguid,
                'chaptersUrl' => $ichapters
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            $items[$iid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$iid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if (is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        } else if (!empty($feedValueBlock)) {
            $feedValueBlock = json_decode($feedValueBlock, TRUE);
            if (is_array($feedValueBlock) && isset($feedValueBlock['model']) && isset($feedValueBlock['destinations'])) {
                $items[$iid]['value'] = $feedValueBlock;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    foreach ($items as $item) {
        $episodes[] = $item;
    }

    //Log and leave
    //loggit(3, "Returning episode with guid|feedid: [$guid|$feedguid]");
    return ($episodes);
}


/**
 * Retrieves feed IDs that are tagged with a specific medium.
 *
 * @param string|null $medium Medium filter (e.g., music, video, film, audiobook, newsletter, blog).
 * @param int $max Maximum number of feed IDs to return.
 * @return array<int>|null List of feed IDs, or null on invalid input.
 */
function get_feeds_with_medium($medium = NULL, $max = 10000)
{
    //Check parameters
    if (empty($medium) || (
            //The "podcast" medium isn't usable because it's the entire index
            $medium != "music"
            && $medium != "video"
            && $medium != "film"
            && $medium != "audiobook"
            && $medium != "newsletter"
            && $medium != "blog"
        )
    ) {
        loggit(2, "The medium argument is blank or corrupt: [$medium]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT feedid 
          FROM nfmediums 
         WHERE medium=? 
         ORDER BY feedid ASC 
         LIMIT ?
         ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $medium, $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with medium: [$medium].");
        return (array());
    }
    $sql->bind_result($fid) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $feeds[$count] = $fid;
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds for medium: [$medium].");
    return ($feeds);
}


/**
 * Sets a medium tag for a given feed.
 *
 * Inserts a row into `nfmediums` for the feed/medium pair. Valid media are:
 * `music`, `video`, `film`, `audiobook`, `newsletter`, `blog`.
 * The "podcast" medium is intentionally excluded (it represents the entire index).
 *
 * @param int|null $fid Feed ID. Must be numeric.
 * @param string|null $medium Medium to set (one of the allowed values listed above).
 * @return bool True on success, false on invalid feed ID; null on invalid medium.
 */
function set_feed_medium($fid = NULL, $medium = NULL)
{
    //Check parameters
    if (empty($fid) || !is_numeric($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (FALSE);
    }
    if (empty($medium) || (
            //The "podcast" medium isn't usable because it's the entire index
            $medium != "music"
            && $medium != "video"
            && $medium != "film"
            && $medium != "audiobook"
            && $medium != "newsletter"
            && $medium != "blog"
        )
    ) {
        loggit(2, "The medium argument is blank or corrupt: [$medium]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $stmt = "INSERT INTO nfmediums (feedid, medium) VALUES(?,?)";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("ds", $fid, $medium) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->close();

    //Log and leave
    //loggit(3, "Set medium to: [$medium] for feed id: [$fid].");

    return (TRUE);
}

/**
 * Retrieves feeds that have at least one episode with a transcript.
 *
 * @param int|null $max Maximum number of feeds to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of feeds with transcripts.
 */
function get_feeds_with_transcript($max = NULL, $fulltext = FALSE)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the query
    $sqltxt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash,
          val.value_block
        FROM nfvalue AS val
         JOIN newsfeeds AS newsfeeds ON val.feedid = newsfeeds.id
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
        ORDER BY newsfeeds.popularity DESC
    ";

    //Execute
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //Check result count
    if ($sql->num_rows() < 1) {
        $sql->close() or loggit(3, "MySql error: " . $dbh->error);
        loggit(2, "There are no feeds with value blocks.");
        return (array());
    }

    //Set bindings
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fundingurl,
        $fundingmessage,
        $fcatids,
        $fimageurlhash,
        $fvalblock
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'imageUrlHash' => $fimageurlhash,
            'value' => $fvalblock
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //loggit(3, "Returning: [$count] feeds with value blocks.");
    return ($feeds);
}


/**
 * Retrieves currently live episodes with optional language/category filters and pagination.
 *
 * @param int|null $since Unix timestamp lower bound.
 * @param int|null $max Maximum number of episodes to return.
 * @param array<int, string>|null $languages Language codes to include.
 * @param array<int, int|string>|null $exclude_categories Category IDs or names to exclude.
 * @param array<int, int|string>|null $include_categories Category IDs or names to include.
 * @param int|null $before_id Return items with ID less than this value.
 * @param bool|null $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of live episodes.
 */
function get_live_episodes_with_filters($since = NULL, $max = NULL, $languages = NULL, $exclude_categories = NULL, $include_categories = NULL, $before_id = NULL, $fulltext = NULL)
{
    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $msb_types = "";
    $msb_params = [];
    $msb_types_l = "";
    $msb_params_l = [];
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $weekago = $nowtime - (86400 * 7);
    $orderby_clause = " ORDER BY items.id DESC ";

    //Was an item id specified?
    $since_clause = "";
    if (!empty($before_id) && is_numeric($before_id)) {
        $since_clause = " items.timestamp < $nowtime AND items.id < ? ";
        $msb_types .= "d";
        $msb_params[] = $before_id;
    } else {
        //Determine time range
        if (empty($since) || !is_numeric($since)) $since = $weekago;
        if ($since < $weekago) {
            $since = $weekago;
        }
        $since_clause = " items.timestamp < $nowtime AND items.timestamp >= ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Language filter
    $lcount = 0;
    $language_clause = "";
    if (!empty($languages)) {
        foreach ($languages as $language) {
            if (!empty($language)) {
                $language = strtolower($language);
                if ($language == "unknown") $language = "";
                if ($lcount == 0) {
                    $language_clause .= " AND ( LOWER(feeds.language) = ? ";
                } else {
                    $language_clause .= " OR LOWER(feeds.language) = ? ";
                }
                $lcount++;
                $msb_types .= "s";
                $msb_types_l .= "s";
                $msb_params[] = $language;
                $msb_params_l[] = $language;
            }
        }
        if ($lcount > 0) {
            $language_clause .= " ) ";
        }
    }

    //We need a fast name to index lookup if someone passed categories as strings
    if (!empty($include_categories) || !empty($exclude_categories)) {
        $categorynames_lc = array_map('strtolower', $cg_categorynames);
        $categorynames_flipped = array_flip($categorynames_lc);
    }

    //Category inclusions
    $categories_join_type = "LEFT";
    $category_include_clause = "";
    if (!empty($include_categories)) {
        //$orderby_clause = "";
        $categories_join_type = "";
        $cilcount = 0;
        $category_include_clause .= " AND ( ";
        foreach ($include_categories as $include_category) {
            if (!is_numeric($include_category)) $include_category = $categorynames_flipped[strtolower($include_category)];
            if ($include_category > 0) {
                if ($cilcount > 0) {
                    $category_include_clause .= " OR ";
                }
                $category_include_clause .= " (? IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) $language_clause ) ";
                $msb_types .= "d";
                $msb_types .= $msb_types_l;
                $msb_params[] = $include_category;
                $msb_params = array_merge($msb_params, $msb_params_l);
                $cilcount++;
            }
        }
        $category_include_clause .= " ) ";
    }

    //Category exclusions (only apply if there were no inclusions given)
    $category_exclude_clause = "";
    if (!empty($exclude_categories)) {
        //$orderby_clause = "";
        $categories_join_type = "";
        foreach ($exclude_categories as $exclude_category) {
            if (!is_numeric($exclude_category)) $exclude_category = $categorynames_flipped[strtolower($exclude_category)];
            if ($exclude_category > 0) {
                $category_exclude_clause .= " AND ? NOT IN (cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) ";
                $msb_types .= "d";
                $msb_params[] = $exclude_category;
            }
        }
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > $cg_default_max_list) {
        $max = $cg_default_max_list;
    }
    $msb_types .= "d";
    $msb_params[] = $max;


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $stmt = "SELECT
          items.id,
          items.title,
          items.link,          
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          feeds.itunes_id,
          feeds.image AS feedImage,
          items.image AS itemImage,
          feeds.title AS feedTitle,
          feeds.id AS feedId,
          feeds.language AS feedLanguage,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds
        FROM nfliveitems AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id
         $categories_join_type JOIN nfcategories AS cat ON cat.feedid = items.feedid
        WHERE
          $since_clause
          $language_clause
          $category_include_clause
          $category_exclude_clause
        $orderby_clause
        LIMIT ?
    ";
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_params, TRUE));
    //loggit(3, print_r($msb_types, TRUE) . print_r($msb_params, TRUE));

    //Parameter binding
    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);

    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No recent feeds returned. This is odd.");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ititle,
        $ilink,
        //$idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $fitunesid,
        $fimage,
        $iimage,
        $ftitle,
        $fid,
        $flanguage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = [];
    $count = 0;
    while ($sql->fetch()) {
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        $items[] = array(
            'id' => $iid,
            'title' => $ititle,
            'link' => $ilink,
            //'description' => $idescription,
            'guid' => $iguid,
            'datePublished' => $itimestamp,
            'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
            'dateCrawled' => $itimeadded,
            'enclosureUrl' => $ienclosureurl,
            'enclosureType' => $ienclosuretype,
            'enclosureLength' => $ienclosurelength,
            'explicit' => $iexplicit,
            'image' => $iimage,
            'feedItunesId' => $fitunesid,
            'feedImage' => $fimage,
            'feedId' => $fid,
            'feedTitle' => $ftitle,
            'feedLanguage' => $flanguage,
            'categories' => $categories
        );
        $count++;
    }
    $sql->close();

    //loggit(3, print_r($cg_categorynames, TRUE));

    //Log and leave
    //loggit(3, "Returning: [$count] live episodes.");
    return ($items);
}


/**
 * Retrieves a feed by its iTunes ID and includes categories and extended fields.
 *
 * @param int|null $itunesId iTunes ID of the feed.
 * @param bool $fulltext Include full text fields when true.
 * @return array<string, mixed> Feed details as an associative array (empty if not found).
 */
function get_feed_by_itunes_id2($itunesId = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($itunesId)) {
        loggit(2, "The itunesId argument is blank or corrupt: [$itunesId]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.explicit,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          guids.guid,
          newsfeeds.podcast_locked,
          newsfeeds.item_count,
          funding.url,
          funding.message,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nffunding AS funding ON funding.feedid = newsfeeds.id
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
        WHERE newsfeeds.itunes_id=? 
        ORDER BY newsfeeds.newest_item_pubdate DESC
        LIMIT $cg_default_max_search_results
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $itunesId) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that itunes id: [$itunesId].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $fexplicit,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $fguid,
        $flocked,
        $fitemcount,
        $fundingurl,
        $fundingmessage,
        $fcatids
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'explicit' => $fexplicit,
            'language' => $flanguage,
            'podcastGuid' => $fguid,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'episodeCount' => $fitemcount
        );
        if ($fundingurl !== NULL) {
            $feeds[$count]['funding'] = array(
                'url' => $fundingurl,
                'message' => $fundingmessage
            );
        }
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match itunes id: [$itunesId].");
    return ($feeds[0]);
}


/**
 * Checks whether a feed is marked as dead in the database.
 *
 * A feed is considered "dead" when its `dead` column in the `newsfeeds` table is non-zero.
 *
 * @param int|null $fid Feed ID to check.
 * @return bool|null TRUE if the feed is marked dead; FALSE if no matching dead record is found;
 *                   NULL when the input is empty/invalid.
 */
function feed_is_dead($fid = NULL)
{
    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id argument is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sql = $dbh->prepare("SELECT id 
                                  FROM $cg_table_newsfeeds 
                                 WHERE id = ? 
                                   AND dead != 0
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $fid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);

    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        loggit(2, "No dead feed found with id: [$fid].");
        return (FALSE);
    }

    $sql->close();

    //Log and leave
    loggit(1, "Feed: [$fid] is dead.");
    return (TRUE);
}


/**
 * Retrieves feeds recently added to the index with cursor and optional developer filter.
 *
 * @param int|null $start_at Starting feed ID cursor.
 * @param int|null $max Maximum number of feeds to return.
 * @param string $direction Sort direction: ASC or DESC.
 * @param int|null $developer Developer ID to filter by.
 * @return array<int, array<string, mixed>> List of recently added feeds with basic status.
 */
function get_recently_added_feeds2($start_at = NULL, $max = NULL, $direction = "ASC", $developer = NULL)
{

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $hourago = $nowtime - 3600;
    $yesterday = $nowtime - 86400;
    $defaultstart = $nowtime - (86400 * 30);
    $since = 2;
    $msb_types = "";
    $msb_values = [];

    //Check parameters
    if (!empty($start_at) && is_numeric($start_at) && $start_at > 0) {
        $since = $start_at;
    }
    $msb_types .= "d";
    $msb_values[] = $since;

    $developer_clause = "";
    if (!empty($developer) && is_numeric($developer)) {
        $developer_clause = " AND developerid=? ";
        $msb_types .= "d";
        $msb_values[] = $developer;
    }

    if (empty($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 25000) {
        $max = 25000;
    }
    $msb_types .= "d";
    $msb_values[] = $max;

    $dir_op = "<=";
    if ($direction != "DESC") {
        $direction = "ASC";
        $dir_op = ">=";
    }


    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Look for the url in the feed table
    $sqltxt = "
        SELECT
          feedsadded.feedid,
          newsfeeds.url,
          feedsadded.time_added,
          newsfeeds.lastparse,
          newsfeeds.contenthash,
          newsfeeds.language,
          newsfeeds.image,
          newsfeeds.dead
        FROM $cg_table_feeds_added AS feedsadded          
        LEFT JOIN $cg_table_newsfeeds AS newsfeeds ON feedsadded.feedid = newsfeeds.id
        WHERE feedsadded.feedid $dir_op ?
        $developer_clause
        ORDER BY feedsadded.feedid $direction
        LIMIT ?
    ";
    //loggit(3, $sqltxt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_values, TRUE));
    $sql = $dbh->prepare($sqltxt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param($msb_types, ...$msb_values) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No added feeds since: [$since].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $furl,
        $ftimeadded,
        $flastparse,
        $fcontenthash,
        $flanguage,
        $fimage,
        $fdead
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = [];
    $count = 0;
    while ($sql->fetch()) {
        $status = "confirmed";
        if (empty($flastparse)) {
            $status = "pending";
        }
        $feeds[] = array(
            'id' => $fid,
            'url' => $furl,
            'timeAdded' => $ftimeadded,
            'status' => $status,
            'contentHash' => $fcontenthash,
            'language' => $flanguage,
            'image' => $fimage,
            'dead' => $fdead
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] recently added feeds.");
    return ($feeds);
}


/**
 * Retrieves currently live items for a given feed ID.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $since Return items with live start time after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of live items.
 */
function get_live_items_by_feed_id($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " items.feedid = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " items.feedid = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $vmax = $max * 5;

    $msb_types .= "d";
    $msb_params[] = $vmax;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.start_time,
          items.end_time,
          items.status,
          items.content_link,
          feeds.itunes_id,
          feeds.url,
          feeds.image,
          items.image,
          feeds.language,
          feeds.dead,
          feeds.duplicateof,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          guids.guid
        FROM nfliveitems AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfguids AS guids ON guids.feedid = feeds.id
        WHERE 
         $feedid_clause
         AND items.timestamp < $nowtime
         $since_clause         
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $istart,
        $iend,
        $istatus,
        $icontentlink,
        $fitunesid,
        $furl,
        $fimage,
        $iimage,
        $flanguage,
        $fdead,
        $fduplicateof,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $persons = [];
    $transcripts = [];
    $count = 0;
    while ($sql->fetch()) {
        //Live Status
        $liveStatus = "pending";
        if ($istatus == 1) {
            $liveStatus = "live";
        }
        if ($istatus == 2) {
            $liveStatus = "ended";
        }
        //Fix amps
        if (stripos($ienclosureurl, '&amp;') !== FALSE) {
            $ienclosureurl = str_ireplace('&amp;', '&', $ienclosureurl);
        }

        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'startTime' => $istart,
                'endTime' => $iend,
                'status' => $liveStatus,
                'contentLink' => $icontentlink,
                'duration' => NULL,
                'explicit' => $iexplicit,
                'episode' => NULL,
                'episodeType' => NULL,
                'season' => NULL,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedUrl' => $furl,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'podcastGuid' => $fguid,
                'feedLanguage' => $flanguage,
                'feedDead' => $fdead,
                'feedDuplicateOf' => $fduplicateof,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            $items[$iid]['soundbite'] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
            $items[$iid]['soundbites'][] = array(
                'startTime' => $isbstarttime,
                'duration' => $isbduration,
                'title' => $isbtitle
            );
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    if (is_array($fid)) {
        foreach ($fid as $feedid) {
            //loggit(3, "Returning episodes for feed id: [$feedid].");
        }
    } else {
        //loggit(3, "Returning episodes for feed id: [$fid|$count|$ecount].");
    }
    return ($episodes);
}


/**
 * Retrieves items for one or more feeds by iTunes ID with optional time and enclosure filters.
 *
 * @param int|array<int>|null $fid Single iTunes ID or array of iTunes IDs.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @param string|null $enclosure Optional exact enclosure URL to match.
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_itunes_id3($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE, $enclosure = NULL)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " feeds.itunes_id = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " feeds.itunes_id = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND items.timestamp > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //A specific enclosure is wanted
    $enclosure_clause = "";
    if (!empty($enclosure)) {
        $enclosure_clause = " AND items.enclosure_url = ? ";
        $msb_types .= "s";
        $msb_params[] = $enclosure;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 1000) {
        $max = 1000;
    }
    $vmax = $max * 5;
    $msb_types .= "d";
    $msb_params[] = $vmax;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          items.description,
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.image,
          items.image,
          feeds.language,
          feeds.dead,
          feeds.duplicateof,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          social.uri,
          social.protocol,
          social.accountId,
          social.accountUrl,
          social.priority
        FROM $cg_table_newsfeed_items AS items
         JOIN $cg_table_newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfitem_socialinteract AS social ON items.id = social.itemid
        WHERE 
         $feedid_clause
         AND items.timestamp < $nowtime
         $since_clause
         $enclosure_clause
        ORDER BY items.timestamp DESC 
        LIMIT ?
    ";
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $fimage,
        $iimage,
        $flanguage,
        $fdead,
        $fduplicateof,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $socialuri,
        $socialprotocol,
        $socialaccountid,
        $socialaccounturl,
        $socialpriority
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $soundbites = [];
    $persons = [];
    $transcripts = [];
    $socialInteracts = [];
    $count = 0;
    while ($sql->fetch()) {
        //Fix amps
        if (stripos($ienclosureurl, '&amp;') !== FALSE) {
            $ienclosureurl = str_ireplace('&amp;', '&', $ienclosureurl);
        }

        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'feedLanguage' => $flanguage,
                'feedDead' => $fdead,
                'feedDuplicateOf' => $fduplicateof,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            if (!in_array((string)($isbstarttime . $isbduration), $soundbites)) {
                $items[$iid]['soundbites'][] = array(
                    'startTime' => $isbstarttime,
                    'duration' => $isbduration,
                    'title' => $isbtitle
                );
                $soundbites[] = (string)($isbstarttime . $isbduration);
            }
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Social interact
        if (!empty($socialuri)) {
            if (!in_array($socialuri, $socialInteracts)) {
                $socialprotocoltext = 'activitypub';
                if ($socialprotocol == 2) {
                    $socialprotocoltext = 'twitter';
                }
                $items[$iid]['socialInteract'][] = array(
                    'uri' => $socialuri,
                    'protocol' => $socialprotocoltext,
                    'accountId' => $socialaccountid,
                    'accountUrl' => $socialaccounturl,
                    'priority' => $socialpriority
                );
                $socialInteracts[] = $socialuri;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    if (is_array($fid)) {
        foreach ($fid as $feedid) {
            //loggit(3, "Returning episodes for feed id: [$feedid].");
        }
    } else {
        //loggit(3, "Returning episodes for feed id: [$fid|$count|$ecount].");
    }
    return ($episodes);
}


/**
 * Retrieves feeds by a list of feed IDs and includes value blocks and extended fields.
 *
 * @param array<int> $fids Feed IDs to fetch.
 * @param bool $fulltext Include full text fields when true.
 * @param int|null $max Maximum number of feeds to return.
 * @param bool $withexplicit Include explicit feeds when true.
 * @param bool $withdead Include dead feeds when true.
 * @return array<int, array<string, mixed>> List of feeds with extended metadata.
 */
function get_feeds_by_id_array2($fids = array(), $fulltext = FALSE, $max = NULL, $withexplicit = TRUE, $withdead = FALSE)
{
    //Check parameters
    if (empty($fids)) {
        loggit(2, "The feed id array argument is blank.");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Assemble the feed id list to get
    $assembled_feed_list = "";
    $count = 0;
    foreach ($fids as $fid) {
        if ($count == 0) {
            $assembled_feed_list .= " ( ";
        }
        $assembled_feed_list .= " newsfeeds.id = $fid ";
        if (isset($fids[$count + 1])) {
            $assembled_feed_list .= "OR ";
        }
        $count++;
    }
    $assembled_feed_list .= " ) ";

    if (!$withexplicit) {
        $assembled_feed_list .= " AND explicit = 0 ";
    }

    if (empty($max)) {
        $max = $cg_default_max_search_results;
    }
    if ($max > 1000) {
        $max = 1000;
    }

    $dead_clause = " AND dead=0";
    if ($withdead) {
        $dead_clause = "";
    }

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.createdon,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.parsenow,
          newsfeeds.priority,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.explicit,
          guids.guid,
          mediums.medium,
          newsfeeds.item_count,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash,
          value.value_block
        FROM $cg_table_newsfeeds AS newsfeeds
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
         LEFT JOIN nfmediums AS mediums ON mediums.feedid = newsfeeds.id
         LEFT JOIN nfvalue AS value ON value.feedid = newsfeeds.id
        WHERE $assembled_feed_list $dead_clause
        GROUP BY newsfeeds.id
        ORDER BY popularity DESC, newest_item_pubdate DESC
        LIMIT ?
    ";
    //loggit(3, $stmt);
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "Could not retrieve feeds for search result lookup.");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $fcreatedon,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fparsenow,
        $fpriority,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fexplicit,
        $fguid,
        $fmedium,
        $fitemcount,
        $fcatids,
        $fimageurlhash,
        $fvalueblock
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        if (!empty($fduplicateof)) {
            if (in_array($fduplicateof, $fids)) {
                continue;
            }
        }
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $explicit = FALSE;
        if ($fexplicit == 1) $explicit = TRUE;
        if (empty($fmedium)) $fmedium = "podcast";
        if (empty($categories)) $categories = NULL;
        $feeds[] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'inPollingQueue' => $fparsenow,
            'priority' => $fpriority,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'createdOn' => $fcreatedon,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'explicit' => $explicit,
            'podcastGuid' => $fguid,
            'medium' => $fmedium,
            'episodeCount' => $fitemcount,
            'imageUrlHash' => $fimageurlhash,
            'newestItemPubdate' => $fnewestitemdate,
            'valueBlock' => $fvalueblock
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds that match the feed id array.");
    return ($feeds);
}


/**
 * Retrieves value time split segments for a given episode ID.
 *
 * @param int|null $eid Episode ID.
 * @return array<int, array<string, int|string>> List of value time splits with timing and destination info.
 */
function get_valuetimesplits_by_episode_id($eid = NULL)
{

    //Check parameters
    if (empty($eid)) {
        loggit(2, "The episode id argument is blank or corrupt: [$eid]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          episodes.id,
          vts.start_time,
          vts.duration,
          vts.remote_start_time,
          vts.remote_percentage,
          vts.feed_guid,
          vts.feed_url,
          vts.item_guid,
          vts.medium
        FROM nfitems AS episodes
         JOIN nfitem_valuetimesplits AS vts ON vts.itemid = episodes.id
        WHERE episodes.id=?
    ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("d", $eid) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with that id: [$eid].");
        return (array());
    }
    $sql->bind_result(
        $eid,
        $fstarttime,
        $fduration,
        $fremotestarttime,
        $fremotepercentage,
        $ffeedguid,
        $ffeedurl,
        $fitemguid,
        $fmedium
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $episodes = array();
    $count = 0;
    while ($sql->fetch()) {
        $episodes[$count] = array(
            'startTime' => $fstarttime,
            'duration' => $fduration,
            'remoteStartTime' => $fremotestarttime,
            'remotePercentage' => $fremotepercentage,
            'feedGuid' => $ffeedguid,
            'feedUrl' => $ffeedurl,
            'itemGuid' => $fitemguid,
            'medium' => $fmedium,
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning value block for feed id: [$eid].");
    return ($episodes);
}


/**
 * Retrieves feeds that are tagged with a specific medium, returning extended fields.
 *
 * @param string|null $medium Medium filter (e.g., music, video, film, audiobook, newsletter, blog).
 * @param bool $fulltext Include full text fields when true.
 * @param int $max Maximum number of feeds to return.
 * @return array<int, array<string, mixed>> List of feeds with the specified medium.
 */
function get_feeds_with_medium2($medium = NULL, $fulltext = FALSE, $max = 10000)
{
    //Check parameters
    if (empty($medium) || (
            //The "podcast" medium isn't usable because it's the entire index
            $medium != "music"
            && $medium != "video"
            && $medium != "film"
            && $medium != "audiobook"
            && $medium != "newsletter"
            && $medium != "blog"
        )
    ) {
        loggit(2, "The medium argument is blank or corrupt: [$medium]");
        return (NULL);
    }

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Do the query
    $sql = $dbh->prepare("
        SELECT 
          newsfeeds.id,
          newsfeeds.title,
          newsfeeds.url,
          newsfeeds.link,
          newsfeeds.lastupdate,
          newsfeeds.lastcheck,
          newsfeeds.errors,
          newsfeeds.lasthttpstatus,
          newsfeeds.contenttype,
          newsfeeds.itunes_id,
          newsfeeds.artwork_url_600,
          newsfeeds.description,
          newsfeeds.image,
          newsfeeds.type,
          newsfeeds.generator,
          newsfeeds.lastgoodhttpstatus,
          newsfeeds.dead,
          newsfeeds.original_url,
          newsfeeds.lastparse,
          newsfeeds.parsenow,
          newsfeeds.priority,
          newsfeeds.newest_item_pubdate,
          newsfeeds.parse_errors,
          newsfeeds.itunes_author,
          newsfeeds.itunes_owner_email,
          newsfeeds.itunes_owner_name,
          newsfeeds.language,
          newsfeeds.podcast_locked,
          newsfeeds.explicit,
          guids.guid,
          mediums.medium,
          newsfeeds.item_count,
          CONCAT_WS(';',cat.catid1,cat.catid2,cat.catid3,cat.catid4,cat.catid5,cat.catid6,cat.catid7,cat.catid8,cat.catid9,cat.catid10) AS categoryIds,
          CRC32(REPLACE(REPLACE(image, 'https://', ''), 'http://', '')) as imageUrlHash      
        FROM nfmediums AS mediums
         JOIN newsfeeds AS newsfeeds ON newsfeeds.id = mediums.feedid
         LEFT JOIN nfcategories AS cat ON cat.feedid = newsfeeds.id
         LEFT JOIN nfguids AS guids ON guids.feedid = newsfeeds.id
        WHERE mediums.medium=? 
          AND dead=0
        ORDER BY newsfeeds.id DESC
        LIMIT ?
         ") or loggit(2, "MySql error: " . $dbh->error);
    $sql->bind_param("sd", $medium, $max) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    if ($sql->num_rows() < 1) {
        $sql->close();
        //loggit(3, "No feeds exist with medium: [$medium].");
        return (array());
    }
    $sql->bind_result(
        $fid,
        $ftitle,
        $furl,
        $flink,
        $flastupdate,
        $flastcheck,
        $ferrors,
        $flasthttpstatus,
        $fcontenttype,
        $fitunesid,
        $fartwork,
        $fdescription,
        $fimage,
        $ftype,
        $fgenerator,
        $flastgoodhttpstatus,
        $fdead,
        $foriginalurl,
        $flastparse,
        $fparsenow,
        $fpriority,
        $fnewestitemdate,
        $fparseerrors,
        $fauthor,
        $femail,
        $fname,
        $flanguage,
        $flocked,
        $fexplicit,
        $fguid,
        $fmedium,
        $fitemcount,
        $fcatids,
        $fimageurlhash
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    //Build the return results
    $feeds = array();
    $categories = array();
    $count = 0;
    while ($sql->fetch()) {
        $description = limit_words(strip_tags($fdescription), 100, TRUE);
        if ($fulltext) {
            $description = $fdescription;
        }
        $catids = array_filter(explode(';', $fcatids));
        $ccount = 0;
        $categories = array();
        foreach ($catids as $catid) {
            $categories[$catid] = $cg_categorynames[$catid];
            $ccount++;
        }
        $explicit = FALSE;
        if ($fexplicit == 1) $explicit = TRUE;
        if (empty($fmedium)) $fmedium = "podcast";
        if (empty($categories)) $categories = NULL;
        $feeds[$count] = array(
            'id' => $fid,
            'title' => $ftitle,
            'url' => $furl,
            'originalUrl' => $foriginalurl,
            'link' => $flink,
            'description' => $description,
            'author' => $fauthor,
            'ownerName' => $fname,
            'image' => $fimage,
            'artwork' => $fartwork,
            'lastUpdateTime' => $flastupdate,
            'lastCrawlTime' => $flastcheck,
            'lastParseTime' => $flastparse,
            'inPollingQueue' => $fparsenow,
            'priority' => $fpriority,
            'lastGoodHttpStatusTime' => $flastgoodhttpstatus,
            'lastHttpStatus' => $flasthttpstatus,
            'contentType' => $fcontenttype,
            'itunesId' => $fitunesid,
            'generator' => $fgenerator,
            'language' => $flanguage,
            'type' => $ftype,
            'dead' => $fdead,
            'crawlErrors' => $ferrors,
            'parseErrors' => $fparseerrors,
            'categories' => $categories,
            'locked' => $flocked,
            'explicit' => $explicit,
            'podcastGuid' => $fguid,
            'medium' => $fmedium,
            'episodeCount' => $fitemcount,
            'imageUrlHash' => $fimageurlhash,
            'newestItemPubdate' => $fnewestitemdate
        );
        $count++;
    }
    $sql->close();

    //Log and leave
    //loggit(3, "Returning: [$count] feeds for medium: [$medium].");
    return ($feeds);
}


/**
 * Retrieves items for a given feed ID with optional time and enclosure filters (extended variant).
 *
 * @param int|null $fid Feed ID.
 * @param int|null $since Return items published after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @param string|null $enclosure Optional exact enclosure URL to match.
 * @return array<int, array<string, mixed>> List of items.
 */
function get_items_by_feed_id4($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE, $enclosure = NULL)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " feedid = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " feedid = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND `timestamp` > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //A specific enclosure is wanted
    $enclosure_clause = "";
    if (!empty($enclosure)) {
        $enclosure_clause = " AND enclosure_url = ? ";
        $msb_types .= "s";
        $msb_params[] = $enclosure;
    }

    //Max return count
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 5000) {
        $max = 5000;
    }
    $vmax = $max * 5;
    $msb_types .= "d";
    $msb_params[] = $vmax;

    //Look for the url in the feed table
    $stmt = "
        SELECT 
          items.id,
          items.feedid,
          items.title,
          items.link,
          SUBSTRING(items.description, 1, 3000),
          items.guid,
          items.timestamp,
          items.timeadded,
          items.enclosure_url,
          items.enclosure_type,
          items.enclosure_length,
          items.itunes_explicit,
          items.itunes_episode,
          items.itunes_episode_type,
          items.itunes_season,
          items.itunes_duration,
          feeds.itunes_id,
          feeds.url,
          feeds.image,
          items.image,
          feeds.language,
          feeds.dead,
          feeds.duplicateof,
          chapters.url,
          transcripts.url,
          transcripts.type,
          soundbites.start_time,
          soundbites.duration,
          soundbites.title,
          persons.id, 
          persons.name,
          persons.role,
          persons.grp,
          persons.img,
          persons.href,
          val.value_block,
          social.uri,
          social.protocol,
          social.accountId,
          social.accountUrl,
          social.priority,
          vts.id,
          vts.start_time,
          vts.duration,
          vts.remote_start_time,
          vts.remote_percentage,
          vts.feed_guid,
          vts.feed_url,
          vts.item_guid,
          vts.medium,
          guids.guid
        FROM (
        	SELECT id,
                   feedid,
                   title,
                   link,
                   SUBSTRING(description, 1, 3000) as description,
                   guid,
                   `timestamp`,
                   timeadded,
                   enclosure_url,
                   enclosure_type,
                   enclosure_length,
                   itunes_explicit,
                   itunes_episode,
                   itunes_episode_type,
                   itunes_season,
                   itunes_duration,
                   image
            FROM nfitems
            WHERE 
              $feedid_clause
              AND `timestamp` <= UNIX_TIMESTAMP()
              $since_clause
              $enclosure_clause
            ORDER BY `timestamp` DESC
            LIMIT ?
        ) AS items
         JOIN newsfeeds AS feeds ON items.feedid = feeds.id 
         LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
         LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
         LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
         LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
         LEFT JOIN nfitem_value AS val ON items.id = val.itemid
         LEFT JOIN nfitem_socialinteract AS social ON items.id = social.itemid
         LEFT JOIN nfitem_valuetimesplits AS vts ON items.id = vts.itemid
         LEFT JOIN nfguids AS guids ON guids.feedid = feeds.id
       LIMIT 10000
    ";
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $rows = $sql->num_rows();
    if ($rows < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    //loggit(3, "Episode count: [$rows]");
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $furl,
        $fimage,
        $iimage,
        $flanguage,
        $fdead,
        $fduplicateof,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $socialuri,
        $socialprotocol,
        $socialaccountid,
        $socialaccounturl,
        $socialpriority,
        $vtsid,
        $vtsstarttime,
        $vtsduration,
        $vtsremotestarttime,
        $vtsremotepercentage,
        $vtsfeedguid,
        $vtsfeedurl,
        $vtsitemguid,
        $vtsmedium,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $soundbites = [];
    $persons = [];
    $transcripts = [];
    $socialInteracts = [];
    $valueTimeSplits = [];
    $count = 0;
    while ($sql->fetch()) {
        //Fix amps
        if (stripos($ienclosureurl, '&amp;') !== FALSE) {
            $ienclosureurl = str_ireplace('&amp;', '&', $ienclosureurl);
        }

        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedUrl' => $furl,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'podcastGuid' => $fguid,
                'feedLanguage' => $flanguage,
                'feedDead' => $fdead,
                'feedDuplicateOf' => $fduplicateof,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            if (!in_array((string)($isbstarttime . $isbduration), $soundbites)) {
                $items[$iid]['soundbites'][] = array(
                    'startTime' => $isbstarttime,
                    'duration' => $isbduration,
                    'title' => $isbtitle
                );
                $soundbites[] = (string)($isbstarttime . $isbduration);
            }
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Social interact
        if (!empty($socialuri)) {
            if (!in_array($socialuri, $socialInteracts)) {
                $socialprotocoltext = 'activitypub';
                if ($socialprotocol == 2) {
                    $socialprotocoltext = 'twitter';
                }
                $items[$iid]['socialInteract'][] = array(
                    'uri' => $socialuri,
                    'protocol' => $socialprotocoltext,
                    'accountId' => $socialaccountid,
                    'accountUrl' => $socialaccounturl,
                    'priority' => $socialpriority
                );
                $socialInteracts[] = $socialuri;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }
        //Value time splits
        if (!empty($vtsstarttime) && !empty($vtsduration)) {
            if (!in_array($vtsid, $valueTimeSplits)) {
                $items[$iid]['timesplits'][] = array(
                    'startTime' => $vtsstarttime,
                    'duration' => $vtsduration,
                    'remoteStartTime' => $vtsremotestarttime,
                    'remotePercentage' => $vtsremotepercentage,
                    'feedGuid' => $vtsfeedguid,
                    'feedUrl' => $vtsfeedurl,
                    'itemGuid' => $vtsitemguid,
                    'medium' => $vtsmedium
                );
                $valueTimeSplits[] = $vtsid;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    if (is_array($fid)) {
        foreach ($fid as $feedid) {
            //loggit(3, "Returning episodes for feed id: [$feedid].");
        }
    } else {
        //loggit(3, "Returning episodes for feed id: [$fid|$count|$ecount].");
    }
    return ($episodes);
}


/**
 * Retrieves the newest items for a given feed ID starting at an optional timestamp.
 *
 * @param int|null $fid Feed ID.
 * @param int|null $since Return items with publish time after this Unix timestamp.
 * @param int|null $max Maximum number of items to return.
 * @param bool $fulltext Include full text fields when true.
 * @return array<int, array<string, mixed>> List of newest items for the feed.
 */
function get_newest_items_by_feed_id($fid = NULL, $since = NULL, $max = NULL, $fulltext = FALSE)
{

    //Check parameters
    if (empty($fid)) {
        loggit(2, "The feed id is blank or corrupt: [$fid]");
        return (NULL);
    }

    //Helper vars
    $nowtime = time() - 1;
    $fifteenminutesago = $nowtime - 900;
    $yearago = $nowtime - (86400 * 365);

    //Binders for mysql params
    $msb_types = "";
    $msb_params = [];

    //Includes
    include get_cfg_var("global_conf") . '/includes/env.php';

    //Connect to the database server
    $dbh = new mysqli($cg_dbhost, $cg_dbuser, $cg_dbpass, $cg_dbname) or loggit(2, "MySql error: " . $dbh->error);

    //Max return count (in upper statement)
    if (empty($max) || !is_numeric($max)) {
        $max = $cg_default_max_list;
    }
    if ($max > 5000) {
        $max = 5000;
    }
    $vmax = $max * 5;

    //Build the feed id array
    $feedid_clause = "";
    if (is_array($fid)) {
        $fccount = 0;
        foreach ($fid as $feedid) {
            if ($fccount == 0) $feedid_clause .= " ( ";
            if ($fccount > 0) $feedid_clause .= " OR ";
            $feedid_clause .= " feedid = ? ";
            $msb_types .= "d";
            $msb_params[] = $feedid;
            $fccount++;
        }
        $feedid_clause .= " ) ";
    } else {
        $feedid_clause .= " feedid = ? ";
        $msb_types .= "d";
        $msb_params[] = $fid;
    }

    //Determine time range
    $since_clause = "";
    if (!empty($since) && is_numeric($since)) {
        $since_clause = " AND `timestamp` > ? ";
        $msb_types .= "d";
        $msb_params[] = $since;
    }

    //Max return count in lower part of statement
    $msb_types .= "d";
    $msb_params[] = $vmax;
    $msb_types .= "d";
    $msb_params[] = $vmax;

    //Look for the url in the feed table
    $stmt = "
        WITH items AS (
            SELECT *,
                ROW_NUMBER() OVER (PARTITION BY feedid ORDER BY `timestamp` DESC) AS rn
            FROM nfitems AS episodes 
            WHERE 
                $feedid_clause
                AND `timestamp` <= UNIX_TIMESTAMP()
                $since_clause
            LIMIT ?
        )
        SELECT 
            items.id,
            items.feedid,
            items.title,
            items.link,
            items.description,
            items.guid,
            items.timestamp,
            items.timeadded,
            items.enclosure_url,
            items.enclosure_type,
            items.enclosure_length,
            items.itunes_explicit,
            items.itunes_episode,
            items.itunes_episode_type,
            items.itunes_season,
            items.itunes_duration,
            feeds.itunes_id,
            feeds.title,
            feeds.url,
            feeds.image,
            items.image,
            feeds.language,
            feeds.dead,
            feeds.duplicateof,
            chapters.url,
            transcripts.url,
            transcripts.type,
            soundbites.start_time,
            soundbites.duration,
            soundbites.title,
            persons.id, 
            persons.name,
            persons.role,
            persons.grp,
            persons.img,
            persons.href,
            val.value_block,
            social.uri,
            social.protocol,
            social.accountId,
            social.accountUrl,
            social.priority,
            vts.id,
            vts.start_time,
            vts.duration,
            vts.remote_start_time,
            vts.remote_percentage,
            vts.feed_guid,
            vts.feed_url,
            vts.item_guid,
            vts.medium,
            funding.url,
            funding.message,
            guids.guid
        FROM items
            JOIN newsfeeds AS feeds ON items.feedid = feeds.id 
            LEFT JOIN nfitem_chapters AS chapters ON items.id = chapters.itemid
            LEFT JOIN nfitem_transcripts AS transcripts ON items.id = transcripts.itemid
            LEFT JOIN nfitem_soundbites AS soundbites ON items.id = soundbites.itemid
            LEFT JOIN nfitem_persons AS persons ON items.id = persons.itemid
            LEFT JOIN nfitem_value AS val ON items.id = val.itemid
            LEFT JOIN nfitem_socialinteract AS social ON items.id = social.itemid
            LEFT JOIN nfitem_valuetimesplits AS vts ON items.id = vts.itemid
            LEFT JOIN nfguids AS guids ON guids.feedid = feeds.id
            LEFT JOIN nffunding AS funding ON funding.feedid = feeds.id
        WHERE rn = 1
        LIMIT ?
    ";
    //loggit(3, $stmt);
    //loggit(3, print_r($msb_types, TRUE));
    //loggit(3, print_r($msb_params, TRUE));
    $sql = $dbh->prepare($stmt) or loggit(2, "MySql error: " . $dbh->error);

    $sql->bind_param($msb_types, ...$msb_params) or loggit(2, "MySql error: " . $dbh->error);
    $sql->execute() or loggit(2, "MySql error: " . $dbh->error);
    $sql->store_result() or loggit(2, "MySql error: " . $dbh->error);
    //See if any rows came back
    $rows = $sql->num_rows();
    if ($rows < 1) {
        $sql->close();
        //loggit(3, "No items exist for feed id: [$fid].");
        return (array());
    }
    //loggit(3, "Episode count: [$rows]");
    $sql->bind_result(
        $iid,
        $ifid,
        $ititle,
        $ilink,
        $idescription,
        $iguid,
        $itimestamp,
        $itimeadded,
        $ienclosureurl,
        $ienclosuretype,
        $ienclosurelength,
        $iexplicit,
        $iepisode,
        $iepisodetype,
        $iepisodeseason,
        $iduration,
        $fitunesid,
        $ftitle,
        $furl,
        $fimage,
        $iimage,
        $flanguage,
        $fdead,
        $fduplicateof,
        $ichapters,
        $itranscripturl,
        $itranscripttype,
        $isbstarttime,
        $isbduration,
        $isbtitle,
        $pid,
        $pname,
        $prole,
        $pgroup,
        $pimg,
        $phref,
        $valueblock,
        $socialuri,
        $socialprotocol,
        $socialaccountid,
        $socialaccounturl,
        $socialpriority,
        $vtsid,
        $vtsstarttime,
        $vtsduration,
        $vtsremotestarttime,
        $vtsremotepercentage,
        $vtsfeedguid,
        $vtsfeedurl,
        $vtsitemguid,
        $vtsmedium,
        $funurl,
        $funmessage,
        $fguid
    ) or loggit(2, "MySql error: " . $dbh->error);

    //Build the return results
    $items = array();
    $soundbites = [];
    $persons = [];
    $transcripts = [];
    $socialInteracts = [];
    $valueTimeSplits = [];
    $count = 0;
    while ($sql->fetch()) {
        //Fix amps
        if (stripos($ienclosureurl, '&amp;') !== FALSE) {
            $ienclosureurl = str_ireplace('&amp;', '&', $ienclosureurl);
        }

        if (!$fulltext) {
            $idescription = limit_words(stripAttributes(strip_tags($idescription, '<p><br><h1><h2><h3><h4><h5><b>')), 100, TRUE);
        }
        if (!isset($items[$iid])) {
            $items[$iid] = array(
                'id' => $iid,
                'title' => $ititle,
                'link' => $ilink,
                'description' => $idescription,
                'guid' => $iguid,
                'datePublished' => $itimestamp,
                'datePublishedPretty' => date("F d, Y g:ia", $itimestamp),
                'dateCrawled' => $itimeadded,
                'enclosureUrl' => $ienclosureurl,
                'enclosureType' => $ienclosuretype,
                'enclosureLength' => $ienclosurelength,
                'duration' => $iduration,
                'explicit' => $iexplicit,
                'episode' => $iepisode,
                'episodeType' => $iepisodetype,
                'season' => $iepisodeseason,
                'image' => $iimage,
                'feedItunesId' => $fitunesid,
                'feedTitle' => $ftitle,
                'feedUrl' => $furl,
                'feedImage' => $fimage,
                'feedId' => $ifid,
                'podcastGuid' => $fguid,
                'feedLanguage' => $flanguage,
                'feedDead' => $fdead,
                'feedDuplicateOf' => $fduplicateof,
                'chaptersUrl' => $ichapters,
                'transcriptUrl' => $itranscripturl,
                'fundingUrl' => $funurl,
                'fundingMessage' => $funmessage
            );
        }
        //Soundbites
        if ($isbstarttime !== NULL && !empty($isbduration)) {
            if (!in_array((string)($isbstarttime . $isbduration), $soundbites)) {
                $items[$iid]['soundbites'][] = array(
                    'startTime' => $isbstarttime,
                    'duration' => $isbduration,
                    'title' => $isbtitle
                );
                $soundbites[] = (string)($isbstarttime . $isbduration);
            }
        }
        //Persons
        if (!empty($pname)) {
            if (!in_array($pid, $persons)) {
                $items[$iid]['persons'][] = array(
                    'id' => $pid,
                    'name' => $pname,
                    'role' => $prole,
                    'group' => $pgroup,
                    'href' => $phref,
                    'img' => $pimg
                );
                $persons[] = $pid;
            }
        }
        //Social interact
        if (!empty($socialuri)) {
            if (!in_array($socialuri, $socialInteracts)) {
                $socialprotocoltext = 'activitypub';
                if ($socialprotocol == 2) {
                    $socialprotocoltext = 'twitter';
                }
                $items[$iid]['socialInteract'][] = array(
                    'uri' => $socialuri,
                    'protocol' => $socialprotocoltext,
                    'accountId' => $socialaccountid,
                    'accountUrl' => $socialaccounturl,
                    'priority' => $socialpriority
                );
                $socialInteracts[] = $socialuri;
            }
        }
        //Transcripts
        if (!empty($itranscripturl)) {
            if (!in_array($itranscripturl, $transcripts)) {
                $transcript_mime_type = "text/plain";
                switch ($itranscripttype) {
                    case 0:
                        $transcript_mime_type = "text/html";
                        break;
                    case 1:
                        $transcript_mime_type = "application/json";
                        break;
                    case 2:
                        $transcript_mime_type = "application/srt";
                        break;
                    case 3:
                        $transcript_mime_type = "text/vtt";
                        break;
                }
                $items[$iid]['transcripts'][] = array(
                    'url' => $itranscripturl,
                    'type' => $transcript_mime_type
                );
                $transcripts[] = $itranscripturl;
            }
        }
        //Value Block
        if (!empty($valueblock)) {
            $valueblock = json_decode($valueblock, TRUE);
            if ($valueblock !== NULL && is_array($valueblock) && isset($valueblock['model']) && isset($valueblock['destinations'])) {
                $items[$iid]['value'] = $valueblock;
            }
        }
        //Value time splits
        if (!empty($vtsstarttime) && !empty($vtsduration)) {
            if (!in_array($vtsid, $valueTimeSplits)) {
                $items[$iid]['timesplits'][] = array(
                    'startTime' => $vtsstarttime,
                    'duration' => $vtsduration,
                    'remoteStartTime' => $vtsremotestarttime,
                    'remotePercentage' => $vtsremotepercentage,
                    'feedGuid' => $vtsfeedguid,
                    'feedUrl' => $vtsfeedurl,
                    'itemGuid' => $vtsitemguid,
                    'medium' => $vtsmedium
                );
                $valueTimeSplits[] = $vtsid;
            }
        }

        $count++;
    }
    $sql->close();

    $episodes = array();
    $ecount = 0;
    foreach ($items as $item) {
        $episodes[] = $item;
        $ecount++;
        if ($ecount >= $max) break;
    }

    //Log and leave
    if (is_array($fid)) {
        foreach ($fid as $feedid) {
            //loggit(3, "Returning episodes for feed id: [$feedid].");
        }
    } else {
        //loggit(3, "Returning episodes for feed id: [$fid|$count|$ecount].");
    }
    return ($episodes);
}