<?php

if (ini_get('phar.readonly')) {
    $cmd = escapeshellarg(PHP_BINARY);
    $cmd .= ' -d phar.readonly=0';
    foreach ($argv as $i) {
        $cmd .= ' '.escapeshellarg($i);
    }
    passthru($cmd,$rv);
    exit($rv);
}

define('CMD',array_shift($argv));
error_reporting(E_ALL);

function usage() {
    die("Usage:\n\t".CMD." [-o outdir] <src_directory>\n");
}
$path = ".";

if (isset($argv[0]) && $argv[0] == '-o') {
    array_shift($argv);
    $path = array_shift($argv);
    if (!isset($path)) die("Must specify output path\n");
    if (!is_dir($path)) die("$path: output directory not found\n");
}
$path = preg_replace('/\/*$/',"",$path).'/';

$srcdir = array_shift($argv);
if (!isset($srcdir)) usage();
$srcdir = preg_replace('/\/*$/',"",$srcdir).'/';

if (!is_dir($srcdir)) {
    die("$srcdir: directory doesn't exist!\n");
}
if (!is_dir($srcdir.'src')) {
    die("$srcdir: Does not contain a src directory\n");
}

$git="https://github.com/PocketMine/PreProcessor.git";
if (!is_dir(dirname(__FILE__)."/PreProcessor")) {
    exec_cmd(["git","clone",$git,dirname(__FILE__)."/PreProcessor"]);
}


//////////////////////////////////////////////////////////////////////

/**
 * File Utilities
 */
abstract class FileUtils
{

    /**
     * Recursive copy function
     * @param str src source path
     * @param str dst source path
     * @return bool
     */
    public static function cp_r($src,$dst)
    {
        if (is_link($src)) {
            $l = readlink($src);
            if (!symlink($l,$dst)) {
                return false;
            }
        } elseif (is_dir($src)) {
            if (!mkdir($dst)) {
                return false;
            }
            $objects = scandir($src);
            if ($objects === false) {
                return false;
            }
            foreach ($objects as $file) {
                if ($file == "." || $file == "..") {
                    continue;
                }
                if (!self::cp_r($src.DIRECTORY_SEPARATOR.$file,$dst.DIRECTORY_SEPARATOR.$file)) {
                    return false;
                }
            }
        } else {
            if (!copy($src,$dst)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recursive delete function
     * @param str path
     * @return bool
     */
    public static function rm_r($path)
    {
        if (!is_link($path) && is_dir($path)) {
            $objects = scandir($path);
            if ($objects === false) {
                return false;
            }
            foreach ($objects as $file) {
                if ($file == "." || $file == "..") {
                    continue;
                }
                if (!self::rm_r($path.DIRECTORY_SEPARATOR.$file)) {
                    return false;
                }
            }
            return rmdir($path);
        }
        return unlink($path);
    }

    public static function tempdir($dir, $prefix='', $mode=0700)
    {
        if (substr($dir, -1) != '/') {
            $dir .= '/';
        }
        do {
            $path = $dir.$prefix.mt_rand(0, 9999999);
        } while (!mkdir($path, $mode));
        return $path;
    }

}

function read_PM_src($srcdir)
{
    $pm = file($srcdir."src/pocketmine/PocketMine.php",
                   FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if ($pm === null) {
        die("$srcdir: Unable to read PocketMine file\n");
    }

    $attr = [];
    foreach ($pm as $ln) {
        if (preg_match('/^\s*const\s*([_A-Z]+)\s*=\s*["\']([^"]+)["\']\s*;\s*$/',
                            $ln,$mv)) {
            $attr[$mv[1]] = $mv[2];
        }
    }
    $info = file($srcdir."src/pocketmine/network/protocol/Info.php",
                     FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if ($info === null) {
        die("$srcdir: Unable to read network/protocol/Info file\n");
    }
    foreach ($info as $ln) { //\s*;\s*$
        if (preg_match('/^\s*const\s*([_A-Z]+)\s*=\s*([0-9]+)\s*;\s*$/',
                            $ln,$mv)) {
            $attr[$mv[1]] = $mv[2];
        }
    }
    $attr["NAME"] = "PocketMine-MP";
    foreach (["VERSION","API_VERSION","CODENAME",
                 "MINECRAFT_VERSION","MINECRAFT_VERSION_NETWORK",
                 "CURRENT_PROTOCOL"] as $v) {
        if (!isset($attr[$v])) {
            die("Missing attribute $v\n");
        }
    }
    // VERSION
    // API_VERSION
    // CODENAME
    // MINECRAFT_VERSION
    // MINECRAFT_VERSION_NETWORK
    // CURRENT_PROTOCOL
    return $attr;
}

function exec_cmd($args)
{
    $q = "";
    $cmdline = "";
    foreach ($args as $i) {
        $cmdline .= $q.escapeshellarg($i);
        $q = " ";
    }
    passthru($cmdline,$rv);
    if ($rv) {
        die("Error executing command: $cmdline\n");
    }
}


//////////////////////////////////////////////////////////////////////
$attr = read_PM_src($srcdir);
print_r($attr);
$tmp = FileUtils::tempdir($path,"mkp");
echo ("Creating working directory\n");
if (!FileUtils::cp_r($srcdir."src",$tmp."/src")) {
    die("$tmp: copy error\n");
}
echo ("PreProcessing source\n");
exec_cmd([PHP_BINARY,
          dirname(realpath(__FILE__))."/PreProcessor/PreProcessor.php",
          "--path=".$tmp."/src",
          "--multisize"]);
echo ("Optimizing code\n");
exec_cmd([PHP_BINARY,
          dirname(realpath(__FILE__))."/PreProcessor/CodeOptimizer.php",
          "--path=".$tmp."/src"]);

echo ("Generating PHAR\n");
$pharname = $attr["NAME"]."_".$attr["VERSION"].".phar";
$phar = new Phar($path.$pharname);
$phar->setMetadata(["name" => $attr["NAME"],
                    "version" => $attr["VERSION"],
                    "api" => $attr["API_VERSION"],
                    "minecraft" => $attr["MINECRAFT_VERSION"],
                    "protocol" => $attr["CURRENT_PROTOCOL"],
                    "creationDate" => time(),]);

$phar->setStub('<?php define("pocketmine\\\\PATH","phar://". __FILE__ ."/");
                require_once("phar://". __FILE__ ."/src/pocketmine/PocketMine.php");
                __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->startBuffering();

foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp."/src")) as $file) {
    $path = ltrim(str_replace(["\\", $tmp], ["/", ""], $file), "/");
    if ($path{0} === "." || strpos($path, "/.") !== false || substr($path, 0, 4) !== "src/") {
        continue;
    }
    $phar->addFile($file, $path);
}
foreach ($phar as $file => $finfo) {
    /** @var \PharFileInfo $finfo */
    if ($finfo->getSize() > (1024 * 512)) {
        $finfo->compress(\Phar::GZ);
    }
}
$phar->stopBuffering();
echo ("Created: $path$pharname\n");
echo ("Cleaning up $tmp\n");
FileUtils::rm_r($tmp);
