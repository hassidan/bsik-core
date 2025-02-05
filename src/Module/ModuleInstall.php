<?php

namespace Siktec\Bsik\Module;

if (!defined('DS')) 
    define('DS', DIRECTORY_SEPARATOR);
if (!defined('ROOT_PATH')) 
    define("ROOT_PATH", dirname(__FILE__).DS."..".DS.".." );

/******************************  Requires       *****************************/
use \Exception;
use \Throwable;
use \ZipArchive;
use \SplFileInfo;
use \Siktec\Bsik\Std;
use \Siktec\Bsik\CoreSettings;
use \Siktec\Bsik\Base;
use \Siktec\Bsik\FsTools\BsikZip;
use \Siktec\Bsik\FsTools\BsikFileSystem;
use \Siktec\Bsik\Module\Schema\ModuleSchema;

/*********************  Load Conf and DataBase  *****************************/
//TODO: this should be as a method in the moduleinstaller class - standalone()
if (!isset(Base::$db)) {
    Base::configure($conf);
    Base::connect_db();
}

/********************** Installer *******************************************/
class ModuleInstaller {

    //Path and extraction related:
    private const           TEMP_FOLDER_PREFIX  = "bsik_m_temp_";
    private string          $rand_id            = "";
    private string          $temp_folder_path   = "";  
    private ?SplFileInfo    $install_in        = null;
    public  ?SplFileInfo    $temp_extracted    = null;
    private ?SplFileInfo    $source            = null;
    public  ZipArchive      $zip;

    //Validation related
    public const REQUIRED_FILES_INSTALL = [
        "module.jsonc" => "jsonc"
        //"module.php"   => "exists" //TODO: this should be checked only when single included or 
    ];
    public const REQUIRED_FILES_MODULE = [
        "module.jsonc" => "jsonc",
        "module.php" => "exists"
    ];

    /** 
     * Construct ModuleInstaller
     * 
     * @param string $source => path to archive on server.
     * @param string $in   => path to default extract destination folder.
     * @throws Exception => E_PLAT_ERROR on zip cant be opened.
     * @return ModuleInstaller
     */
    public function __construct(
        string|SplFileInfo $source, 
        string|SplFileInfo|null $in = null
    ) {
        $in = $in ?? CoreSettings::$path["manage-modules"];
        $this->source           = is_string($source) ? new SplFileInfo($source) : $source;
        $this->install_in       = is_string($in) ? new SplFileInfo($in) : $in;
        $this->rand_id          = std::$date::time_datetime("YmdHis");
        $this->temp_folder_path = Std::$fs::path($this->install_in->getRealPath(), self::TEMP_FOLDER_PREFIX.$this->rand_id);
        $this->zip              = BsikZip::open_zip($this->source->getRealPath() ?: "");
    }

    /** 
     * validate_required_files_in_zip - validate an zip in module archive.
     * 
     * @return array => array of errors message if any
     */
    public function validate_required_files_in_zip() : array {
        $errors = [];
        if ($this->zip->filename && $this->zip->status === ZipArchive::ER_OK) {
            //List the files in zip
            $list = BsikZip::list_files($this->zip);
            //Validate required - simple validation just of presence and format:
            foreach (self::REQUIRED_FILES_INSTALL as $file => $validate) {
                if (array_key_exists($file, $list)) {
                    $content = $this->zip->getFromIndex($list[$file]["index"]);
                    if (!$this->validate_file("", $validate, $content)) {
                        $errors[] = "File is invalid [{$file}] expected [{$validate}]";
                    }
                } else {
                    $errors[] = "Required file missing [{$file}]";
                }
            }
        } else {
            $errors[] = "zip archive not loaded";
        }
        return $errors;
    }

    /** 
     * validate_required_files_in_extracted - validate an extracted zip in folder.
     * @param  mixed $folder => null for loaded, string for given folder path 
     * @return array         => array of errors message if any
     */
    public function validate_required_files_in_extracted($folder = null, array $required = []) : array {
        $folder = $folder ?? $this->temp_extracted->getRealPath();
        $errors = [];
        foreach ($required as $file => $validate) {
            if (!Std::$fs::path_exists($folder, $file)) {
                $errors[] = "Required file missing [{$file}]";
                continue;
            }
            if (!$this->validate_file(Std::$fs::path($folder, $file), $validate)) {
                $errors[] = "File is invalid [{$file}] expected [{$validate}]";
            }
        }
        return $errors;
    }

    /** 
     * validate_file - validate an a file that is required by its type.
     * @param  string $path             => the file path will be used only if $content is null. 
     * @param  string $validate_type    => the file content validation required (json, jsonc etc.)  
     * @param  mixed $content           => null for load from path or the file content
     * @return bool                     => true on valid
     */
    public function validate_file(string $path, string $validate_type, ?string $content = null) : bool {
        if (is_null($content)) {
            $content = file_get_contents($path) ?? "";
        }
        switch ($validate_type) {
            case "jsonc": {
                $json = Std::$str::strip_comments($content);
                if (!Std::$str::is_json($json)) {
                    return false;
                }
            } break; 
            case "json": {
                $json = $content;
                if (!Std::$str::is_json($json)) {
                    return false;
                }
            } break;
        }
        return true;
    }

