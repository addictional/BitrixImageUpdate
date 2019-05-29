<?php
if(empty($_SERVER['DOCUMENT_ROOT']))
    $_SERVER['DOCUMENT_ROOT'] = pather();

require_once ($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
require_once __DIR__.DIRECTORY_SEPARATOR."imageReader.php";

class SecondDataReader extends  DataReader
{
    public function __construct()
    {
        $this->readFiles();
        $this->setIds();
    }
}
class SecondUpdateImages extends UpdateImages
{
    public function __construct()
    {
        $this->prepareData();
    }

    protected function prepareData()
   {
       $reader = new SecondDataReader();
       $this->items = $reader->getItems();
       foreach ($this->items as &$item)
       {
           $item['CHECKED'] = false;
       }
       $this->ids = $reader->getItemsIds();
   }
   public function deleteOldPhotos()
   {
       $this->bitrixPhoto = $this->items;
       $this->deleteBitrixPhoto();
   }
}
$obj = new SecondUpdateImages();
$obj->deleteOldPhotos();
//$images = UpdateImages::getInstanse();
//$images->update();