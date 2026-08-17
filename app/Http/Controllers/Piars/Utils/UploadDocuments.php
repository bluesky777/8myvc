<?php namespace App\Http\Controllers\Piars\Utils;

use Request;
use DB;
use File;
use App\Models\ImageModel;
use App\Support\SafeUpload;

class UploadDocuments {
  public static function save_document($user)
	{
		$folderName = 'user_'.$user->user_id;
		$folder = 'uploads/'.$folderName;

		if (!File::exists($folder)) {
			File::makeDirectory($folder, $mode = 0755, true, true);
		}

		$file = Request::file("file");

		// Valida la extensión contra la lista blanca y resuelve colisiones
		// (foto.pdf, foto(1).pdf, foto(2).pdf…) igual que antes.
		$fullFileName = SafeUpload::nombreDisponible($file, $folder, SafeUpload::EXTENSIONES_DOCUMENTO);

		$file->move($folder, $fullFileName);

		$fullPath 		= $folderName. '/'.$fullFileName;

		return $fullPath;
	}
}