    /** 
     * close_zip - close and release a loaded zip file
     * safe to use even when not opened
     * @return void
     */
    public function close_zip() {
        try {
            $this->zip->close();
        } catch (Throwable) { ; }
    }

    /** 
     * temp_deploy - extract the loaded zip archive to a folder
     * 
     * @param   bool $close_after - whether to close the zip file or not.
     * @return  bool true when success
     */
    public function temp_deploy(bool $close_after = false, int $flags = 0) : bool {
        if ($result = BsikZip::extract_zip($this->zip, $this->temp_folder_path, $flags)) {
            $this->temp_extracted = new SplFileInfo($this->temp_folder_path);
        } 
        if ($close_after) {
            $this->close_zip();
        }
        return $result;
    }
    
    /**
     * clean - will clear an remove the temp folder if its there
     * 
     * @return bool - true if temp was cleared false if no temp folder.
     */
    public function clean() : bool {
        if ($this->temp_extracted && $path = $this->temp_extracted->getRealPath()) {
            return BsikFileSystem::clear_folder($path, true);
        }
        return false;
    }
    
    public function install($by = null, ?string $from = null) : array {

        $from = $from ?? $this->temp_extracted->getRealPath();
        $installed = [];
        
        //Load module.jsonc:
        $module_json = Std::$fs::get_json_file(
            Std::$fs::path($from, "module.jsonc")
        );

        //Check we got a valid schema.jsonc
        if (empty($module_json)) {
            return [false, "module.jsonc is missing or corrupted", []];
        }
        if (empty($module_json["schema"] ?? "")) {
            return [false, "module.jsonc is missing schema version definition", []];
        }
        
        //Load schema:
        $schema = new ModuleSchema("install", $module_json["schema"]);
        if (!$schema->is_loaded()) {
            return [false, $schema->get_message(), []];
        }
        
        //Create the given definition and validate:
        $module_def = $schema->create_definition($module_json);
        if (!$module_def->valid) {
            return [false, $module_def->errors, []];
        }

        //Install module depends on the type:
        switch ($module_def->struct["this"]["type"]) {
            case "single" : {
                //Install the module:
                $module = $module_def->struct[$schema->naming('modules_container')][0];
                [$status, $module_name, $errors] = $this->install_module($module, $by);
                if (!$status) {
                    return [false, $errors, $installed];
                } else {
                    $installed[] = $module_name;
                }
            } break;
            case "bundle" : {
                return [false, "bundle installation is not supported yet", []];
            } break;
        }
        
        //Return
        return [true, "installed", $installed];
    }

    private function install_module(array $single_definition, $by = null) : array {
        $module_name = Std::$str::filter_string($single_definition["name"] ?? "unknown", ["A-Z","a-z","0-9", "_"]);
        $module_path = Std::$fs::path($this->install_in->getRealPath(), $module_name);

        // validate its new module:
        if (
                Base::$db->where("name", $module_name)->has("bsik_modules")
            ||  Std::$fs::path_exists($module_path)
        ) {
            return [true, $module_name, "allready installed"]; // We return true because its allready in
        }
        
        //Load schema:
        $schema = new Schema\ModuleSchema("module", $single_definition["schema"] ?? "");
        if (!$schema->is_loaded()) {
            return [false, $module_name, $schema->get_message()];
        }

        //Create the given definition and validate:
        $module = $schema->create_definition($single_definition);
        if (!$module->valid) {
            return [false, $module_name, $module->errors];
        }

        //Now install the module:
        switch ($module->struct["type"]) {
            case "included": {
                //Vallidate required:
                $errors = $this->validate_required_files_in_extracted(null, self::REQUIRED_FILES_MODULE);
                if (!empty($errors)) {
                    return [false, $module_name, $errors];
                }
                //Move temp folder:
                if (!BsikFileSystem::xcopy($this->temp_extracted->getRealPath(), $module_path)) {
                    return [false,$module_name,"failed to copy module to destination"];
                }
                //Register on DataBase:
                $info = $module->struct;
                unset($info["menu"]);
                unset($info["\$schema_naming"]);
                unset($info["\$schema_required"]);
                if (!Base::$db->insert("bsik_modules", [
                    "name"          => $module_name,
                    "status"        => 1,
                    "updates"       => 0,
                    "path"          => $module_name.DIRECTORY_SEPARATOR,
                    "settings"      => "{}",
                    "menu"          => json_encode($module->struct[$schema->naming("menu_container")]),
                    "version"       => $module->struct["ver"],
                    "created"       => Base::$db->now(),
                    "updated"       => Base::$db->now(),
                    "info"          => json_encode($info),
                    "installed_by"  => $by
                ])) {
                    //Remove folder:
                    BsikFileSystem::clear_folder($module_path, true);
                    return [false, $module_name, "failed to register module to database"];
                };
            } break;
            case "remote": {
                return [false, $module_name, "remote modules are not supported yet"];
            } break;
        }
        return [true, $module_name, "module installed"];
    }
}