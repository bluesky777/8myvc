<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromView;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\WithTitle;

class DeudoresSheet implements FromView, WithTitle
{
    private $alumnos;

    public function __construct($alumnos)
    {
        $this->alumnos  = $alumnos;
    }

    /**
     * @return Builder
     */
    public function view(): View
    {
        return view('deudores', [
            'alumnos' => $this->alumnos,
        ]);
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return 'Deudores por grupos';
    }
}