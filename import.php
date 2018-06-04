<?php

/**
 * Initial 1.0 release--hopefully this helps someone!
 * 
 * Matthew Wegner
 * matthew@teamcolorblind.com
 */

// lazy command line usage
$host = $argv[1];
$user = $argv[2];
$pass = $argv[3];
$db = $argv[4];

if(empty($host) || empty($user) || empty($pass) || empty($db))
	die("Usage:  php import.php host username password database\n");

$timeStart = microtime(true);
pg_connect("host={$host} port=10733 dbname=$db user={$user} password={$pass}") or die("Couldn't connect to database\n");

// count things initially
$countChangesets = QueryValue("SELECT COUNT(*) AS value FROM changeset") - 1;
$countUsers = QueryValue("SELECT COUNT(DISTINCT creator) AS value FROM changeset") - 1;
$countAssets = QueryValue("SELECT COUNT(*) AS value FROM asset") - 1;
$countAssetVersions = QueryValue("SELECT COUNT(*) AS value FROM assetversion") - 1;
$lastCommit = 4000 + $countChangesets;
$firstCommitTime = QueryValue("SELECT extract(epoch from commit_time) AS value FROM changeset WHERE commit_time IS NOT NULL ORDER BY commit_time ASC LIMIT 1");

fwrite(STDERR, "Importing {$db} from {$host}:\n{$countUsers} users, {$countChangesets} changesets with {$countAssetVersions} versions of {$countAssets} assets\n\n");

// ongoing list of all assets in the project
$assets = array();

// blobs we've already sent to git
$blobs = array();

// grab all accounts out of the database
$query = "
	SELECT person.serial, person.username,
		split_part(pg_shdescription.description, ':'::text, 1) AS realname,
		split_part(pg_shdescription.description, ':'::text, 2) AS email
	FROM person
	LEFT JOIN pg_user ON person.username = pg_user.usename
	LEFT JOIN pg_shdescription ON pg_user.usesysid = pg_shdescription.objoid
";
$result = pg_query($query);
$persons = array();
while($row = pg_fetch_array($result, null,PGSQL_ASSOC))
{
	// index by username
	$persons[$row['username']] = $row;
}

// set up admin account more directly
$persons['admin']['realname'] = 'admin';
$persons['admin']['email'] = 'admin@teamcolorblind.com';

// get all changesets
$query = "
	SELECT p.username, c.serial, c.description, c.creator, extract(epoch from c.commit_time) as time
	FROM changeset c, person p
	WHERE c.creator = p.serial
	ORDER BY c.serial ASC
