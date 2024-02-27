<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use File;

class UploadDocuments {
  public static function save_document($user)
	{
		$folderName = 'user_'.$user->user_id;
		$folder = 'documents/'.$folderName;

		if (!File::exists($folder)) {
			File::makeDirectory($folder, $mode = 0777, true, true);
		}

		$file = Request::file("file");

		//separamos el nombre de la img y la extensión
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

		$newImg 						= new ImageModel;
		$newImg->nombre 		= $folderName.'/'.$fullFileName;
		$newImg->user_id 		= $user->user_id;
		$newImg->created_by = $user->user_id;
		$newImg->save();

		return $newImg;
	}
}
