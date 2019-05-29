<?php

function pather()
{
    $path = __FILE__;
    $path = preg_replace('/\/local\/service_scripts\/.*/','',$path);
    return $path;
}

if ( ! function_exists( 'array_key_last' ) ) {
    /**
     * Polyfill for array_key_last() function added in PHP 7.3.
     *
     * Get the last key of the given array without affecting
     * the internal array pointer.
     *
     * @param array $array An array
     *
     * @return mixed The last key of array if the array is not empty; NULL otherwise.
     */
    function array_key_last( $array ) {
        $key = NULL;

        if ( is_array( $array ) ) {

            end( $array );
            $key = key( $array );
        }

        return $key;
    }
}


//require_once (__DIR__."/Task.php");



class DataReader
{
    protected $items = [];

    const UPLOAD_DIR = '/WEB';
    const IBLOCK_ID = 8;

    public function __construct()
    {
        $this->readFiles();
        $this->setIds();
//        $this->sortAndHash();
    }
    protected function readFiles()
    {
        $dirPath = $_SERVER['DOCUMENT_ROOT']."/upload".self::UPLOAD_DIR;
        $dir = new \RecursiveDirectoryIterator($dirPath,\RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($dir,\RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file)
        {
            if($file->isFile())
            {

                $array = explode("_",$file->getFilename());
                $code = $array[0];
                $sort = str_replace($array[0],'',$file->getFilename());
                $sort = preg_replace('(\D)','',$sort);
                $info = getimagesize($file->getRealPath());
                $this->items[$code]["FILES"][(int)$sort] = [
                    'PATH' =>$file->getRealPath(),
                    'EXTENTION' => $file->getExtension(),
                    'NAME' => $file->getBasename(),
                    'WIDTH' => $info[0],
                    'HEIGHT' => $info[1],
                    'TYPE' => $info['mime'],
                    'SIZE' => filesize($file->getRealPath()),
                    'SUBDIR' => dirname($file->getRealPath())
                ] ;
            }
        }
    }
    protected function sortAndHash()
    {
        foreach($this->items as &$item)
        {
            ksort($item['FILES']);
        }
        ksort($this->items);
        $this->getDirHash($this->items);
    }
    protected function getDirHash($array)
    {
          $size = count($array);
          $data = new ServiceScripts\StackableArray($array);
          $ts = [];
//          $pool = new Pool(4,"\ServiceScripts\DataCollector",[$data]);
          for ($i=0;$i<$size;$i++)
          {
              $ts[]=new ServiceScripts\T($data);
          }

//          while ($pool->collect());
//          $pool->shutdown();
          echo count($ts)." - threads".PHP_EOL;
          foreach ($ts as $t)
          {
              $t->join();
          }
          $sData = unserialize($data->b);
          foreach ($this->items as $key => $item)
          {
              $this->items[$key]['HASH'] = $sData[$item['ID'][0]];
          }
          echo 'test';
          die();
    }
    protected function setIds()
    {
        global $DB;
        $sql = "SELECT ID,CODE FROM `b_iblock_element`
        WHERE IBLOCK_ID = ".self::IBLOCK_ID;
        $data = $DB->Query($sql,true);
        if(!$data)
            throw new \Exception($sql);
        while($row = $data->Fetch())
        {
            if(isset($this->items[$row['CODE']]))
                $this->items[$row['CODE']]['ID'][] = $row['ID'];
        }
        foreach ($this->items as $code => $item)
        {
            if(!isset($item['ID']))
                unset($this->items[$code]);
        }
    }
    public function getItems()
    {
        return $this->items;
    }
    public function getItemsIds()
    {
        $array = [];
        foreach ($this->items as $code => $item)
        {
            foreach ($item['ID'] as $id)
            $array[$code][] = $id;
        }
        return $array;
    }
}
class UpdateImages
{
    protected $items = [];
    protected $ids = [];
    protected $bitrixPhoto = [];

    const PROP = 26;