";
$result = pg_query($query);
$last = 0;
while($row = pg_fetch_array($result, null,PGSQL_ASSOC))
{
	$row['time'] = (int) $row['time'];

	// very old repositories are missing start time
	if($row['time'] == 0)
		$row['time'] = (int) ($firstCommitTime - 10);

	// get the changeset contents
	$query = "
		SELECT v.name, v.serial, v.created_in, v.revision, v.asset, v.parent, v.assettype, guid2hex(a.guid) AS guid, v.created_in
		FROM assetversion v, changesetcontents c, asset a
		WHERE v.serial = c.assetversion
			AND c.changeset = $row[serial]
			AND v.asset = a.serial
	";

	// build up a list of assets in this commit
	$result2 = pg_query($query);
	while($row2 = pg_fetch_array($result2, null, PGSQL_ASSOC))
	{
		$row2['is_delete'] = 0;

		// was it a delete?
		if(preg_match("/DEL_/", $row2["name"]))
		{
			$row2["name"] = substr($row2["name"], 0, -39);
			$row2["is_delete"] = 1;
		}

		$assets[$row2['asset']] = $row2;
	}

	// progress display for command line use
	$seconds = microtime(true) - $timeStart;
	$elapsed = SecondsToTime($seconds);
	fwrite(STDERR, "{$elapsed}\t{$row['serial']}/{$lastCommit}\n");

	// update all paths
	foreach($assets as &$asset)
	{
		$asset['path'] = BuildPath($asset);
	}

	$commit = "";
	// save out all modifications to files
	foreach($assets as &$asset)
	{
		if ($asset['is_delete'])
			continue;

		// if this is a directory, make a meta file (skip assets/trash folders)
		if($asset['assettype'] == 7000 && !in_array($asset['guid'], ['ffffffffffffffffffffffffffffffff', '00000000000000001000000000000000']))
		{
			$meta = "fileFormatVersion: 2\nguid: {$asset['guid']}\nfolderAsset: yes\n DefaultImporter:\n  userData: \n";

			$commit .= "M 0644 inline \"{$asset['path']}.meta\"\n";
			$commit .= "data " . strlen($meta) . "\n";
			$commit .= $meta;
		}

		// get the latest data for this blob
		$query = "SELECT assetversion, tag, lobj FROM stream, assetcontents
			WHERE stream = lobj
				AND tag = ANY(ARRAY['asset'::name, 'asset.meta'::name, 'metaData'::name])
				AND assetversion = {$asset['serial']}
		";
		$result3 = pg_query($query);
		while($row3 = pg_fetch_array($result3, null, PGSQL_ASSOC))
		{
			// check for meta tag ("metaData" tag is very old unity--3.x?)
			$path = $asset['path'];
			if($row3['tag'] == "asset.meta" || $row3['tag'] == "metaData")
				$path .= ".meta";

			$blob = $row3['lobj'];

			// write file as blob stream if we haven't already
			if(!array_key_exists($blob, $blobs))
			{
				$blobs[$blob] = 1;

				// save out file if we don't have it cached already (caching was mostly for dev/debugging)
				$filename = "temp_lobj_{$blob}";
				if(!file_exists("{$filename}"))
				{
					// read it to file
					pg_query("begin");
					pg_lo_export($blob, $filename);
					pg_query("commit");
				}

				print "blob\n";
				print "mark :{$blob}\n";
				// jam into data stream
				print "data " . filesize($filename) . "\n";
				readfile($filename);
				print "\n";

				unlink($filename);

			}

			$commit .= "M 644 :{$blob} \"{$path}\"\n";
		}

		// could append these later, but just pretend they were there from the start
		$commit .= InjectFile(".gitignore", "../template_gitignore");
		$commit .= InjectFile(".gitattributes", "../template_gitignore");
	}

	BeginCommit($row, $last);

	// just delete everything in index and start fresh
	print "deleteall\n";

	// we cached the commit to here because blobs can't appear inside a commit for some dumb reason
	print $commit;
	print "\n";

	$last = $row['serial'];
}

/**
 * Shove a non-Asset Server file into the project from the beginning--this should be changed over to blobs
 * @param $pathProject
 * @param $pathLocal
 */
function InjectFile($pathProject, $pathLocal)
{
	if(!file_exists($pathLocal))
		return "";

	$inject = "";

	$inject .= "M 0644 inline \"$pathProject\"\n";
	$inject .= "data " . filesize($pathLocal) . "\n";
	$inject .= file_get_contents($pathLocal);
	$inject .= "\n";

	return $inject;
}

/**
 * Header for a new commit
 * @param $row
 */
function BeginCommit($row, $from = 0)
{
	global $persons;

	print "commit refs/heads/master\n";
	print "mark :{$row['serial']}\n";

	$person = $persons[$row['username']];

	print "author {$person['realname']} <{$person['email']}> {$row['time']} -0700\n";
	print "committer {$person['realname']} <{$person['email']}> {$row['time']} -0700\n";

	ExportData($row['description']);

	if($from)
		print "from :{$from}\n";
}

/**
 * Helper function (although only ended up being used once)
 * @param $data
 */
function ExportData($data)
{
	$length = strlen($data);
	print "data {$length}\n";
	print $data . "\n";
}

/**
 * Recursively update path for any given asset
 * @param $asset
 * @param string $path
 * @return string
 */
function BuildPath($asset, $path = "")
{
	global $assets;

	if(empty($path))
		$path = $asset['name'];
	else
		$path = "{$asset['name']}/{$path}";

	$parent = @$assets[$asset['parent']];
	if($parent)
		$path = BuildPath($parent, $path);
	else if(EndsWith($asset['name'], ".asset") || EndsWith($asset['name'], ".prefs"))
		$path = "ProjectSettings/{$path}";

	return $path;
}

/**
 * Return a single value
 * @param $query
 */
function QueryValue($query)
{
	$result = pg_query($query);
	$data = pg_fetch_object($result);
	return $data->value;
}

/**
 * PHP should really build this in
 * @param $haystack
 * @param $needle
 * @return bool
 */
function EndsWith($haystack, $needle) {
	return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
}

/**
 * Lazy paste, probably a built-in way to do this by now
 * @param $s
 * @return string
 */
function SecondsToTime($s)
{
	$h = floor($s / 3600);
	$s -= $h * 3600;
	$m = floor($s / 60);
	$s -= $m * 60;
	return $h.':'.sprintf('%02d', $m).':'.sprintf('%02d', $s);
}