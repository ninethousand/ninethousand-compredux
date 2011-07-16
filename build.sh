#!/usr/bin/php
<?php
include('Autoload.php');
$release = '-dev';
if ((count($argv) > 1) && $argv[1] == 'release') {
    $release = '';
}
$fh = fopen('VERSION', "r");
$vstr = fread($fh, filesize('VERSION'));
fclose($fh);
$version = substr($vstr, strpos($vstr, '=')+1);
$path = realpath(dirname(__FILE__));
$dir = $path.'/lib';
$file = $path.'/library.php';
$archive = $path.'/download'.$release.'/compredux.'.$version.'.'.date("mdyHis").'.zip';
$wp_plugin_archive = $path.'/download'.$release.'/wp_compredux.'.$version.'.'.date("mdyHis").'.zip';
$readmefile = $path.'/README.md';
$docfile = $path.'/docs'.$release.'/blank.html';
$mdh = new Compredux\MarkdownParser();


$files_to_zip = array(
  'README.md',
  'Autoload.php',
  '.htaccess',
  'index.example.php',
  'library.php',
  'robots.txt'
);

$files_to_wp_zip = array(
  'README.md',
  'Autoload.php',
  '.htaccess',
  'wp_compredux.php',
  'library.php',
  'robots.txt'
);


if (file_exists($file)) {
    echo "deleting $file\n";
    unlink($file);
}

foreach (array('download', 'build') as $newdir) {
    if (!is_dir($path.'/'.$newdir)) {
        mkdir($path.'/'.$newdir);
    }
}

$fh = fopen($file, "a");
fwrite($fh, '<?php'."\n");
fclose($fh);

echo "scanning $dir for files...\n";
cat_r($dir, $file);

echo "ammending documentation...\n";
if ((file_exists($docfile)) && (file_exists($readmefile))) {
    $fh = fopen($readmefile, 'r');
    $readme = fread($fh, filesize($readmefile));
    fclose($fh);
    $fh = fopen($docfile, 'w');
    fwrite($fh, $mdh->transform($readme));
    fclose($fh);

    $docsfiles = listdir_r('./docs'.$release);
    if (count($docsfiles)>0) {
        $files_to_zip = array_merge($docsfiles, $files_to_zip);
    }    
}

echo "creating standard archive...";
if (create_zip($files_to_zip, $archive)) { echo "success!\n"; }
shell_exec("rm compredux-current.zip; cp $archive ./compredux-current.zip");

echo "creating wp plugin...";
if (create_zip($files_to_wp_zip, $wp_plugin_archive)) { echo "success!\n"; }
shell_exec("rm wp_compredux.zip; cp $wp_plugin_archive ./wp_compredux.zip");

function stripPHPTag($contents) {
    if (strpos($contents, '<?php') !== false) {
            $contents = str_replace('<?php', '', $contents);
    }
    return $contents;
}

function listdir_r($dir) 
{
    $files = array();
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir."/".$object) == "dir") {
                    $files = array_merge(listdir_r($dir."/".$object), $files);
                } else {
                    $files[] = $dir."/".$object;
                }
            }
        }
        reset($objects);
    }
    return $files;
}

function cat_r($dir, $file) 
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir."/".$object) == "dir") {
                    cat_r($dir."/".$object, $file);
                } else {
                    $fh = fopen($file, "a");
                    fwrite($fh, stripPHPTag(file_get_contents($dir."/".$object)));
                    fclose($fh);
                }
            }
        }
        reset($objects);
    }
}

/* creates a compressed zip file */
function create_zip($files = array(),$destination = '',$overwrite = false) {
	//if the zip file already exists and overwrite is false, return false
	if(file_exists($destination) && !$overwrite) { return false; }
	//vars
	$valid_files = array();
	//if files were passed in...
	if(is_array($files)) {
		//cycle through each file
		foreach($files as $file) {
			//make sure the file exists
			if(file_exists($file)) {
				$valid_files[] = $file;
			}
		}
	}
	//if we have good files...
	if(count($valid_files)) {
		//create the archive
		$zip = new ZipArchive();
		if($zip->open($destination,$overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
			return false;
		}
		//add the files
		foreach($valid_files as $file) {
			$zip->addFile($file,$file);
		}
		//debug
		//echo 'The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->status;
		
		//close the zip -- done!
		$zip->close();
		
		//check to make sure the file exists
		return file_exists($destination);
	}
	else
	{
		return false;
	}
}

