<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use File;
use App\Models\ImageModel;

class UploadDocuments {
  public static function save_document($user)
	{
		$folderName = 'user_'.$user->user_id;
		$folder = 'uploads/'.$folderName;

		if (!File::exists($folder)) {
			File::makeDirectory($folder, $mode = 0777, true, true);
		}

		$file = Request::file("file");

		$fileNameSplitted = explode(".", $file->getClientOriginalName());
		$fullFileName = $file->getClientOriginalName();

		//mientras el nombre exista iteramos y aumentamos i
		$i = 0;
		while(file_exists($folder.'/'. $fullFileName)){
			$i++;
			$fullFileName = $fileNameSplitted[0]."(".$i.")".".".$fileNameSplitted[1];              
		}
		//guardamos la imagen con otro nombre ej foto(1).jpg || foto(2).jpg etc
		$file->move($folder, $fullFileName);

		$fullPath 		= $folderName. '/'.$fullFileName;

		return $fullPath;
	}
}
