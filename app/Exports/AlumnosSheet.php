<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromView;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\WithTitle;

class AlumnosSheet implements FromView, WithTitle
{
    private $grupo;
    private $alumnos;

    public function __construct($grupo, $alumnos)
    {
        $this->grupo = $grupo;
        $this->alumnos  = $alumnos;
    }

    /**
     * @return Builder
     */
    public function view(): View
    {
        return view('simat', [
            'alumnos' => $this->alumnos,
            'grupo' => $this->grupo,
        ]);
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->grupo->abrev;
    }
}