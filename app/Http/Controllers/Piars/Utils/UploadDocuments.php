<?php namespace App\Http\Controllers\Piars\Utils;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

		// Ver 05 §45: con `file[]` esto era un array y el TypeError salía como 500.
		$file = SafeUpload::archivoRecibido("file");

		// Valida la extensión contra la lista blanca y resuelve colisiones
		// (foto.pdf, foto(1).pdf, foto(2).pdf…) igual que antes.
		$fullFileName = SafeUpload::nombreDisponible($file, $folder, SafeUpload::EXTENSIONES_DOCUMENTO);

		$file->move($folder, $fullFileName);

		$fullPath 		= $folderName. '/'.$fullFileName;

		return $fullPath;
	}
}