    public function __construct()
    {
        $this->prepareData();
//        $this->compareHashes();
//        $this->saveHash();
//        print_r($this->items);
//        echo PHP_EOL;
    }
    public static function getInstanse()
    {
        return new self();
    }
    protected function prepareData()
    {
       $reader = new DataReader();
       $this->items = $reader->getItems();
       foreach ($this->items as &$item)
       {
           $item['CHECKED'] = false;
       }
       $this->ids = $reader->getItemsIds();
    }
    protected function compareHashes()
    {
        global $DB;
        $sql = "CREATE TABLE IF NOT EXISTS `ritter_hash_img`
        ( `ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `VENDOR_CODE` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
          `DIR_HASH` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
          PRIMARY KEY (`ID`),
          UNIQUE KEY `VENDOR_CODE` (`VENDOR_CODE`)
        ) ENGINE=InnoDB AUTO_INCREMENT=56957 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
        $DB->Query($sql);
        $sql = "SELECT * FROM ritter_hash_img";
        $res = $DB->Query($sql);
        while($row = $res->Fetch())
        {
            if(isset($this->items[$row['VENDOR_CODE']]))
            {
                if($this->items[$row['VENDOR_CODE']]['HASH'] == $row['DIR_HASH'])
                {
                    unset($this->items[$row['VENDOR_CODE']]);
                    unset($this->ids[$row['VENDOR_CODE']]);
                }
                else
                {
                    $this->items[$row['VENDOR_CODE']]['CHECKED'] = true;
                }
            }
        }
        foreach ($this->items as $code => $item)
        {
            if(!$item['CHECKED'])
                $this->bitrixPhoto[$code] = $item;
        }
    }

    protected function deleteBitrixPhoto()
    {
        global $DB;
        if(count($this->bitrixPhoto) == 0)
            return false;
        $ids = [];
        foreach($this->bitrixPhoto as $code => $item)
        {
            foreach ($item['ID'] as $id)
            {
                $ids[] = $id;
            }
        }
        $sql = "SELECT iblock.ID, iblock.DETAIL_PICTURE, props.ID as props_for_delete,
                props.VALUE as props_img_id,
                SUBDIR,FILE_NAME,
                CONCAT(\"/upload/\",file.SUBDIR,\"/\",file.FILE_NAME) as prop_path
                FROM `b_iblock_element` as iblock
                LEFT JOIN `b_iblock_element_property`as props ON iblock.ID = props.IBLOCK_ELEMENT_ID
                LEFT JOIN `b_file` as file ON props.VALUE = file.ID
                WHERE  iblock.ID IN (".implode(',',$ids).")AND iblock.IBLOCK_ID = ".DataReader::IBLOCK_ID
            ." AND props.IBLOCK_PROPERTY_ID = ".self::PROP;
        $data = $DB->Query($sql);
        $pathForDelete = [];
        $elementIds = [];
        $picturePropIds = [];
        $detailImg = [];
        $propsForDelete = [];
        while ($row = $data->Fetch())
        {
            if(!empty($row['prop_path']))
            {
                $pathForDelete[] = [
                    'img' => $_SERVER['DOCUMENT_ROOT'].$row['prop_path'],
                    'name' => $row['FILE_NAME'],
                    'subdir' => $_SERVER['DOCUMENT_ROOT']."/upload/resize_cache/".$row['SUBDIR']
                    ];
            }
            if(!empty($row['props_img_id']))
                $picturePropIds[] = $row['props_img_id'];
            if(!empty($row['ID']))
                $elementIds[$row['ID']] = $row['ID'];
            if(!empty($row['DETAIL_PICTURE']))
                $detailImg[] = $row['DETAIL_PICTURE'];
            if(!empty($row['props_for_delete']))
                $propsForDelete[] = $row['props_for_delete'];
        }
        var_dump($pathForDelete);
        if(count($detailImg)!=0)
        {
            $sql = "SELECT CONCAT(\"/upload/\",SUBDIR,\"/\",FILE_NAME) as prop_path,
                SUBDIR,FILE_NAME FROM `b_file`
                WHERE ID IN(".implode(',',$detailImg).")";
            $data = $DB->Query($sql);
            while ($row = $data->Fetch())
            {
                $pathForDelete[] = [
                    'img' => $_SERVER['DOCUMENT_ROOT'].$row['prop_path'],
                    'name' => $row['FILE_NAME'],
                    'subdir' => $_SERVER['DOCUMENT_ROOT']."/upload/resize_cache/".$row['SUBDIR']
                ];
            }
        }
        file_put_contents(__DIR__.DIRECTORY_SEPARATOR."data.json",json_encode($pathForDelete));
        die();
        foreach ($pathForDelete as $path)
        {
            if(is_dir($path['subdir']))
            {
                $dir = new \RecursiveDirectoryIterator($path['subdir'],\RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new \RecursiveIteratorIterator($dir);
                foreach ($files as $file)
                {
                    if($file->getBasename() == $path['name'])
                    {
                        unlink($files->getRealPath());
                    }
                }
            }
            unlink($path['img']);
        }
        if(!empty($elementIds))
        {
            $sql = "UPDATE `b_iblock_element` SET DETAIL_PICTURE = NULL WHERE ID IN(".implode(',',$elementIds).")";
            $DB->Query($sql);
        }
        if(!empty($propsForDelete))
        {
            $sql = "DELETE FROM `b_iblock_element_property` WHERE ID IN(".implode(',',$propsForDelete). ")";
            $DB->Query($sql);
        }
        if(!empty($detailImg) && !empty($picturePropIds))
        {
            $sql = "DELETE FROM `b_file` WHERE ID IN(".implode(',',$detailImg).",".implode(',',$picturePropIds).")";
            $DB->Query($sql);
        }

    }

    protected function saveHash()
    {
        global $DB;
        $data = [];
        $sql = [];
        $sqlStart = "INSERT INTO ritter_hash_img (VENDOR_CODE, DIR_HASH) 
        VALUES ";
        $sqlEnd = "ON DUPLICATE KEY UPDATE DIR_HASH = VALUES(DIR_HASH);";
        foreach($this->items as $code => $item)
        {
            $data[]  = "('".$code."','".$item['HASH']."'".")";
        }
        $count = 0;
        $last_key = array_key_last($data);

        foreach ($data as $art => $code)
        {
            if(!isset($string))
                $string = $sqlStart;
            $string .= $code;
            $count++;
            if($count >= 1000 || $art == $last_key)
            {
                $sql[] = $string.PHP_EOL.$sqlEnd;
                $string = $sqlStart;
                $count = 0;
            }
            else
                $string .= ",".PHP_EOL;
        }
        foreach ($sql as $query)
        {
            $DB->Query($query);
        }
    }

    public function cleareResizeCache($code)
    {
//        echo "--start--".PHP_EOL.PHP_EOL;

//        echo $code.PHP_EOL;
        if(strlen($code) != 6)
            return false;
        $arr = str_split($code,2);
        $dir = $_SERVER["DOCUMENT_ROOT"]."/upload/resize_cache".DataReader::UPLOAD_DIR;
        foreach ($arr as $path)
        {
            $dir .= "/".$path;
        }
        $dir .= "/";
//        echo $dir.PHP_EOL;
//        var_dump(is_dir($dir));
        if(!is_dir($dir))
            return true;
        else
        {
            $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it,
                \RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file) {
                if ($file->isDir()){
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            rmdir($dir);
        }
    }

    public function updateItems()
    {
        global $DB;
        $updateDetail = [];
        $updateProp = [];
        $elementIds = [];
        $count = 0;
        foreach ($this->items as $code => $item)
        {
            foreach ($item['FILES'] as $index => $file)
            {
                    foreach ($item['ID'] as $key => $id)
                    {
                        if($count>1000 || (array_key_last($this->items) == $code &&
                    array_key_last($item['FILES']) == $index && array_key_last($item['ID']) == $key))
                        {
                            if($index == 1)
                                $updateDetail[] = "WHEN ID = ".$id." THEN ".$file['ID'];
                            else
                                $updateProp[] = "(".self::PROP.",".$id.",".$file['ID'].",'text',".$file['ID'].")";
                            $elementIds[] = $id;
                            $sql = "UPDATE `b_iblock_element` SET DETAIL_PICTURE = CASE".PHP_EOL.
                                implode(PHP_EOL,$updateDetail).PHP_EOL."END WHERE ID IN(".implode(",",$elementIds).")";
                            if(!empty($updateDetail) && !empty($elementIds))
                                $DB->Query($sql);
                            $sql = "DELETE FROM b_iblock_element_property 
          WHERE IBLOCK_PROPERTY_ID = ".self::PROP." AND IBLOCK_ELEMENT_ID IN(" .
                                implode(',',$elementIds).")";
                            if(!empty($elementIds))
                                $DB->Query($sql);
                            $sql = "INSERT INTO `b_iblock_element_property` "
                                ."(IBLOCK_PROPERTY_ID, IBLOCK_ELEMENT_ID, VALUE,VALUE_TYPE,VALUE_NUM) VALUES ".
                            implode(','.PHP_EOL,$updateProp);
                            if(!empty($updateProp))
                                $DB->Query($sql);
                            $updateDetail = [];
                            $updateProp = [];
                            $elementIds = [];
                            $count = 0;
                        }
                        else
                        {
                            if($index == 1)
                                $updateDetail[] = "WHEN ID = ".$id." THEN ".$file['ID'];
                            else
                                $updateProp[] = "(".self::PROP.",".$id.",".$file['ID'].",'text',".$file['ID'].")";
                            $elementIds[] = $id;
                            $count++;
                        }
                    }
            }
            $this->cleareResizeCache($code);
        }
    }
    public function update()
    {
//        $this->deleteBitrixPhoto();
        $this->updateArrImg();
        $this->updateItems();
//        $this->saveHash();
    }
    protected function updateArrImg()
    {
        global $DB;
        $deleteSql = [];
        $addSql = [];
        $ids = [];
        $count = 0;
        foreach ($this->items as $code => $item)
        {
            foreach ($item['FILES'] as $key => $img)
            {

                    if($count>1000 || ($code == array_key_last($this->items) && $key == array_key_last($item['FILES'])))
                    {
                        $deleteSql[] = "'".$img['NAME']."'";
                        $addSql[] = $this->prepareFileArrForSQL($img);
                        $sql = "DELETE FROM `b_file` WHERE ORIGINAL_NAME IN(".implode(',',$deleteSql).")";
                        $success = $DB->Query($sql,true);
                        if(!$success)
                            throw  new \Exception($sql,true);
                        $sql = "INSERT INTO b_file(
                                HEIGHT,
                                WIDTH,
                                FILE_SIZE,
                                CONTENT_TYPE,
                                SUBDIR,
                                FILE_NAME,
                                ORIGINAL_NAME,
                                DESCRIPTION,
                                HANDLER_ID,
                                EXTERNAL_ID,
                                TIMESTAMP_X
                              ) VALUES ".implode(",".PHP_EOL,$addSql);
                        $success = $DB->Query($sql,true);
                        if(!$success)
                            throw  new \Exception($sql);
                        $sql= "SELECT ID, SUBDIR, ORIGINAL_NAME FROM b_file WHERE ORIGINAL_NAME IN(".implode(',',$deleteSql).")";
                        $data = $DB->Query($sql,true);
                        if(!$data)
                            throw  new \Exception($sql);
                        while($row = $data->Fetch())
                        {
                            $code = implode("",explode("/",str_replace("WEB/",'',$row['SUBDIR'])));
                            foreach ($this->items[$code]['FILES'] as &$newimg)
                            {
                                if($newimg['NAME'] == $row['ORIGINAL_NAME'])
                                {
                                    $newimg['ID'] = $row['ID'];
                                    break;
                                }
                            }
                        }
                        $deleteSql = [];
                        $addSql = [];
                        $count = 0;
                    }
                    else
                    {
                        $deleteSql[] = "'".$img['NAME']."'";
                        $addSql[] = $this->prepareFileArrForSQL($img);
                        $count++;
                    }
            }

        }
    }
    protected function prepareFileArrForSQL($image)
    {
        global $DB;
        return "(".intval($image["HEIGHT"]).",
                ".intval($image["WIDTH"]).",
                ".round(floatval($image["SIZE"])).",
                '".$DB->ForSql($image["TYPE"], 255)."',
                '".$DB->ForSql(str_replace($_SERVER["DOCUMENT_ROOT"].'/upload/','',$image["SUBDIR"]), 255)."',
                '".$DB->ForSqL($image["NAME"], 255)."',
                '".$DB->ForSql($image["NAME"], 255)."',
                '".$DB->ForSqL("null")."',
                ".$DB->ForSql("null").",
                ".$DB->ForSql("null").",
                ".$DB->GetNowFunction().")";
    }

}

